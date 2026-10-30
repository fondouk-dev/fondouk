#!/usr/bin/env python3
"""
scripts/run-harness.py — harnais reproductible Fondouk : une commande, un rapport JSON + Markdown.

Trois catégories, rapportées séparément :
  normatif         scripts/protocol-checks.py (exigences UCP / MCP, avec référence) ;
  contrat-fondouk  scripts/agent-proofs.py, mcp-proofs.py, hardening-proofs.py ;
  tiers            suite officielle de conformité UCP (facultative, via Docker) et essais
                   d'agents externes consignés à la main (facultatifs).
Un contrôle non exécuté est NOT_TESTED, jamais PASS (voir scripts/harness_common.py).

    python3 scripts/run-harness.py http://localhost:8092 --out-dir report/ \
        [--label "Free v1.3.0"] [--ps-version 9.2.0] [--known-gaps gaps.json] \
        [--conformance-dir path/to/ucp/conformance] [--ucp-version 2026-04-08] \
        [--external external.json] [--skip-hardening]

--known-gaps : JSON {id_de_contrôle: "note"} — un FAIL listé reste FAIL, mais il est marqué
               « écart connu » et ne fait pas échouer le code de sortie.
--external   : JSON [{"agent": "...", "date": "AAAA-MM-JJ", "scenario": "...", "status":
               "PASS|FAIL|NOT_TESTED", "evidence": "..."}] — essais réels consignés à la main.
Code de sortie : 0 si aucun FAIL ou ERROR hors écarts connus, sinon 1.
"""
import argparse
import datetime
import importlib.util
import json
import os
import platform
import re
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from harness_common import ERROR, FAIL, NOT_TESTED, PASS, check, from_flags, http  # noqa: E402

CONFORMANCE_DEPS = ["absl-py>=1.0.0", "httpx>=0.26.0", "pydantic>=2.12.0", "ucp-sdk==0.4.6",
                    "fastapi>=0.109.0", "uvicorn[standard]>=0.38.0"]
CONFORMANCE_APPLICABLE = ["ProtocolTest.test_discovery"]  # le reste de la suite porte sur le checkout
EXTERNAL_PLANNED = ["Claude (connecteur MCP distant)", "ChatGPT (navigation / connecteur)",
                    "Gemini", "Perplexity", "navigateur agentique (Comet ou équivalent)"]


def now():
    return datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat()


