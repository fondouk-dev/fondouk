"""
scripts/harness_common.py — vocabulaire commun des harnais Fondouk.

Statuts d'un contrôle :
  PASS        l'assertion a été EXÉCUTÉE et vérifiée ;
  FAIL        l'assertion a été exécutée et n'est pas vérifiée ;
  NOT_TESTED  le contrôle n'a pas pu être exécuté (donnée absente, environnement inadapté) ;
  ERROR       le contrôle a levé une erreur inattendue (réseau, réponse illisible).
Seul PASS compte comme réussi : un contrôle non exécuté n'est jamais déclaré PASS.

Catégories :
  normatif         exigence d'une spécification publiée (UCP, MCP), avec sa référence ;
  contrat-fondouk  comportement promis par Fondouk (sécurité, durcissement, découverte) ;
  tiers            outil ou client externe (suite officielle de conformité, agents réels).
"""
import json
import urllib.error
import urllib.request

PASS, FAIL, NOT_TESTED, ERROR = "PASS", "FAIL", "NOT_TESTED", "ERROR"
STATUSES = (PASS, FAIL, NOT_TESTED, ERROR)


def status_of(value):
    """True -> PASS, False -> FAIL, None -> NOT_TESTED."""
    if value is None:
        return NOT_TESTED
    return PASS if value else FAIL


def check(cid, category, title, status, ref=None, evidence=None, reason=None):
    assert status in STATUSES, status
    out = {"id": cid, "category": category, "title": title, "status": status}
    if ref:
        out["ref"] = ref
    if evidence is not None:
        out["evidence"] = evidence
    if reason:
        out["reason"] = reason
    return out


def from_flags(flags, category, prefix, ref=None):
    """Convertit le dict {nom: True/False/None} des harnais historiques en contrôles."""
    return [check(f"{prefix}.{name}", category, name, status_of(val), ref=ref) for name, val in flags.items()]


def http(method, url, body=None, headers=None, raw=None, timeout=20):
    """Requête HTTP brute : (statut, en-têtes en minuscules, texte, json|None). Ne lève pas sur 4xx/5xx."""
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    h = {"User-Agent": "fondouk-harness/1.0"}
    if data is not None:
        h["Content-Type"] = "application/json"
    h.update(headers or {})
    req = urllib.request.Request(url, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            status, hdrs, payload = r.status, r.headers, r.read()
    except urllib.error.HTTPError as e:
        status, hdrs, payload = e.code, e.headers, e.read()
    text = payload.decode("utf-8", "replace")
    try:
        parsed = json.loads(text) if text.strip() else None
    except ValueError:
        parsed = None
    return status, {k.lower(): v for k, v in hdrs.items()}, text, parsed


def print_flags(flags):
    """Affichage historique des harnais : ✅ / ❌ / ⚪ (non testé)."""
    for k, v in flags.items():
        mark = "⚪" if v is None else ("✅" if v else "❌")
        print(f"  {mark} {k}: {'NOT_TESTED' if v is None else v}")
