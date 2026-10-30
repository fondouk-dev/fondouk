#!/usr/bin/env python3
"""
scripts/protocol-checks.py — contrôles NORMATIFS UCP / MCP d'une boutique Fondouk (HTTP réel).

Chaque contrôle cite l'exigence de la spécification qu'il vérifie. Il est PASS seulement si
l'assertion a été exécutée et vérifiée ; il est NOT_TESTED (avec la raison) quand
l'environnement ne permet pas de conclure (boutique en HTTP local, mode dev de PrestaShop).
Ces contrôles décrivent la spécification, pas le comportement attendu de Fondouk : un écart
connu reste un FAIL.

    python3 scripts/protocol-checks.py http://localhost:8092 [--json out.json]

Références (relevées le 2026-10-19) :
  UCP 2026-04-08  https://ucp.dev/2026-04-08/specification/overview/
                  https://ucp.dev/2026-04-08/specification/catalog/rest/
                  https://ucp.dev/2026-04-08/specification/catalog/mcp/
  MCP 2025-06-18  https://modelcontextprotocol.io/specification/2025-06-18/basic/transports
  MCP 2025-11-25  https://modelcontextprotocol.io/specification/2025-11-25/changelog
  MCP 2026-07-28  https://modelcontextprotocol.io/specification/2026-07-28/changelog
"""
import json
import re
import sys

from harness_common import ERROR, FAIL, NOT_TESTED, PASS, check, http, status_of

UCP_OVERVIEW = "https://ucp.dev/2026-04-08/specification/overview/"
UCP_REST = "https://ucp.dev/2026-04-08/specification/catalog/rest/"
UCP_MCP = "https://ucp.dev/2026-04-08/specification/catalog/mcp/"
MCP_0618 = "https://modelcontextprotocol.io/specification/2025-06-18/basic/transports"
MCP_1125 = "https://modelcontextprotocol.io/specification/2025-11-25/changelog"
MCP_0728 = "https://modelcontextprotocol.io/specification/2026-07-28/changelog"
MCP_TOOLS = "https://modelcontextprotocol.io/specification/2025-06-18/server/tools"
FETCH_CORS = "https://fetch.spec.whatwg.org/#http-cors-protocol"
HTTP_405 = "https://www.rfc-editor.org/rfc/rfc9110#name-405-method-not-allowed"
REVERSE_DNS = re.compile(r"^[a-z0-9]+(\.[a-z0-9_]+){2,}$")
MISSING = "gid://fondouk-harness/0/product/999999999"


