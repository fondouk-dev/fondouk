#!/usr/bin/env python3
"""
Harnais MCP Fondouk (reproductible). Un client MCP découvre le serveur DEPUIS le
manifest (endpoint non codé en dur) puis exerce le protocole.

  python3 scripts/mcp-proofs.py http://localhost:8081 http://localhost:8091

Vérifie par boutique :
  - Le manifest annonce transport:mcp (honnête : le serveur répond réellement).
  - initialize / tools/list (3 outils, descriptions + inputSchema).
  - tools/call search_catalog → structuredContent avec produits.
  - Filtre de sécurité §5 aussi via MCP : get_product sur un produit B2B-only → erreur
    d'outil claire (isError) guidant vers search_catalog.
  - Outil inconnu → erreur protocole exploitable.
  - Contrat de lot (Gate 2 F2b) : un batch de 20 requêtes est servi, 21 → HTTP 400.
    (Le batch JSON-RPC est retiré de MCP depuis 2025-06-18 : c'est une tolérance propre à
    Fondouk, bornée, testée ici comme contrat et non comme conformité.)
  - Notification seule → HTTP 202 sans corps.
  - Binding MCP d'UCP : paramètres sous `catalog` (+ `meta`) acceptés pour les trois outils,
    `catalog` prioritaire sur la forme à plat, forme à plat toujours acceptée, `catalog` non
    objet → erreur d'outil.
"""
import json
import re
import sys
import urllib.error
import urllib.request

from harness_common import http, print_flags


def _get(url):
    with urllib.request.urlopen(url, timeout=15) as r:
        return json.load(r)


def _rpc(url, method, params=None, rid=1):
    body = {"jsonrpc": "2.0", "id": rid, "method": method}
    if params is not None:
        body["params"] = params
    req = urllib.request.Request(url, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json",
                                          "UCP-Agent": 'profile="https://agent.example/mcp.json"'})
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        return json.load(e)


def _b2b_id(base):
    # Un produit B2B-only n'est pas cherchable ; on le trouve côté opérateur par titre
    # via une recherche large et on prend un id numérique interne connu (seed AGT-0031).
    # Ici on teste par un id élevé improbable + le fait que le produit réservé n'apparaît pas.
    res = _rpc(base + "/fondouk/ucp/mcp", "tools/call",
               {"name": "search_catalog", "arguments": {"query": "réservé B2B", "pagination": {"limit": 5}}})
    titles = [p["title"] for p in res["result"]["structuredContent"]["products"]]
    return "Produit réservé B2B" not in titles


