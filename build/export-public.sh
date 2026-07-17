#!/usr/bin/env bash
# =============================================================================
# build/export-public.sh — construit une arborescence propre et autonome du projet
# (le code, le cœur, les harnais, les docs de référence, la CI). Copie par WHITELIST
# stricte : seuls les chemins listés ci-dessous sont inclus. Les documents internes
# (planning, notes de travail) et la configuration d'exploitation ne sont pas copiés.
#
# Un helper de nettoyage/contrôle optionnel (build/_export_clean.py) est exécuté s'il
# est présent : il autonomise quelques renvois internes et vérifie l'export.
#
# Le résultat est initialisé avec UN commit ; il n'est PAS poussé (revue avant push).
#   ./build/export-public.sh            # -> build/public-export/
# =============================================================================
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/build/public-export}"
VERSION="v0.1.0"

echo "== Export -> $OUT =="
rm -rf "$OUT"; mkdir -p "$OUT/docs" "$OUT/build" "$OUT/docker"

# ---------- Whitelist : copie ----------
cp -r "$ROOT/capability-core" "$ROOT/prestashop-module" "$ROOT/scripts" "$ROOT/.github" "$OUT/"
cp "$ROOT/README.md" "$ROOT/README.fr.md" "$ROOT/README.ar.md" "$ROOT/SECURITY.md" "$ROOT/LICENSE" "$ROOT/ARCHITECTURE.md" "$ROOT/.gitignore" "$OUT/"
cp "$ROOT/build/package.sh" "$ROOT/build/_transform.py" "$ROOT/build/export-public.sh" "$ROOT/build/check-security.sh" "$OUT/build/"
cp "$ROOT/docs/TECHNICAL-SPEC.md" "$ROOT/docs/GATE2-REPORT.md" "$ROOT/docs/demo-deployment.md" "$OUT/docs/"
cp -r "$ROOT/docs/blueprint" "$OUT/docs/"
# Environnement dev + démo (les harnais et le guide de démo en ont besoin)
cp "$ROOT/docker-compose.yml" "$ROOT/docker-compose.demo.yml" "$ROOT/.env.example" "$OUT/"
cp -r "$ROOT/docker/demo" "$ROOT/docker/seed" "$ROOT/docker/README.md" "$OUT/docker/"

# ---------- Purge artefacts (vendor bundlé, caches) ----------
find "$OUT" -type d \( -name vendor -o -name '.phpunit.cache' \) -prune -exec rm -rf {} + 2>/dev/null || true
find "$OUT" \( -name '.php-cs-fixer.cache' -o -name '.DS_Store' -o -name 'config_*.xml' \) -delete 2>/dev/null || true

# ---------- Purge scripts d'EXPLOITATION (jamais publics) ----------
# scripts/demo-addons/* pilote le serveur de démo Hetzner (IP + chemin de clé SSH) :
# opérationnel, hors périmètre public. Le garde-fou anti-fuite le refuserait sinon.
rm -rf "$OUT/scripts/demo-addons"

# ---------- Nettoyage + contrôle (helper privé, présent uniquement dans le repo source) ----------
if [ -f "$ROOT/build/_export_clean.py" ]; then
  python3 "$ROOT/build/_export_clean.py" "$OUT" || { echo "ÉCHEC: contrôle de l'export non satisfait."; exit 1; }
else
  echo "  (helper de nettoyage absent — étape sautée)"
fi

# ---------- Init git + UN commit (pas de push : revue manuelle avant) ----------
git -C "$OUT" init -q -b main
git -C "$OUT" add -A
git -C "$OUT" -c commit.gpgsign=false commit -q -m "Fondouk $VERSION — initial public release"

echo "== Export prêt : $OUT =="
echo "   $(git -C "$OUT" rev-list --count HEAD) commit · $(git -C "$OUT" ls-files | wc -l | tr -d ' ') fichiers"
echo
echo "Revue manuelle avant push :"
echo "   git -C \"$OUT\" ls-files        # lister et valider l'arborescence"
echo "   git -C \"$OUT\" log --stat       # vérifier le commit unique"
echo "Puis, une fois validé :"
echo "   git -C \"$OUT\" remote add origin git@github.com:<org>/fondouk.git && git -C \"$OUT\" push -u origin main"
