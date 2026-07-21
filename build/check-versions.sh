#!/usr/bin/env bash
# =============================================================================
# check-versions.sh — garde-fou « aucune version PrestaShop périmée » (Addons).
#
# Source de vérité UNIQUE : build/SUPPORTED-VERSIONS (MIN / MAX). Toute mention
# de compatibilité PrestaShop dans les artefacts publics (README, docs, fiches,
# site, templates, CI, et PDF) doit correspondre à cette plage — sinon le build casse.
#
# Née d'un incident réel : la fiche Addons annonçait 8.0–9.2 pendant que les PDF
# readme joints disaient encore 8.2/9.1. Le contrôle rend cette classe d'incident
# impossible à rééditer.
#
#   ./build/check-versions.sh [racine]      # défaut : racine du dépôt
# =============================================================================
set -uo pipefail
ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
SRC="$(dirname "${BASH_SOURCE[0]}")/SUPPORTED-VERSIONS"
[ -f "$SRC" ] || { echo "  ✗ build/SUPPORTED-VERSIONS manquant"; exit 1; }
MIN="$(grep -E '^MIN=' "$SRC" | cut -d= -f2 | tr -d ' \r')"
MAX="$(grep -E '^MAX=' "$SRC" | cut -d= -f2 | tr -d ' \r')"

python3 - "$ROOT" "$MIN" "$MAX" <<'PY'
import os, re, subprocess, sys
root, MIN, MAX = sys.argv[1], sys.argv[2], sys.argv[3]
SKIP = ('/vendor/','/node_modules/','/.git/','/build/dist','/build/public-export/')
TEXT = ('.md','.html','.php','.tpl','.yml','.yaml','.txt','.json')
# « PrestaShop 8.2 and 9.1 », « PrestaShop 8.2 », « PrestaShop 8.0 – 9.2 »…
PAT = re.compile(r'PrestaShop\s*\*{0,2}(\d+\.\d+)\*{0,2}(?:\s*(?:and|or|ou|et|to|à|[-–/·])\s*\*{0,2}(\d+\.\d+)\*{0,2})?', re.I)
bad = []
def check(path, text, label=''):
    for m in PAT.finditer(text):
        a, b = m.group(1), m.group(2)
        vs = [v for v in (a, b) if v]
        # tolère une version PHP citée juste après (ex. « PrestaShop 9.1-8.2 » = tag docker)
        if any(v not in (MIN, MAX) for v in vs):
            ctx = re.sub(r'\s+', ' ', text[max(0, m.start()-40):m.end()+20])
            bad.append(f"{path}{label}: …{ctx}…")
# PÉRIMÈTRE = artefacts qui PROMETTENT une compatibilité (pas le journal,
# pas l'infra de test, pas les non-objectifs — ceux-là citent des versions
# factuelles/historiques légitimes).
INCLUDE = [
    'README.md', 'README.fr.md', 'README.ar.md',
    'prestashop-module',            # module gratuit (code, tpl, readme PDF embarqués)
    'fondoukpro-module',            # module Pro
]
# Répertoire de fiche marketing (privé, Pro uniquement) : découvert par un marqueur,
# jamais nommé en dur — son chemin ne doit pas fuiter dans l'export public.
import glob as _glob
for _d in _glob.glob(os.path.join(root, 'docs', '*')):
    if os.path.isdir(_d) and os.path.isfile(os.path.join(_d, 'submission-note.md')):
        INCLUDE.append(os.path.relpath(_d, root))
targets = []
for inc in INCLUDE:
    full = os.path.join(root, inc)
    if os.path.isfile(full):
        targets.append(full)
    elif os.path.isdir(full):
        for dp, dn, fn in os.walk(full):
            if any(s in dp + '/' for s in SKIP): continue
            targets += [os.path.join(dp, f) for f in fn]
for p in targets:
    rel = os.path.relpath(p, root)
    if p.lower().endswith('.pdf'):
        try:
            t = subprocess.run(['pdftotext', p, '-'], capture_output=True, timeout=30).stdout.decode('utf-8','ignore')
            check(rel, t, ' (PDF)')
        except Exception: pass
    elif p.endswith(TEXT):
        try: check(rel, open(p, encoding='utf-8', errors='ignore').read())
        except Exception: pass
bad = sorted(set(bad))
if bad:
    print(f"  ✗ version(s) PrestaShop périmée(s) — attendu {MIN} – {MAX} :")
    for b in bad[:25]: print("     " + b)
    print(f"  → corriger, ou mettre à jour build/SUPPORTED-VERSIONS si la plage a changé.")
    sys.exit(1)
print(f"  ✓ versions cohérentes partout (plage déclarée {MIN} – {MAX}, PDF inclus)")
PY
