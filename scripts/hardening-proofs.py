#!/usr/bin/env python3
"""
Harnais de durcissement Fondouk (reproductible, HTTP réel).

  python3 scripts/hardening-proofs.py http://localhost:8081 http://localhost:8091

Vérifie, par boutique :
  - Cache : deux appels identiques → MISS puis HIT (en-tête X-Fondouk-Cache).
  - Pagination sous adversité (modèle d'erreur 2 couches §2.3) : curseur malformé → 400
    invalid_cursor ; limit hors bornes → clampé (200) ; batch lookup > 100 → 400
    request_too_large ; curseur valide → page suivante distincte.
  - Taille de corps (Gate 2 F2a) : corps > 256 KiB → 413, en REST comme en MCP.
  - Passerelle GET (NON NORMATIVE, contrat Fondouk) : GET search/product renvoie la même
    charge utile que le POST canonique.
  - Rate limiting : crawl agressif → 429 + Retry-After ; le manifest reste réactif
    (non rate-limité) pendant le crawl. Toujours en dernier : il laisse l'IP limitée.

Note : l'invalidation du cache PAR HOOK (changer un prix en BO → réponse agent mise à
jour) exige un accès BO/DB ; elle est prouvée par scripts/proof-cache-invalidation.sh.
"""
import base64
import json
import sys
import threading
import time
import urllib.error
import urllib.request
import urllib.parse

from harness_common import http, print_flags


def _json_or_empty(fp):
    """Corps JSON, ou {} si la réponse n'en est pas (ex. page d'erreur sous forte charge) :
    un thread de crawl ne doit pas s'arrêter sur une réponse illisible."""
    try:
        return json.load(fp)
    except ValueError:
        return {}


def _call(base, path, body):
    req = urllib.request.Request(base + path, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            return r.status, r.headers, _json_or_empty(r)
    except urllib.error.HTTPError as e:
        return e.code, e.headers, _json_or_empty(e)


def run(base):
    print(f"\n=== {base} ===")
    c = {}
    cat = "/fondouk/ucp/catalog"

    # Cache MISS -> HIT (query UNIQUE par run → clé fraîche garantie, 1er appel toujours MISS)
    q = {"query": "cache-probe-" + str(time.time()), "pagination": {"limit": 1}}
    _, h1, _ = _call(base, cat + "/search", q)
    _, h2, _ = _call(base, cat + "/search", q)
    c["cache_MISS_then_HIT"] = h1.get("X-Fondouk-Cache") == "MISS" and h2.get("X-Fondouk-Cache") == "HIT"

    # Pagination adverse
    st, _, b = _call(base, cat + "/search", {"pagination": {"cursor": "@@@not-base64@@@"}})
    c["malformed_cursor_400"] = st == 400 and b.get("code") == "invalid_cursor"
    st, _, b = _call(base, cat + "/search", {"pagination": {"cursor": base64.b64encode(b'{"x":1}').decode()}})
    c["wrong_shape_cursor_400"] = st == 400 and b.get("code") == "invalid_cursor"
    st, _, b = _call(base, cat + "/search", {"pagination": {"limit": 9999}})
    c["limit_clamped_le_50"] = st == 200 and len(b.get("products", [])) <= 50
    st, _, b = _call(base, cat + "/lookup", {"ids": [str(i) for i in range(101)]})
    c["oversized_lookup_400"] = st == 400 and b.get("code") == "request_too_large"
    _, _, p1 = _call(base, cat + "/search", {"pagination": {"limit": 2}})
    cur = p1.get("pagination", {}).get("cursor")
    _, _, p2 = _call(base, cat + "/search", {"pagination": {"limit": 2, "cursor": cur}})
    ids1 = {x["id"] for x in p1.get("products", [])}
    ids2 = {x["id"] for x in p2.get("products", [])}
    c["valid_cursor_next_page"] = cur is not None and ids1.isdisjoint(ids2) and len(p2.get("products", [])) > 0

    # Taille de corps : 300 KiB > plafond de 256 KiB
    big = b'{"query":"' + b"x" * (300 * 1024) + b'"}'
    st, _, _, _ = http("POST", base + cat + "/search", raw=big)
    c["body_over_256k_rest_413"] = st == 413
    st, _, _, _ = http("POST", base + "/fondouk/ucp/mcp", raw=big)
    c["body_over_256k_mcp_413"] = st == 413

    # Passerelle GET (non normative) == POST canonique, sur la même requête
    _, _, _, post_s = http("POST", base + cat + "/search", {"query": "t-shirt", "pagination": {"limit": 3}})
    _, _, _, get_s = http("GET", base + cat + "/search?" + urllib.parse.urlencode({"q": "t-shirt", "limit": 3}))
    ids = lambda r: [p["id"] for p in (r or {}).get("products", [])]  # noqa: E731
    c["get_bridge_search_equals_post"] = bool(ids(post_s)) and ids(post_s) == ids(get_s)
    pid = (ids(post_s) or [None])[0]
    if pid:
        _, _, _, post_p = http("POST", base + cat + "/product", {"id": pid})
        _, _, _, get_p = http("GET", base + cat + "/product?" + urllib.parse.urlencode({"id": pid}))
        c["get_bridge_product_equals_post"] = (post_p or {}).get("product") == (get_p or {}).get("product") is not None
    else:
        c["get_bridge_product_equals_post"] = None

    # Rate limiting — token bucket 60/min burst 120 (en dernier : laisse l'IP limitée).
    # Le contrôle de rafale suppose un seau plein : si des appels précédents (autres harnais
    # du même passage) l'ont entamé, on attend la recharge (1 jeton/s) avant de mesurer.
    _, h0, _ = _call(base, cat + "/search", {})
    remaining = int(h0.get("X-Fondouk-RateLimit-Remaining") or 120)
    if remaining < 110:
        time.sleep(min(130, 120 - remaining + 3))
    codes, ra = [], None
    for _ in range(150):
        st, h, _ = _call(base, cat + "/search", {})
        codes.append(st)
        if st == 429 and ra is None:
            ra = h.get("Retry-After")
    c["rate_limit_429"] = 429 in codes
    c["burst_allows_over_60"] = codes.count(200) > 90  # burst 120 >> fenêtre fixe 60
    c["retry_after_header"] = ra is not None

    # Réactivité : manifest pendant un crawl multi-thread
    stop = threading.Event()

    def hammer():
        while not stop.is_set():
            _call(base, cat + "/search", {})
    ts = [threading.Thread(target=hammer) for _ in range(8)]
    [t.start() for t in ts]
    lat = []
    for _ in range(5):
        t0 = time.time()
        with urllib.request.urlopen(base + "/.well-known/ucp", timeout=15) as r:
            r.read()
            lat.append((r.status, round((time.time() - t0) * 1000)))
    stop.set()
    [t.join() for t in ts]
    c["responsive_during_crawl"] = all(s == 200 and ms < 1500 for s, ms in lat)

    ok = all(v is True for v in c.values())
    print_flags(c)
    print(f"  manifest latency during crawl (ms): {[ms for _, ms in lat]}")
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