def run(base):
    """Retourne la liste des contrôles normatifs (dicts harness_common.check)."""
    base = base.rstrip("/")
    out = []
    N = "normatif"

    def add(cid, title, value, ref, evidence=None, reason=None):
        st = value if value in (PASS, FAIL, NOT_TESTED, ERROR) else status_of(value)
        out.append(check(cid, N, title, st, ref=ref, evidence=evidence, reason=reason))

    # ---------------- UCP — manifeste ----------------
    st, h, _, m = http("GET", base + "/.well-known/ucp")
    ucp = (m or {}).get("ucp") if isinstance(m, dict) else None
    add("ucp.manifest.http_200_json", "Manifeste /.well-known/ucp en 200 JSON", st == 200 and isinstance(ucp, dict),
        UCP_OVERVIEW, {"status": st})
    if not isinstance(ucp, dict):
        return out + [check("ucp.manifest.unreadable", N, "Manifeste illisible : contrôles UCP suivants non exécutés",
                            ERROR, ref=UCP_OVERVIEW)]
    add("ucp.manifest.required_fields", "Champs requis : version, services, payment_handlers",
        all(k in ucp for k in ("version", "services", "payment_handlers")), UCP_OVERVIEW, sorted(ucp))
    add("ucp.manifest.registries_are_objects", "services et payment_handlers sont des objets JSON (même vides)",
        isinstance(ucp.get("services"), dict) and isinstance(ucp.get("payment_handlers"), dict), UCP_OVERVIEW,
        {"services": type(ucp.get("services")).__name__, "payment_handlers": json.dumps(ucp.get("payment_handlers"))})
    add("ucp.manifest.version_date", "Version datée AAAA-MM-JJ", bool(re.match(r"^\d{4}-\d{2}-\d{2}$", str(ucp.get("version")))),
        UCP_OVERVIEW, ucp.get("version"))
    caps = ucp.get("capabilities") or {}
    add("ucp.manifest.capability_names_reverse_dns", "Noms de capacité en reverse-DNS",
        all(REVERSE_DNS.match(c) for c in caps) if isinstance(caps, dict) else False, UCP_OVERVIEW, sorted(caps))
    cc = h.get("cache-control", "")
    if "post-check" in cc:  # en-tête imposé par PrestaShop en mode dev, pas par le module
        add("ucp.manifest.cache_public_maxage60", "Cache-Control public, max-age ≥ 60, sans no-store/no-cache",
            NOT_TESTED, UCP_OVERVIEW, cc, reason="en-têtes de cache imposés par le mode dev de PrestaShop")
    else:
        mx = re.search(r"max-age=(\d+)", cc)
        add("ucp.manifest.cache_public_maxage60", "Cache-Control public, max-age ≥ 60, sans no-store/no-cache",
            "public" in cc and bool(mx) and int(mx.group(1)) >= 60 and not re.search(r"no-store|no-cache|private", cc),
            UCP_OVERVIEW, cc)
    add("ucp.manifest.validator", "Validateur ETag ou Last-Modified (recommandé)", bool(h.get("etag") or h.get("last-modified")),
        UCP_OVERVIEW, {"etag": h.get("etag"), "last-modified": h.get("last-modified")})
    services = [s for s in (ucp.get("services") or {}).get("dev.ucp.shopping", [])] if isinstance(ucp.get("services"), dict) else []
    endpoints = {s.get("transport"): s.get("endpoint") for s in services}
    if base.startswith("https://"):
        add("ucp.manifest.endpoints_https", "Endpoints de transport en https", all(str(e).startswith("https://") for e in endpoints.values()),
            UCP_OVERVIEW, endpoints)
    else:
        add("ucp.manifest.endpoints_https", "Endpoints de transport en https", NOT_TESTED, UCP_OVERVIEW, endpoints,
            reason="boutique testée en HTTP local")
    rest_ep = endpoints.get("rest") or base + "/fondouk/ucp"
    mcp_ep = endpoints.get("mcp") or base + "/fondouk/ucp/mcp"

    # ---------------- UCP — REST ----------------
    st, _, _, s = http("POST", rest_ep + "/catalog/search", {"query": "", "pagination": {"limit": 2}})
    ok = st == 200 and isinstance(s, dict) and "ucp" in s and isinstance(s.get("products"), list)
    add("ucp.rest.search", "POST /catalog/search : ucp + products", ok, UCP_REST, {"status": st})
    pid = s["products"][0]["id"] if ok and s["products"] else None
    if pid:
        st, _, _, p = http("POST", rest_ep + "/catalog/product", {"id": pid})
        add("ucp.rest.product", "POST /catalog/product : ucp + product", st == 200 and isinstance((p or {}).get("product"), dict),
            UCP_REST, {"status": st})
        st, _, _, lk = http("POST", rest_ep + "/catalog/lookup", {"ids": [pid]})
        add("ucp.rest.lookup", "POST /catalog/lookup : ucp + products", st == 200 and isinstance((lk or {}).get("products"), list),
            UCP_REST, {"status": st})
    else:
        for cid in ("ucp.rest.product", "ucp.rest.lookup"):
            add(cid, cid, NOT_TESTED, UCP_REST, reason="aucun produit public retourné par la recherche")
    st, _, _, nf = http("POST", rest_ep + "/catalog/product", {"id": MISSING})
    msgs = (nf or {}).get("messages") or []
    add("ucp.rest.not_found_business_error", "Produit inconnu : 200 + ucp.status=error + message not_found",
        st == 200 and ((nf or {}).get("ucp") or {}).get("status") == "error" and any(x.get("code") == "not_found" for x in msgs),
        UCP_OVERVIEW, {"status": st})
    st, _, _, bad = http("POST", rest_ep + "/catalog/search", raw=b"{not json")
    add("ucp.rest.invalid_json_400", "Corps JSON invalide : erreur de transport 400",
        st == 400, UCP_OVERVIEW, {"status": st, "products_returned": isinstance((bad or {}).get("products"), list)})
    st, h, _, _ = http("PUT", rest_ep + "/catalog/search", {"query": ""})
    add("ucp.rest.method_not_allowed_405", "Méthode hors contrat (PUT) : 405 + en-tête Allow (POST)",
        st == 405 and "POST" in h.get("allow", ""), HTTP_405, {"status": st, "allow": h.get("allow")})
    st, h, text, _ = http("OPTIONS", rest_ep + "/catalog/search", headers={
        "Origin": "https://agent.example", "Access-Control-Request-Method": "POST",
        "Access-Control-Request-Headers": "content-type, ucp-agent"})
    acam, acah = h.get("access-control-allow-methods", ""), h.get("access-control-allow-headers", "").lower()
    add("ucp.rest.cors_preflight", "Pré-vol CORS : 2xx, sans exécuter la requête, méthodes et en-têtes autorisés",
        200 <= st < 300 and "POST" in acam and "content-type" in acah and "ucp-agent" in acah and "products" not in text,
        FETCH_CORS, {"status": st, "methods": acam, "headers": acah})

    # ---------------- UCP — binding MCP ----------------
    meta = {"ucp-agent": {"profile": "https://agent.example/profile.json"}}
    st, _, _, r = http("POST", mcp_ep, {"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {
        "name": "search_catalog", "arguments": {"meta": meta, "catalog": {"query": "zzqx-introuvable-zzqx", "pagination": {"limit": 2}}}}})
    sc = ((r or {}).get("result") or {}).get("structuredContent") or {}
    add("ucp.mcp.binding_search_nested_catalog", "search_catalog respecte les arguments sous catalog (requête introuvable → 0 produit)",
        isinstance(sc.get("products"), list) and len(sc["products"]) == 0, UCP_MCP,
        {"produits": len(sc.get("products") or []), "total_count": (sc.get("pagination") or {}).get("total_count")})
    if pid:
        st, _, _, r = http("POST", mcp_ep, {"jsonrpc": "2.0", "id": 2, "method": "tools/call", "params": {
            "name": "get_product", "arguments": {"meta": meta, "catalog": {"id": pid}}}})
        res = (r or {}).get("result") or {}
        add("ucp.mcp.binding_get_product_nested_catalog", "get_product respecte catalog.id",
            res.get("isError") is not True and isinstance((res.get("structuredContent") or {}).get("product"), dict), UCP_MCP,
            {"isError": res.get("isError")})
    else:
        add("ucp.mcp.binding_get_product_nested_catalog", "get_product respecte catalog.id", NOT_TESTED, UCP_MCP,
            reason="aucun identifiant de produit disponible")

    # ---------------- MCP — transport Streamable HTTP ----------------
    st, _, _, ini = http("POST", mcp_ep, {"jsonrpc": "2.0", "id": 3, "method": "initialize", "params": {
        "protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "harness", "version": "1"}}},
        headers={"Accept": "application/json, text/event-stream"})
    announced = (((ini or {}).get("result") or {}).get("protocolVersion"))
    add("mcp.initialize_version", "initialize renvoie une version de protocole", bool(announced), MCP_0618, announced)
    st, _, _, _ = http("POST", mcp_ep, {"jsonrpc": "2.0", "id": 4, "method": "ping"}, headers={"MCP-Protocol-Version": "1999-01-01"})
    add("mcp.protocol_version_header_invalid_400", "En-tête MCP-Protocol-Version non supporté → 400", st == 400, MCP_0618, {"status": st})
    st, _, _, _ = http("POST", mcp_ep, {"jsonrpc": "2.0", "id": 5, "method": "ping"}, headers={"Origin": "http://evil.example"})
    add("mcp.origin_invalid_403", "Origin invalide → 403", st == 403, MCP_1125, {"status": st})
    st, _, _, _ = http("GET", mcp_ep, headers={"Accept": "text/event-stream"})
    add("mcp.get_405_or_sse", "GET sur l'endpoint → flux SSE ou 405", st == 405, MCP_0618, {"status": st})
    st, h, _, _ = http("DELETE", mcp_ep)
    add("mcp.delete_405_no_session", "DELETE sans gestion de session → 405", st == 405, MCP_0618,
        {"status": st, "allow": h.get("allow")})
    st, _, _, _ = http("POST", mcp_ep, raw=b"{not json")
    add("mcp.invalid_json_http_error", "Entrée non acceptée → statut HTTP d'erreur", st >= 400, MCP_0618, {"status": st})
    st, h, text, _ = http("OPTIONS", mcp_ep, headers={
        "Origin": "https://agent.example", "Access-Control-Request-Method": "POST",
        "Access-Control-Request-Headers": "content-type, mcp-protocol-version"})
    acam, acah = h.get("access-control-allow-methods", ""), h.get("access-control-allow-headers", "").lower()
    add("mcp.cors_preflight", "Pré-vol CORS : 2xx, méthodes et en-têtes MCP autorisés",
        200 <= st < 300 and "POST" in acam and "content-type" in acah and "mcp-protocol-version" in acah and "jsonrpc" not in text,
        FETCH_CORS, {"status": st, "methods": acam, "headers": acah})
    st, _, _, tl = http("POST", mcp_ep, {"jsonrpc": "2.0", "id": 8, "method": "tools/list"})
    tools = ((tl or {}).get("result") or {}).get("tools") or []
    add("mcp.tools.read_only_annotations", "Outils annotés en lecture seule (title + annotations.readOnlyHint)",
        bool(tools) and all(t.get("title") and (t.get("annotations") or {}).get("readOnlyHint") is True for t in tools),
        MCP_TOOLS, [{t.get("name"): t.get("annotations")} for t in tools])
    st, _, _, call = http("POST", mcp_ep, {"jsonrpc": "2.0", "id": 9, "method": "tools/call", "params": {
        "name": "search_catalog", "arguments": {"query": "", "pagination": {"limit": 1}}}})
    res = (call or {}).get("result") or {}
    texts = [c.get("text") for c in res.get("content") or [] if c.get("type") == "text"]
    try:
        mirrored = bool(texts) and json.loads(texts[0]) == res.get("structuredContent")
    except (ValueError, TypeError):
        mirrored = False
    add("mcp.text_content_mirrors_structured", "structuredContent doublé de son JSON sérialisé dans un bloc texte (SHOULD)",
        mirrored, MCP_TOOLS, {"text_debut": (texts[0] if texts else "")[:60]})
    st, _, _, um = http("POST", mcp_ep, {"jsonrpc": "2.0", "id": 6, "method": "resources/list"})
    add("mcp.unknown_method_32601", "Méthode inconnue → -32601", ((um or {}).get("error") or {}).get("code") == -32601, MCP_0618,
        {"status": st})
    st, _, _, d = http("POST", mcp_ep, {"jsonrpc": "2.0", "id": 7, "method": "server/discover", "params": {}})
    add("mcp.rev_2026_07_28_server_discover", "Révision courante 2026-07-28 : server/discover implémenté",
        isinstance((d or {}).get("result"), dict), MCP_0728, {"status": st, "error": (d or {}).get("error")})
    return out


def main(argv):
    args = argv[1:]
    out_json = None
    if "--json" in args:
        i = args.index("--json"); out_json = args[i + 1]; del args[i:i + 2]
    allc = []
    for b in args or ["http://localhost:8092"]:
        print(f"\n=== {b} ===")
        try:
            cs = run(b)
        except Exception as e:  # noqa: BLE001
            cs = [check("normatif.run", "normatif", "exécution des contrôles", ERROR, reason=repr(e))]
        for c in cs:
            print(f"  {c['status']:10} {c['id']}")
        allc += [dict(c, base=b) for c in cs]
    if out_json:
        with open(out_json, "w") as f:
            json.dump(allc, f, ensure_ascii=False, indent=2)
    return 0 if all(c["status"] == PASS for c in allc if c["status"] != NOT_TESTED) else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
