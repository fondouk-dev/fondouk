#!/usr/bin/env python3
"""
Harnais de durcissement Fondouk (reproductible, HTTP réel).

  python3 scripts/hardening-proofs.py http://localhost:8081 http://localhost:8091

Vérifie, par boutique :
  - Cache : deux appels identiques → MISS puis HIT (en-tête X-Fondouk-Cache).
  - Pagination sous adversité (modèle d'erreur 2 couches §2.3) : curseur malformé → 400
    invalid_cursor ; limit hors bornes → clampé (200) ; batch lookup > 100 → 400
    request_too_large ; curseur valide → page suivante distincte.
  - Rate limiting : crawl agressif → 429 + Retry-After ; le manifest reste réactif
    (non rate-limité) pendant le crawl.

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


def _call(base, path, body):
    req = urllib.request.Request(base + path, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            return r.status, r.headers, json.load(r)
    except urllib.error.HTTPError as e:
        return e.code, e.headers, json.load(e)


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
    c["limit_clamped_le_50"] = st == 200 and len(b["products"]) <= 50
    st, _, b = _call(base, cat + "/lookup", {"ids": [str(i) for i in range(101)]})
    c["oversized_lookup_400"] = st == 400 and b.get("code") == "request_too_large"
    _, _, p1 = _call(base, cat + "/search", {"pagination": {"limit": 2}})
    cur = p1.get("pagination", {}).get("cursor")
    _, _, p2 = _call(base, cat + "/search", {"pagination": {"limit": 2, "cursor": cur}})
    ids1 = {x["id"] for x in p1["products"]}
    ids2 = {x["id"] for x in p2["products"]}
    c["valid_cursor_next_page"] = cur is not None and ids1.isdisjoint(ids2) and len(p2["products"]) > 0

    # Rate limiting — token bucket 60/min burst 120 (en dernier : laisse l'IP limitée)
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

    ok = all(c.values())
    for k, v in c.items():
        print(f"  {'✅' if v else '❌'} {k}: {v}")
    print(f"  manifest latency during crawl (ms): {[ms for _, ms in lat]}")
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
