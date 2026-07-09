#!/usr/bin/env bash
# PROOF durcissement #2 — invalidation du cache PAR HOOK.
# Change un prix « en back-office » (via ObjectModel, qui déclenche actionProductSave)
# et prouve que la réponse agent reflète le changement, avec cache MISS→HIT→(bump)→MISS.
#
# Usage : scripts/proof-cache-invalidation.sh [service] [base]
#   défauts : ps8  http://localhost:8081
set -euo pipefail
SVC=${1:-ps8}
BASE=${2:-http://localhost:8081}

ID=$(docker compose exec -T "$SVC" php -r 'define("_PS_ROOT_DIR_","/var/www/html");require "/var/www/html/config/config.inc.php";echo (int)Db::getInstance()->getValue("SELECT id_product FROM "._DB_PREFIX_."product WHERE reference=\"AGT-0001\"");' 2>/dev/null)
echo "AGT-0001 id_product=$ID sur $SVC"

# départ déterministe : vide le cache réponses + le bucket de rate-limit
docker compose exec -T -u root "$SVC" bash -lc 'rm -rf var/cache/fondouk' >/dev/null 2>&1 || true
docker compose exec -T "$SVC" php -r 'define("_PS_ROOT_DIR_","/var/www/html");require "/var/www/html/config/config.inc.php";Db::getInstance()->execute("TRUNCATE "._DB_PREFIX_."fondouk_rate_limit");' >/dev/null 2>&1 || true

hdr() { curl -s -D - -o /tmp/_ci_body.json -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID\"}" "$BASE/fondouk/ucp/catalog/product"; }
price() { python3 -c "import json;print(json.load(open('/tmp/_ci_body.json'))['product']['variants'][0]['price']['amount'])"; }

C1=$(hdr | grep -i '^X-Fondouk-Cache' | tr -d '\r' | awk '{print $2}'); P1=$(price)
C2=$(hdr | grep -i '^X-Fondouk-Cache' | tr -d '\r' | awk '{print $2}')
echo "appel1 cache=$C1 prix=$P1 | appel2 cache=$C2"

echo "-> changement de prix (BO simulé) : hook doit bumper la version"
docker compose exec -T "$SVC" php -r 'define("_PS_ROOT_DIR_","/var/www/html");require "/var/www/html/config/config.inc.php";$b=(int)Configuration::get("FONDOUK_CATALOG_VERSION");$p=new Product((int)'"$ID"');$p->price=77.77;$p->update();$a=(int)Configuration::get("FONDOUK_CATALOG_VERSION");echo "version $b->$a\n";' 2>/dev/null | grep version

C3=$(hdr | grep -i '^X-Fondouk-Cache' | tr -d '\r' | awk '{print $2}'); P3=$(price)
echo "appel3 cache=$C3 prix=$P1 -> $P3"

# restaure le prix d'origine (10.90) pour ne pas polluer le seed
docker compose exec -T "$SVC" php -r 'define("_PS_ROOT_DIR_","/var/www/html");require "/var/www/html/config/config.inc.php";$p=new Product((int)'"$ID"');$p->price=10.90;$p->update();' 2>/dev/null || true

if [ "$C1" = "MISS" ] && [ "$C2" = "HIT" ] && [ "$C3" = "MISS" ] && [ "$P1" != "$P3" ]; then
  echo "PROOF durcissement #2 : PASS"
else
  echo "PROOF durcissement #2 : CHECK"; exit 1
fi
