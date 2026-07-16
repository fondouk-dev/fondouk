#!/usr/bin/env bash
#
# build/package.sh — produit le zip de soumission Addons, paramétré par MODULE_NAME.
#
#   MODULE_NAME=souk ./build/package.sh              # build + vérification
#   ./build/package.sh fondouktest                 # nom en argument
#   MODULE_NAME=souk NO_VERIFY=1 ./build/package.sh   # sans l'install-depuis-zip
#
# Le zip contient UNIQUEMENT le module (dossier racine {MODULE_NAME}/ = contenu de
# prestashop-module/ + capability-core bundlé dans vendor/). Jamais le monorepo.
#
set -euo pipefail

MODULE_NAME="${1:-${MODULE_NAME:-fondouk}}"
AUTHOR="Synapsea"
YEAR="2026"
IMG="prestashop/prestashop:8.2-8.1"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/build/dist"

LOWER="$(printf '%s' "$MODULE_NAME" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]')"
PASCAL="$(python3 -c "import re,sys;print(''.join(w.capitalize() for w in re.split(r'[^a-zA-Z0-9]+', sys.argv[1]) if w))" "$MODULE_NAME")"
UPPER="$(printf '%s' "$LOWER" | tr '[:lower:]' '[:upper:]')"
[ -n "$LOWER" ] || { echo "MODULE_NAME invalide"; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
MOD="$STAGE/$LOWER"

echo "=== Packaging: MODULE_NAME=$MODULE_NAME (lower=$LOWER pascal=$PASCAL upper=$UPPER) ==="

# 1. STAGE : module (sans vendor/lock/hidden) + cœur (pour résolution Composer path)
mkdir -p "$MOD"
tar -C "$ROOT/prestashop-module" \
    --exclude='vendor' --exclude='composer.lock' --exclude='.git*' --exclude='.DS_Store' --exclude='config_*.xml' --exclude='.php-cs-fixer*' \
    -cf - . | tar -C "$MOD" -xf -
cp -r "$ROOT/capability-core" "$STAGE/capability-core"
# le cœur bundlé = src + schema + composer.json + LICENSE uniquement (pas de dev/tests/outils)
rm -rf "$STAGE/capability-core/vendor" "$STAGE/capability-core/tests" \
       "$STAGE/capability-core/tools" "$STAGE/capability-core/phpunit.xml.dist" \
       "$STAGE/capability-core/.phpunit.cache" "$STAGE/capability-core/composer.lock"

# 2. Renommage (technique/classe/namespace/URL/config/tables), cœur préservé
[ "fondouk.php" = "$LOWER.php" ] || mv "$MOD/fondouk.php" "$MOD/$LOWER.php"
python3 "$ROOT/build/_transform.py" rename "$MOD" "$LOWER" "$PASCAL" "$UPPER"

# 3. Vendoring : copie RÉELLE du cœur (symlink:false), sans dépendance dev
docker run --rm -e COMPOSER_ALLOW_SUPERUSER=1 -v "$STAGE":/stage -w "/stage/$LOWER" \
  --entrypoint bash "$IMG" -lc '
    php -r "copy(\"https://getcomposer.org/installer\",\"/tmp/ci\");" && php /tmp/ci --install-dir=/usr/local/bin --filename=composer -q
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress 2>&1 | tail -4
  '
# composer a tourné en root dans le conteneur → rendre le staging à l'utilisateur hôte
docker run --rm -v "$STAGE":/stage alpine chown -R "$(id -u):$(id -g)" /stage

# 4. Vérifs vendoring
VC="$MOD/vendor/fondouk/capability-core"
[ -d "$VC/src" ] || { echo "ÉCHEC: cœur non bundlé dans vendor/"; exit 1; }
[ -L "$VC" ] && { echo "ÉCHEC: vendor/ contient un SYMLINK vers le cœur (piège monorepo)"; exit 1; }
[ ! -e "$MOD/vendor/opis" ] || { echo "ÉCHEC: dépendance dev opis/json-schema présente dans vendor/"; exit 1; }
echo "  vendoring OK : cœur copié réel, aucune dépendance dev"

# 5. Le cœur autonome n'est plus utile au packaging
rm -rf "$STAGE/capability-core"

# 6. Finalisation : composer.json minimal, index.php partout, en-têtes licence MIT
python3 "$ROOT/build/_transform.py" finalize "$MOD" "$PASCAL" "$AUTHOR" "$YEAR"

# 7. logo.png 32x32 présent ; nettoyage (lock recréé par composer, fichiers cachés)
[ -f "$MOD/logo.png" ] || { echo "ÉCHEC: logo.png manquant"; exit 1; }
rm -f "$MOD/composer.lock"
find "$MOD" -name '.DS_Store' -delete
find "$MOD" -name '.gitkeep' -delete

# 7bis. Vérifications de conformité validateur PrestaShop (ce que le validateur a
#       attrapé et pas nous). Échec du build = zip non produit. Portée : le module
#       (hors vendor tiers) ; les stubs index.php sont exclus (redirections, pas du code).
echo "--- vérifications validateur (garde _PS_VERSION_ · en-tête licence · .htaccess · description EN · stubs) ---"
VFAIL=0
# Gabarit de stub de référence (source de vérité = _transform.py) : les stubs sont
# contrôlés par DIFF exact contre ce template, pas par heuristique.
STUB_REF="$STAGE/stub.ref"
python3 "$ROOT/build/_transform.py" stub > "$STUB_REF"
while IFS= read -r f; do
  rel="${f#"$MOD"/}"
  if [ "$(basename "$f")" = "index.php" ]; then
    # Stub de redirection : DOIT être byte-identique au gabarit (docbloc licence COLLÉ à
    # <?php, code COLLÉ au docbloc — arbitré par prestashop/php-dev-tools, voir
    # .php-cs-fixer.dist.php). Réécrit par _transform.py finalize à l'identique.
    cmp -s "$STUB_REF" "$f" || { echo "  ✗ stub hors gabarit (diff avec le template) : $rel"; VFAIL=1; }
  else
    # Code : garde _PS_VERSION_, en-tête licence, docbloc COLLÉ à <?php (sniffs « missing
    # file doc comment » + « no blank lines before the file comment »).
    grep -q "!defined('_PS_VERSION_')" "$f" || { echo "  ✗ garde _PS_VERSION_ absente : $rel"; VFAIL=1; }
    # La garde doit précéder tout CODE EXÉCUTABLE (require/include/class) — un docblock
    # de fichier + declare(strict_types=1) avant elle est conforme (aucune exécution).
    gl=$(grep -n "!defined('_PS_VERSION_')" "$f" | head -1 | cut -d: -f1 || true)
    cl=$(grep -nE "^[[:space:]]*(require|include|class )" "$f" | head -1 | cut -d: -f1 || true)
    if [ -n "$gl" ] && [ -n "$cl" ] && [ "$gl" -gt "$cl" ]; then
        echo "  ✗ garde _PS_VERSION_ APRÈS du code exécutable (require/include/class ligne $cl) : $rel"; VFAIL=1
    fi
    head -n 15 "$f" | grep -q "@license" || { echo "  ✗ en-tête licence absent : $rel"; VFAIL=1; }
    [[ "$(sed -n '2p' "$f")" == /\** ]] || { echo "  ✗ ligne vide avant le file comment : $rel"; VFAIL=1; }
  fi
done < <(find "$MOD" -name '*.php' -not -path '*/vendor/*')
[ -f "$MOD/.htaccess" ] || { echo "  ✗ .htaccess racine absent (deny listing, §6)"; VFAIL=1; }
grep -q 'Makes the store visible' "$MOD/$LOWER.php" || { echo "  ✗ description non anglaise dans $LOWER.php"; VFAIL=1; }
if find "$MOD" -name 'config_*.xml' | grep -q .; then echo "  ✗ config_*.xml généré présent (à exclure du zip)"; VFAIL=1; fi
[ "$VFAIL" -eq 0 ] || { echo "ÉCHEC: conformité validateur non satisfaite (voir ci-dessus)"; exit 1; }
echo "  ✓ guard + en-tête licence sur chaque .php du module, .htaccess présent, description EN"

# 7ter. Garde-fou sécurité (SQL injection · XSS · fonctions interdites · superglobales · fichiers)
echo "--- sécurité (règles Addons : SQL/XSS/RCE/superglobales) ---"
bash "$ROOT/build/check-security.sh" "$MOD" || { echo "ÉCHEC: règles de sécurité non respectées (voir docs/SECURITY-RULES.md)"; exit 1; }

# 8. Lint de tous les .php du zip (conteneur)
docker run --rm -v "$STAGE":/stage --entrypoint bash "$IMG" -lc '
  err=0; for f in $(find /stage/'"$LOWER"' -name "*.php"); do php -l "$f" >/dev/null || { echo "LINT FAIL $f"; err=1; }; done
  [ $err -eq 0 ] && echo "  lint OK ('"$(find "$MOD" -name '*.php' | wc -l)"' fichiers)" || exit 1
'

# 9. Zip de soumission (racine = {MODULE_NAME}/)
mkdir -p "$DIST"
ZIP="$DIST/$MODULE_NAME.zip"
rm -f "$ZIP"
python3 -c "
import zipfile, os, sys
stage, lower, zippath = sys.argv[1:4]
with zipfile.ZipFile(zippath, 'w', zipfile.ZIP_DEFLATED) as z:
    for dp, _, fns in os.walk(os.path.join(stage, lower)):
        for fn in fns:
            full = os.path.join(dp, fn)
            z.write(full, os.path.relpath(full, stage))
" "$STAGE" "$LOWER" "$ZIP"
echo "=== ZIP produit : $ZIP ($(du -h "$ZIP" | cut -f1)) ==="
echo "  contenu racine : $(python3 -c "import zipfile,sys;print(zipfile.ZipFile(sys.argv[1]).namelist()[0])" "$ZIP")"

# 10. Vérification : install DEPUIS LE ZIP sur PS8 + PS9, smoke, désinstallation propre
[ "${NO_VERIFY:-0}" = "1" ] && { echo "(vérification sautée)"; exit 0; }
if [ "$LOWER" = "fondouk" ]; then
  echo "AVERTISSEMENT: MODULE_NAME=fondouk entre en conflit avec le bind-mount de dev ;"
  echo "la vérification install-depuis-zip est sautée. Utilise un nom distinct pour la prouver."
  exit 0
fi

VDIR="$STAGE/verify"; mkdir -p "$VDIR"
python3 -c "import zipfile,sys;zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])" "$ZIP" "$VDIR"
for S in ps8 ps9; do
  echo "--- vérification sur $S ---"
  DC="docker compose -f $ROOT/docker-compose.yml"
  $DC exec -T "$S" php bin/console prestashop:module uninstall "$LOWER" >/dev/null 2>&1 || true
  $DC exec -T "$S" php bin/console prestashop:module uninstall fondouk >/dev/null 2>&1 || true
  $DC exec -T -u root "$S" rm -rf "modules/$LOWER" >/dev/null 2>&1 || true
  $DC cp "$VDIR/$LOWER" "$S:/var/www/html/modules/$LOWER" >/dev/null 2>&1
  $DC exec -T -u root "$S" bash -lc "chown -R www-data:www-data modules/$LOWER" >/dev/null 2>&1
  $DC exec -T "$S" php bin/console prestashop:module install "$LOWER" >/dev/null 2>&1 || true
  INST=$($DC exec -T "$S" php -r "define('_PS_ROOT_DIR_','/var/www/html');require '/var/www/html/config/config.inc.php';echo (int)Module::isInstalled('$LOWER');" 2>/dev/null | tr -dc 0-9)
  [ "$INST" = "1" ] || { echo "  ÉCHEC: install $LOWER sur $S"; exit 1; }
  echo "  install depuis le zip : OK"
  # Tout process root (console/php) réécrit le cache en root → rendre var/ inscriptible
  # JUSTE avant le smoke apache (www-data), sinon rename FrontContainer échoue.
  $DC exec -T -u root "$S" bash -lc "rm -rf var/cache/*; chmod -R 777 var" >/dev/null 2>&1
  P=$([ "$S" = ps8 ] && echo 8081 || echo 8091)
  EP=""
  for i in $(seq 1 15); do
    EP=$(curl -s "http://localhost:$P/.well-known/ucp" | python3 -c "import sys,json
try: print(json.load(sys.stdin)['ucp']['services']['dev.ucp.shopping'][0]['endpoint'])
except Exception: pass" 2>/dev/null)
    [ -n "$EP" ] && break; sleep 1
  done
  N=$(curl -s -X POST -H 'Content-Type: application/json' -d '{"pagination":{"limit":3}}' "http://localhost:$P/$LOWER/ucp/catalog/search" | python3 -c "import sys,json
try: print(len(json.load(sys.stdin)['products']))
except Exception: print('ERR')" 2>/dev/null)
  echo "  manifest endpoint=$EP | search -> $N produits"
  { [ -n "$EP" ] && [[ "$N" =~ ^[0-9]+$ ]] && [ "$N" -ge 1 ]; } || { echo "  ÉCHEC: smoke sur $S"; exit 1; }
  # désinstallation propre (teardown best-effort : n'échoue pas le build sur un hoquet)
  $DC exec -T "$S" php bin/console prestashop:module uninstall "$LOWER" >/dev/null 2>&1 || true
  CLEAN=$($DC exec -T "$S" php -r "define('_PS_ROOT_DIR_','/var/www/html');require '/var/www/html/config/config.inc.php';\$t=(int)count(Db::getInstance()->executeS('SHOW TABLES LIKE \"'._DB_PREFIX_.'${LOWER}_access_log\"'));\$c=Configuration::get('${UPPER}_IP_SALT')?1:0;echo (\$t===0 && \$c===0)?'propre':(\"résiduel table=\$t config=\$c\");" 2>/dev/null || true)
  echo "  désinstallation : ${CLEAN:-?} (table télémétrie + config)"
  $DC exec -T -u root "$S" rm -rf "modules/$LOWER" >/dev/null 2>&1 || true
  $DC exec -T "$S" php bin/console prestashop:module install fondouk >/dev/null 2>&1 || true
done
echo "=== OK : zip vérifié (install/smoke/désinstall) sur PS8 et PS9 ==="
