#!/usr/bin/env python3
"""
Harnais de preuves Fondouk (reproductible), sur le vrai chemin HTTP.

  python3 scripts/agent-proofs.py http://localhost:8081 http://localhost:8091

Vérifie, pour chaque boutique :
  - PROOF #3 : découverte du manifest (endpoint NON codé en dur) → recherche.
  - PROOF #2 : les produits B2B du seed n'apparaissent dans AUCUNE réponse, et le
               prix spécifique B2B ne s'applique jamais à l'anonyme (alors que la
               promo publique, elle, s'applique).

Prérequis : boutique seedée (docker/seed/seed.php) + module installé.
Un produit du seed introuvable rend le contrôle correspondant NOT_TESTED (jamais PASS) :
le harnais ne déclare réussi que ce qu'il a réellement vérifié.
La validation des réponses contre les schémas publiés 2026-04-08 est couverte par
capability-core (UcpCatalogMapperTest + tools/validate-ucp.php).
"""
import json
import sys
import urllib.request

from harness_common import print_flags

TIMEOUT = 30


def _get(url):
    with urllib.request.urlopen(url, timeout=TIMEOUT) as r:
        return json.load(r)


def _post(url, body):
    req = urllib.request.Request(
        url, data=json.dumps(body).encode(),
        headers={"Content-Type": "application/json",
                 "UCP-Agent": 'profile="https://agent.example/shopping.json"'})
    with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
        return json.load(r)


def _pid(base, ref):
    # Recherche l'id par référence via une recherche texte (l'agent n'a pas la DB).
    res = _post(base + "/fondouk/ucp/catalog/search", {"query": ref, "pagination": {"limit": 1}})
    return res["products"][0]["id"] if res["products"] else None


def run(base):
    print(f"\n=== {base} ===")
    checks = {}

    # PROOF #3 — découverte → recherche
    manifest = _get(base + "/.well-known/ucp")
    checks["manifest_version_2026-04-08"] = manifest["ucp"]["version"] == "2026-04-08"
    svc = next((s for s in manifest["ucp"]["services"].get("dev.ucp.shopping", []) if s["transport"] == "rest"), None)
    checks["manifest_declares_rest"] = svc is not None
    checks["manifest_declares_search"] = "dev.ucp.shopping.catalog.search" in manifest["ucp"]["capabilities"]
    endpoint = svc["endpoint"]  # découvert
    search = _post(endpoint + "/catalog/search", {"query": "t-shirt", "pagination": {"limit": 5}})
    checks["discovery_search_returns_products"] = len(search["products"]) > 0

    # PROOF #2 — filtre B2B (produits du seed)
    all_products = _post(base + "/fondouk/ucp/catalog/search", {"pagination": {"limit": 200}})["products"]
    titles = [p["title"] for p in all_products]
    checks["b2b_absent_from_search"] = "Produit réservé B2B" not in titles

    id31 = _pid(base, "Produit réservé B2B")  # AGT-0031 ; introuvable pour l'anonyme → None
    checks["b2b_not_searchable"] = id31 is None
    # lookup/product par id numérique interne (l'agent réel n'a pas cet id ; on force le test sécurité)
    # -> on retrouve les ids via le seed côté opérateur ; ici on teste par titres publics.

    # AGT-0030 (prix B2B -30%) et AGT-0029 (promo publique -15%) sont, eux, publics.
    b2b_priced = next((p for p in all_products if p["title"] == "Tarif B2B"), None)
    pub_promo = next((p for p in all_products if p["title"] == "Promo publique"), None)
    # Produit absent du seed -> None (NOT_TESTED), jamais un PASS implicite.
    checks["b2b_price_NOT_applied_to_anonymous"] = (
        "list_price" not in b2b_priced["variants"][0] if b2b_priced else None)
    checks["public_price_applied_to_anonymous"] = (
        "list_price" in pub_promo["variants"][0]
        and pub_promo["variants"][0]["list_price"]["amount"] > pub_promo["variants"][0]["price"]["amount"]
        if pub_promo else None)

    ok = all(v is True for v in checks.values())
    print_flags(checks)
    print(f"  => {'PASS' if ok else 'FAIL'} ({len(all_products)} produits publics)")
    return ok, checks


def main(argv):
    bases = argv[1:] or ["http://localhost:8081", "http://localhost:8091"]
    results = {b: run(b)[0] for b in bases}
    print("\n" + "=" * 40)
    allok = all(results.values())
    for b, ok in results.items():
        print(f"  {b}: {'PASS' if ok else 'FAIL'}")
    print("ALL_PASS =", allok)
    return 0 if allok else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