def load(name):
    spec = importlib.util.spec_from_file_location(name.replace("-", "_"), os.path.join(HERE, name + ".py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def git_commit():
    """Commit du harnais ; suffixe « +modifié » si les scripts exécutés diffèrent de ce commit."""
    try:
        sha = subprocess.run(["git", "-C", HERE, "rev-parse", "--short", "HEAD"], capture_output=True, text=True,
                             check=True).stdout.strip()
        dirty = subprocess.run(["git", "-C", HERE, "status", "--porcelain", "--", HERE], capture_output=True,
                               text=True, check=True).stdout.strip()
        return sha + ("+modifié" if dirty else "")
    except Exception:  # noqa: BLE001
        return None


def environment(base):
    env = {"base": base}
    _, _, _, m = http("GET", base.rstrip("/") + "/.well-known/ucp")
    env["ucp_version"] = ((m or {}).get("ucp") or {}).get("version") if isinstance(m, dict) else None
    ep = next((s.get("endpoint") for s in (((m or {}).get("ucp") or {}).get("services") or {}).get("dev.ucp.shopping", [])
               if s.get("transport") == "mcp"), base.rstrip("/") + "/fondouk/ucp/mcp") if isinstance(m, dict) else None
    _, _, _, ini = http("POST", ep, {"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {
        "protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "fondouk-harness", "version": "1"}}})
    res = (ini or {}).get("result") or {}
    env["mcp_protocol_version"] = res.get("protocolVersion")
    env["mcp_server_info"] = res.get("serverInfo")
    return env


def wait_full_bucket(base, minimum=110, max_wait=130):
    """Le limiteur de Fondouk (60/min, rafale 120) est partagé par toutes les suites d'une même IP :
    on part d'un seau plein pour que le résultat ne dépende pas de l'historique récent."""
    waited = 0
    while waited < max_wait:
        st, h, _, _ = http("POST", base.rstrip("/") + "/fondouk/ucp/catalog/search", {"pagination": {"limit": 1}})
        rem = int(h.get("x-fondouk-ratelimit-remaining") or (0 if st == 429 else 120))
        if st != 429 and rem >= minimum:
            return waited
        pause = min(max_wait - waited, max(5, minimum - rem + 2))
        time.sleep(pause)
        waited += pause
    return waited


def run_suite(fn, base, category, prefix):
    started = now()
    try:
        res = fn(base)
        cs = from_flags(res[1], category, prefix) if isinstance(res, tuple) else res
    except Exception as e:  # noqa: BLE001
        cs = [check(prefix, category, f"exécution de {prefix}", ERROR, reason=repr(e))]
    return {"suite": prefix, "category": category, "started": started, "finished": now(), "checks": cs}


def run_conformance(base, suite_dir, ucp_version):
    started = now()
    if not suite_dir:
        return {"suite": "tiers.ucp-conformance", "category": "tiers", "started": started, "finished": now(),
                "checks": [check("tiers.ucp-conformance", "tiers", "Suite officielle de conformité UCP", NOT_TESTED,
                                 reason="--conformance-dir non fourni")]}
    ref = None
    try:
        ref = subprocess.run(["git", "-C", suite_dir, "rev-parse", "--short", "HEAD"], capture_output=True, text=True).stdout.strip()
    except Exception:  # noqa: BLE001
        pass
    cfg = os.path.join(os.path.abspath(suite_dir), "..", "fondouk-conformance-input.json")
    with open(cfg, "w") as f:
        json.dump({"ucp_version": ucp_version,
                   "required_capabilities": ["dev.ucp.shopping.catalog.search", "dev.ucp.shopping.catalog.lookup"],
                   "currency": "EUR", "items": [], "out_of_stock_item": {"id": "none", "title": "none"},
                   "non_existent_item": {"id": "none", "title": "none"}}, f)
    deps = " ".join(f'"{d}"' for d in CONFORMANCE_DEPS)
    tests = " ".join(CONFORMANCE_APPLICABLE)
    cmd = ["docker", "run", "--rm", "--network", "host",
           "-v", f"{os.path.abspath(suite_dir)}:/suite:ro", "-v", f"{os.path.abspath(cfg)}:/cfg.json:ro",
           "python:3.12-slim", "sh", "-c",
           f"cp -r /suite /w && cd /w && pip install -q --root-user-action=ignore --disable-pip-version-check {deps} "
           f">/dev/null 2>&1; python protocol_test.py {tests} --server_url={base} --conformance_input=/cfg.json 2>&1"]
    try:
        out = subprocess.run(cmd, capture_output=True, text=True, timeout=900).stdout
    except Exception as e:  # noqa: BLE001
        return {"suite": "tiers.ucp-conformance", "category": "tiers", "started": started, "finished": now(),
                "checks": [check("tiers.ucp-conformance", "tiers", "Suite officielle de conformité UCP", ERROR, reason=repr(e))]}
    checks = []
    for t in CONFORMANCE_APPLICABLE:
        if re.search(rf"\[\s+OK\s+\] {re.escape(t)}", out):
            st, why = PASS, None
        elif re.search(rf"\[\s+FAILED\s+\] {re.escape(t)}", out):
            st = FAIL
            errs = [ln.strip() for ln in out.splitlines() if re.search(r"Error|error|Input should|assert", ln)]
            why = " | ".join(errs[-3:])[:400] or None
        else:
            st, why = ERROR, out[-400:]
        checks.append(check(f"tiers.ucp-conformance.{t}", "tiers", f"Suite officielle UCP : {t}", st,
                            ref=f"https://github.com/universal-commerce-protocol/conformance (commit {ref})",
                            evidence={"ucp_version_attendue": ucp_version}, reason=why))
    checks.append(check("tiers.ucp-conformance.non_applicable", "tiers",
                        "Autres tests de la suite (checkout, commandes, paiement, webhooks)", NOT_TESTED,
                        reason="hors périmètre : Fondouk n'expose ni checkout ni commande (lecture seule)"))
    return {"suite": "tiers.ucp-conformance", "category": "tiers", "started": started, "finished": now(), "checks": checks}


def external_suite(path):
    started = now()
    if path:
        with open(path) as f:
            rows = json.load(f)
        cs = [check(f"tiers.agent.{i}", "tiers", f"{r['agent']} — {r.get('scenario', '')}", r.get("status", NOT_TESTED),
                    evidence={"date": r.get("date"), "preuve": r.get("evidence")}) for i, r in enumerate(rows)]
    else:
        cs = [check(f"tiers.agent.{i}", "tiers", f"{a} — scénario de découverte et de recherche", NOT_TESTED,
                    reason="aucun essai réel consigné (--external)") for i, a in enumerate(EXTERNAL_PLANNED)]
    return {"suite": "tiers.agents-externes", "category": "tiers", "started": started, "finished": now(), "checks": cs}


def to_markdown(rep):
    L = [f"# Rapport du harnais Fondouk — {rep['run']['finished']}", "",
         f"- Commit du harnais : `{rep['run']['harness_commit']}` · Python {rep['run']['python']}",
         f"- Libellé : {rep['run'].get('label') or '—'} · PrestaShop : {rep['run'].get('ps_version') or 'non fourni'}",
         f"- Début : {rep['run']['started']} · Fin : {rep['run']['finished']}", ""]
    for env in rep["environments"]:
        L.append(f"- `{env['base']}` : UCP `{env.get('ucp_version')}`, MCP `{env.get('mcp_protocol_version')}`, "
                 f"serverInfo `{json.dumps(env.get('mcp_server_info'), ensure_ascii=False)}`")
    L += ["", "## Synthèse", "", "| Catégorie | PASS | FAIL (dont écarts connus) | NOT_TESTED | ERROR |", "| --- | --- | --- | --- | --- |"]
    for cat, s in rep["summary"].items():
        L.append(f"| {cat} | {s[PASS]} | {s[FAIL]} ({s['known_gaps']}) | {s[NOT_TESTED]} | {s[ERROR]} |")
    L += ["", f"**Verdict du harnais : {rep['verdict']}** — {rep['verdict_reason']}", ""]
    for cat in ("normatif", "contrat-fondouk", "tiers"):
        L += [f"## {cat}", "", "| Base | Contrôle | Statut | Écart connu / raison | Référence |", "| --- | --- | --- | --- | --- |"]
        for s in rep["suites"]:
            if s["category"] != cat:
                continue
            for c in s["checks"]:
                note = c.get("known_gap") or c.get("reason") or ""
                L.append(f"| {s['base']} | `{c['id']}` | {c['status']} | {note.replace('|', '/')} | {c.get('ref', '')} |")
        L.append("")
    return "\n".join(L) + "\n"


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("bases", nargs="+")
    ap.add_argument("--out-dir", required=True)
    ap.add_argument("--label")
    ap.add_argument("--ps-version")
    ap.add_argument("--known-gaps")
    ap.add_argument("--conformance-dir")
    ap.add_argument("--ucp-version", default="2026-04-08")
    ap.add_argument("--external")
    ap.add_argument("--skip-hardening", action="store_true", help="évite le test de débit (laisse l'IP limitée)")
    a = ap.parse_args()

    gaps = json.load(open(a.known_gaps)) if a.known_gaps else {}
    rep = {"schema": "fondouk-harness-report/1",
           "run": {"started": now(), "harness_commit": git_commit(), "python": platform.python_version(),
                   "label": a.label, "ps_version": a.ps_version},
           "environments": [], "suites": []}
    normatif = load("protocol-checks")
    contrats = [("agent-proofs", "contrat.agent-proofs"), ("mcp-proofs", "contrat.mcp-proofs")]
    if not a.skip_hardening:
        contrats.append(("hardening-proofs", "contrat.hardening-proofs"))  # en dernier : limite l'IP
    for base in a.bases:
        waited = wait_full_bucket(base)
        env = environment(base)
        env["attente_recharge_debit_s"] = waited
        rep["environments"].append(env)
        rep["suites"].append(dict(run_suite(normatif.run, base, "normatif", "normatif"), base=base))
        rep["suites"].append(dict(run_conformance(base, a.conformance_dir, a.ucp_version), base=base))
        for script, prefix in contrats:
            rep["suites"].append(dict(run_suite(load(script).run, base, "contrat-fondouk", prefix), base=base))
        if a.skip_hardening:
            rep["suites"].append(dict(base=base, suite="contrat.hardening-proofs", category="contrat-fondouk",
                                      started=now(), finished=now(), checks=[check(
                                          "contrat.hardening-proofs", "contrat-fondouk", "Durcissement (cache, limites, débit)",
                                          NOT_TESTED, reason="--skip-hardening")]))
    rep["suites"].append(dict(external_suite(a.external), base="—"))

    summary, unknown = {}, 0
    for s in rep["suites"]:
        for c in s["checks"]:
            if c["status"] == FAIL and c["id"] in gaps:
                c["known_gap"] = gaps[c["id"]]
            agg = summary.setdefault(s["category"], {PASS: 0, FAIL: 0, NOT_TESTED: 0, ERROR: 0, "known_gaps": 0})
            agg[c["status"]] += 1
            if c.get("known_gap"):
                agg["known_gaps"] += 1
            elif c["status"] in (FAIL, ERROR):
                unknown += 1
    rep["summary"] = summary
    rep["run"]["finished"] = now()
    rep["verdict"] = "OK" if unknown == 0 else "RÉGRESSION OU ÉCART NON RÉPERTORIÉ"
    rep["verdict_reason"] = ("aucun FAIL ni ERROR hors écarts connus" if unknown == 0
                             else f"{unknown} FAIL/ERROR hors écarts connus")
    os.makedirs(a.out_dir, exist_ok=True)
    with open(os.path.join(a.out_dir, "report.json"), "w") as f:
        json.dump(rep, f, ensure_ascii=False, indent=2)
        f.write("\n")
    with open(os.path.join(a.out_dir, "report.md"), "w") as f:
        f.write(to_markdown(rep))
    print(f"rapport : {a.out_dir}/report.json + report.md — {rep['verdict']} ({rep['verdict_reason']})")
    for cat, s in summary.items():
        print(f"  {cat:16} PASS {s[PASS]:3}  FAIL {s[FAIL]:3} (connus {s['known_gaps']})  NOT_TESTED {s[NOT_TESTED]:3}  ERROR {s[ERROR]}")
    return 0 if unknown == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
