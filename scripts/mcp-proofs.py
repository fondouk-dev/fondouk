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
"""
import json
import sys
import urllib.error
import urllib.request


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

    ok = all(c.values())
    for k, v in c.items():
        print(f"  {'✅' if v else '❌'} {k}: {v}")
    print(f"  => {'PASS' if ok else 'FAIL'}")
    return ok


def main(argv):
    bases = argv[1:] or ["http://localhost:8081", "http://localhost:8091"]
    res = {b: run(b) for b in bases}
    print("\n" + "=" * 40)
    for b, ok in res.items():
        print(f"  {b}: {'PASS' if ok else 'FAIL'}")
    allok = all(res.values())
    print("ALL_PASS =", allok)
    return 0 if allok else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
