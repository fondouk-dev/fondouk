#!/usr/bin/env bash
# =============================================================================
# Construit prestashop-module/vendor/ (capability-core bundlé) sur le serveur.
#
# vendor/ est gitignored → ABSENT après un `git clone`. Les contrôleurs front font
# `require vendor/autoload.php` : sans ça, les endpoints renvoient 500. À lancer :
#   - une fois après le premier clone ;
#   - après tout `git pull` qui modifie capability-core.
#
# Utilise un conteneur jetable (aucun composer/php requis sur l'hôte).
# =============================================================================
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMG="prestashop/prestashop:9.1-8.2"

echo "== composer install --no-dev dans prestashop-module/ =="
docker run --rm \
  -e COMPOSER_ALLOW_SUPERUSER=1 \
  -v "$ROOT/prestashop-module:/mod" \
  -v "$ROOT/capability-core:/capability-core" \
  -w /mod --entrypoint bash "$IMG" -lc '
    command -v composer >/dev/null 2>&1 || {
      php -r "copy(\"https://getcomposer.org/installer\", \"/tmp/ci\");"
      php /tmp/ci --install-dir=/usr/local/bin --filename=composer -q
    }
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
  '

# composer a tourné en root dans le conteneur → rendre vendor/ à l'utilisateur hôte.
docker run --rm -v "$ROOT/prestashop-module:/mod" alpine \
  chown -R "$(id -u):$(id -g)" /mod/vendor

if [ -f "$ROOT/prestashop-module/vendor/autoload.php" ]; then
  echo "== OK : prestashop-module/vendor/autoload.php présent =="
else
  echo "== ÉCHEC : vendor/autoload.php absent =="; exit 1
fi