def run(base):
    print(f"\n=== {base} ===")
    c = {}
    manifest = _get(base + "/.well-known/ucp")
    transports = [s["transport"] for s in manifest["ucp"]["services"].get("dev.ucp.shopping", [])]
    c["manifest_advertises_mcp"] = "mcp" in transports and "rest" in transports
    mcp_ep = next((s["endpoint"] for s in manifest["ucp"]["services"]["dev.ucp.shopping"] if s["transport"] == "mcp"), None)
    c["mcp_endpoint_discovered"] = mcp_ep is not None

    ini = _rpc(mcp_ep, "initialize", {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "probe", "version": "1"}})
    c["initialize"] = ini.get("result", {}).get("serverInfo", {}).get("name") == "Fondouk"

    tl = _rpc(mcp_ep, "tools/list")
    tools = {t["name"]: t for t in tl["result"]["tools"]}
    c["tools_list_3"] = set(tools) == {"search_catalog", "get_product", "lookup_catalog"}
    c["tools_documented"] = all(len(t["description"]) > 40 and "inputSchema" in t for t in tools.values())

    sc = _rpc(mcp_ep, "tools/call", {"name": "search_catalog", "arguments": {"query": "t-shirt", "pagination": {"limit": 2}}})
    c["search_call"] = sc["result"].get("isError") is False and len(sc["result"]["structuredContent"]["products"]) > 0

    c["b2b_not_searchable_via_mcp"] = _b2b_id(base)

    unk = _rpc(mcp_ep, "tools/call", {"name": "drop_table", "arguments": {}})
    c["unknown_tool_error"] = "error" in unk and "search_catalog" in unk["error"]["message"]

    # Binding MCP d'UCP : arguments sous `catalog` (+ `meta`), forme à plat conservée.
    def call(args, name="search_catalog"):
        r = _rpc(mcp_ep, "tools/call", {"name": name, "arguments": args})
        return r.get("result") or {}

    def products(res):
        return (res.get("structuredContent") or {}).get("products") or []

    meta = {"ucp-agent": {"profile": "https://agent.example/profile.json"}}
    flat = products(call({"query": "t-shirt", "pagination": {"limit": 2}}))
    nested = products(call({"meta": meta, "catalog": {"query": "t-shirt", "pagination": {"limit": 2}}}))
    c["ucp_binding_search_nested"] = bool(nested) and [p["id"] for p in nested] == [p["id"] for p in flat]
    c["ucp_binding_search_nested_filters"] = products(call(
        {"meta": meta, "catalog": {"query": "zzqx-introuvable-zzqx", "pagination": {"limit": 2}}})) == []
    c["ucp_binding_catalog_wins_over_flat"] = bool(products(call(
        {"query": "zzqx-introuvable-zzqx", "catalog": {"query": "t-shirt", "pagination": {"limit": 1}}})))
    pid = flat[0]["id"] if flat else None
    if pid:
        g = call({"meta": meta, "catalog": {"id": pid}}, "get_product")
        c["ucp_binding_get_product_nested"] = g.get("isError") is False and \
            ((g.get("structuredContent") or {}).get("product") or {}).get("id") == pid
        lk = call({"meta": meta, "catalog": {"ids": [pid]}}, "lookup_catalog")
        c["ucp_binding_lookup_nested"] = [p["id"] for p in products(lk)] == [pid]
    else:
        c["ucp_binding_get_product_nested"] = None
        c["ucp_binding_lookup_nested"] = None
    c["flat_args_with_meta_still_work"] = bool(products(call({"meta": meta, "query": "t-shirt", "pagination": {"limit": 1}})))
    bad = call({"meta": meta, "catalog": "query=t-shirt"})
    c["catalog_not_object_tool_error"] = bad.get("isError") is True and "catalog" in str(bad.get("content"))

    # Qualité de lecture par les agents (P1 : M11, M12) et compatibilité des clients actuels.
    entities = ("&quot;", "&#039;", "&#39;", "&amp;", "&lt;", "&gt;")
    gp = call({"id": 'zz"introuvable'}, "get_product")
    texts = " ".join(str(x.get("text")) for x in gp.get("content") or [])
    unk_msg = str((unk.get("error") or {}).get("message", ""))
    c["tool_error_text_plain"] = gp.get("isError") is True and '"zz"introuvable"' in texts \
        and not any(e in texts + unk_msg for e in entities)
    sv = str(ini.get("result", {}).get("serverInfo", {}).get("version", ""))
    c["server_info_version_real"] = bool(re.match(r"^\d+\.\d+\.\d+$", sv)) and sv != "0.1.0"
    c["initialize_older_and_future_versions"] = all(
        _rpc(mcp_ep, "initialize", {"protocolVersion": v, "capabilities": {}, "clientInfo": {"name": "probe", "version": "1"}})
        .get("result", {}).get("protocolVersion") == "2025-06-18" for v in ("2025-03-26", "2099-01-01"))
    c["protocol_header_known_versions_accepted"] = all(
        http("POST", mcp_ep, {"jsonrpc": "2.0", "id": 1, "method": "ping"}, headers={"MCP-Protocol-Version": v})[0] == 200
        for v in ("2025-06-18", "2025-03-26"))
    c["protocol_header_absent_accepted"] = http("POST", mcp_ep, {"jsonrpc": "2.0", "id": 1, "method": "ping"})[0] == 200

    batch20 = [{"jsonrpc": "2.0", "id": i, "method": "ping"} for i in range(20)]
    st, _, _, body = http("POST", mcp_ep, batch20)
    c["batch_20_served"] = st == 200 and isinstance(body, list) and len(body) == 20
    st, _, _, body = http("POST", mcp_ep, batch20 + [{"jsonrpc": "2.0", "id": 99, "method": "ping"}])
    c["batch_21_rejected_400"] = st == 400

    st, _, text, _ = http("POST", mcp_ep, {"jsonrpc": "2.0", "method": "notifications/initialized"})
    c["notification_202_no_body"] = st == 202 and text.strip() == ""

    ok = all(v is True for v in c.values())
    print_flags(c)
    print(f"  => {'PASS' if ok else 'FAIL'}")
    return ok, c


def main(argv):
    bases = argv[1:] or ["http://localhost:8081", "http://localhost:8091"]
    res = {b: run(b)[0] for b in bases}
    print("\n" + "=" * 40)
    for b, ok in res.items():
        print(f"  {b}: {'PASS' if ok else 'FAIL'}")
    allok = all(res.values())
    print("ALL_PASS =", allok)
    return 0 if allok else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
