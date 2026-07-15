#!/usr/bin/env bash
#
# build/check-security.sh — Garde-fou sécurité PrestaShop Addons.
#
# Applique les règles de sécurité de la marketplace (SQL injection, XSS, fonctions
# interdites, superglobales brutes, opérations fichier) sur le CODE du module.
# Échoue (exit 1) à la moindre violation déterministe ; liste les points SQL à revoir.
#
# Pensé pour tourner dans le pipeline de packaging (comme check-i18n), sur le module STAGÉ.
#   ./build/check-security.sh <module_dir>
#
set -uo pipefail
MOD="${1:?usage: check-security.sh <module_dir>}"
FAIL=0

php_files() { find "$MOD" -name '*.php' -not -path '*/vendor/*'; }
tpl_files() { find "$MOD" -name '*.tpl' -not -path '*/vendor/*'; }
# ignore les lignes purement commentaires (docblocks, //)
code_only='^[[:space:]]*(\*|//|/\*|\*/)'

fail() { echo "  ✗ $1"; echo "$2" | sed 's/^/      /'; FAIL=1; }

# 1) Fonctions interdites (RCE / injection de code)
H=$(grep -rnE "\b(eval|create_function|unserialize|serialize|system|passthru|shell_exec|proc_open|popen)[[:space:]]*\(" $(php_files) 2>/dev/null | grep -vE "$code_only")
# exec() seul (pas ->execute()/executeS())
He=$(grep -rnE "(^|[^>_a-zA-Z])exec[[:space:]]*\(" $(php_files) 2>/dev/null | grep -vE "$code_only")
H="$H$([ -n "$H" ] && [ -n "$He" ] && echo; echo "$He" | grep -v '^$')"
[ -n "$(echo "$H" | grep -v '^$')" ] && fail "Fonction interdite (RCE) — remplacer eval/serialize/unserialize/exec/system… par JSON/alternatives sûres" "$(echo "$H" | grep -v '^$')"

# 2) Opérations fichier sur entrée utilisateur (path traversal / RCE)
H=$(grep -rnE "(file_get_contents|fopen|readfile|include|include_once|require|require_once|file_put_contents|unlink)[[:space:]]*\([^;]*\\\$_(GET|POST|REQUEST|COOKIE)" $(php_files) 2>/dev/null | grep -vE "$code_only")
[ -n "$H" ] && fail "Opération fichier sur entrée utilisateur (path traversal / RCE)" "$H"

# 3) Superglobales brutes — le scanner Addons les flague (SQL_Injection), même $_SERVER.
#    Params -> Tools::getValue() ; en-têtes/méthode -> Symfony\...\Request::createFromGlobals().
H=$(grep -rnE "\\\$_(GET|POST|REQUEST|COOKIE|SERVER)\[" $(php_files) 2>/dev/null | grep -vE "$code_only")
[ -n "$H" ] && fail "Superglobale brute \$_GET/\$_POST/\$_REQUEST/\$_COOKIE/\$_SERVER — utiliser Tools::getValue() ou l'objet Symfony Request" "$H"
# 3bis) Lecture brute du corps via file_get_contents(php://input) — flaguée par le scanner Addons
H=$(grep -rnE "file_get_contents\s*\(\s*['\"]php://input" $(php_files) 2>/dev/null | grep -vE "$code_only")
[ -n "$H" ] && fail "file_get_contents('php://input') — lire le corps via \$request->getContent() (Symfony)" "$H"

# 4) nofilter dans un template (contourne l'échappement XSS)
H=$(grep -rnE "nofilter" $(tpl_files) 2>/dev/null)
[ -n "$H" ] && fail "nofilter dans un template — l'échappement XSS ne doit jamais être contourné" "$H"

# 5) Variable Smarty en sortie sans échappement (escape / intval / floatval requis)
for f in $(tpl_files); do
  rel="${f#"$MOD"/}"
  b=$(grep -noE '\{\$[a-zA-Z0-9_.]+\}' "$f" 2>/dev/null)
  [ -n "$b" ] && fail "Variable Smarty NUE (sans échappement) — $rel" "$b"
  m=$(grep -noE '\{\$[a-zA-Z0-9_.]+\|[a-z_]+' "$f" 2>/dev/null | grep -vE '\|(escape|intval|floatval)$')
  [ -n "$m" ] && fail "Variable Smarty à modificateur non sûr (attendu escape/intval/floatval) — $rel" "$m"
done

# 6) [REVUE] Variable interpolée dans du SQL — confirmer la présence d'un assainisseur
H=$(grep -rnE "(SELECT|INSERT|UPDATE|DELETE|[[:space:]]WHERE|VALUES|[[:space:]]FROM|IN[[:space:]]*\()" $(php_files) 2>/dev/null \
    | grep -E "\.[[:space:]]*\\\$[a-zA-Z_]" \
    | grep -vE "pSQL|bqSQL|\(int\)|\(float\)|intval|floatval|_DB_PREFIX_|_MYSQL_ENGINE_|sprintf|->table\(|->scope\(|->publicWhere\(|implode|$code_only")
[ -n "$H" ] && { echo "  ⚠ [revue manuelle] variable interpolée dans du SQL — vérifier pSQL()/(int)/bqSQL() :"; echo "$H" | sed 's/^/      /'; }

if [ "$FAIL" -ne 0 ]; then
  echo "ÉCHEC: violations de sécurité (voir ci-dessus). Règles : docs/SECURITY-RULES.md"
  exit 1
fi
echo "  ✓ sécurité OK : SQL assaini, XSS échappé, aucune fonction interdite, aucune superglobale brute, aucun nofilter"
