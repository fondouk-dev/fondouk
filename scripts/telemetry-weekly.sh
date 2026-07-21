#!/usr/bin/env bash
# =============================================================================
# telemetry-weekly.sh — rapport hebdomadaire de la télémétrie agent de la DÉMO.
#
# Lit `ps_fondouk_access_log` (démo publique) et écrit un rapport markdown
# docs/telemetry-weekly/YYYY-WW.md. DONNÉES BRUTES, ZÉRO INTERPRÉTATION :
# l'interprétation se fait à la rédaction des posts.
#
#   ./telemetry-weekly.sh              # semaine ISO courante (usage cron dimanche 23h59)
#   ./telemetry-weekly.sh 2026-29      # une semaine précise
#   ./telemetry-weekly.sh --since 2026-07-13   # période libre (1er rapport / rattrapage)
#
# Sortie : $TELEMETRY_OUT (défaut : <repo>/docs/telemetry-weekly)
# Historique des UA connus : $TELEMETRY_OUT/.known-agents.txt (les NOUVEAUX
# user-agents sont le signal le plus précieux — chaque agent qui apparaît est un événement).
#
# Prérequis : tourne sur la VM (accès au conteneur MariaDB de la démo).
# Cron :  59 23 * * 0  /home/fondouk/telemetry/telemetry-weekly.sh >> /home/fondouk/telemetry/cron.log 2>&1
# =============================================================================
set -uo pipefail

DB_CONTAINER="${DB_CONTAINER:-fondouk-db}"
DB_NAME="${DB_NAME:-prestashop}"
ENV_FILE="${ENV_FILE:-$HOME/fondouk-site/.env}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${TELEMETRY_OUT:-$(cd "$HERE/.." && pwd)/docs/telemetry-weekly}"
KNOWN="$OUT/.known-agents.txt"

# --- mot de passe root MariaDB (jamais en dur) -------------------------------
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-}"
if [ -z "$DB_ROOT_PASSWORD" ] && [ -f "$ENV_FILE" ]; then
  DB_ROOT_PASSWORD="$(grep -E '^DB_ROOT_PASSWORD=' "$ENV_FILE" | head -1 | cut -d= -f2-)"
fi
[ -n "$DB_ROOT_PASSWORD" ] || { echo "ERREUR: DB_ROOT_PASSWORD introuvable (ni env, ni $ENV_FILE)"; exit 1; }

q() { docker exec -i "$DB_CONTAINER" mariadb -uroot -p"$DB_ROOT_PASSWORD" "$DB_NAME" -N -B -e "$1" 2>/dev/null; }

# --- période -----------------------------------------------------------------
MODE="week"; LABEL=""
if [ "${1:-}" = "--since" ]; then
  MODE="since"; SINCE="${2:?--since AAAA-MM-JJ}"
  START="$SINCE 00:00:00"; END="$(date '+%Y-%m-%d %H:%M:%S')"
  LABEL="$(date -d "$SINCE" +%G-%V)"; TITLE="depuis le $(date -d "$SINCE" '+%d/%m/%Y')"
else
  WK="${1:-$(date +%G-%V)}"; LABEL="$WK"
  Y="${WK%-*}"; W="${WK#*-}"
  START="$(date -d "$Y-01-04 +$(( (10#$W - 1) )) weeks -$(( $(date -d "$Y-01-04" +%u) - 1 )) days" +%Y-%m-%d) 00:00:00"
  END="$(date -d "${START%% *} +6 days" +%Y-%m-%d) 23:59:59"
  TITLE="du $(date -d "${START%% *}" '+%d/%m/%Y') au $(date -d "${END%% *}" '+%d/%m/%Y')"
fi
PREV_START="$(date -d "${START%% *} -7 days" +%Y-%m-%d) 00:00:00"
PREV_END="$(date -d "${START%% *} -1 day" +%Y-%m-%d) 23:59:59"

mkdir -p "$OUT"; touch "$KNOWN"
REPORT="$OUT/$LABEL.md"
W="date_add BETWEEN '$START' AND '$END'"

# --- classification des familles (miroir simplifié de AgentFamily) ------------
FAM="CASE
  WHEN user_agent LIKE '%GPTBot%' OR user_agent LIKE '%ChatGPT%' OR user_agent LIKE '%OAI-SearchBot%' THEN 'OpenAI'
  WHEN user_agent LIKE '%ClaudeBot%' OR user_agent LIKE '%Claude-Web%' OR user_agent LIKE '%anthropic%' THEN 'Anthropic'
  WHEN user_agent LIKE '%PerplexityBot%' OR user_agent LIKE '%Perplexity-User%' THEN 'Perplexity'
  WHEN user_agent LIKE '%Applebot%' THEN 'Apple'
  WHEN user_agent LIKE '%Googlebot%' OR user_agent LIKE '%Google-Extended%' THEN 'Google'
  WHEN user_agent LIKE '%meta-external%' OR user_agent LIKE '%facebookexternalhit%' OR user_agent LIKE '%Meta-AI%' THEN 'Meta'
  WHEN user_agent LIKE '%Bytespider%' THEN 'ByteDance'
  WHEN user_agent LIKE '%Amazonbot%' THEN 'Amazon'
  WHEN user_agent LIKE '%CCBot%' THEN 'CommonCrawl'
  WHEN user_agent LIKE '%Comet%' THEN 'Comet'
  WHEN user_agent IS NULL OR user_agent = '' THEN '(sans user-agent)'
  ELSE 'autre / navigateur'
END"

TOTAL="$(q "SELECT COUNT(*) FROM ps_fondouk_access_log WHERE $W;")"; TOTAL="${TOTAL:-0}"
PREV="$(q "SELECT COUNT(*) FROM ps_fondouk_access_log WHERE date_add BETWEEN '$PREV_START' AND '$PREV_END';")"; PREV="${PREV:-0}"
DELTA=$(( TOTAL - PREV ))
if [ "$PREV" -gt 0 ]; then PCT="$(awk -v d=$DELTA -v p=$PREV 'BEGIN{printf "%+.1f%%", (d/p)*100}')"; else PCT="n/a"; fi
SIGN=$([ "$DELTA" -ge 0 ] && echo "+" || echo "")

# --- p50 / p95 (calcul en awk : portable, pas de fonction fenêtre requise) ----
LAT="$(q "SELECT response_ms FROM ps_fondouk_access_log WHERE $W AND response_ms IS NOT NULL ORDER BY response_ms;")"
read -r P50 P95 PMAX <<<"$(printf '%s\n' "$LAT" | awk 'NF{a[n++]=$1} END{if(n==0){print "n/a n/a n/a"; exit} printf "%d %d %d", a[int(n*0.50)], a[int(n*0.95)>=n?n-1:int(n*0.95)], a[n-1]}')"

R429="$(q "SELECT COUNT(*) FROM ps_fondouk_access_log WHERE $W AND http_status=429;")"; R429="${R429:-0}"
if [ "$TOTAL" -gt 0 ]; then RATE429="$(awk -v a=$R429 -v t=$TOTAL 'BEGIN{printf "%.2f%%", (a/t)*100}')"; else RATE429="n/a"; fi

# --- nouveaux user-agents (jamais vus) ---------------------------------------
CUR_UA="$(q "SELECT DISTINCT user_agent FROM ps_fondouk_access_log WHERE $W AND user_agent IS NOT NULL AND user_agent <> '';")"
NEW_UA="$(comm -13 <(sort -u "$KNOWN") <(printf '%s\n' "$CUR_UA" | sort -u) 2>/dev/null)"

# --- écriture du rapport ------------------------------------------------------
{
echo "# Télémétrie agent — démo publique — semaine $LABEL"
echo
echo "> Période : **$TITLE** · source : \`ps_fondouk_access_log\` de \`demo.fondouk.dev\`."
echo "> Données brutes, sans interprétation (matière pour les posts)."
echo
echo "## Volume"
echo
echo "| | Accès |"
echo "|---|---|"
echo "| Cette période | **$TOTAL** |"
echo "| Période précédente | $PREV |"
echo "| Delta | ${SIGN}${DELTA} ($PCT) |"
echo
echo "## Familles d'agents"
echo
echo "| Famille | Accès | % |"
echo "|---|---|---|"
q "SELECT $FAM AS fam, COUNT(*) c, ROUND(100*COUNT(*)/NULLIF($TOTAL,0),1)
   FROM ps_fondouk_access_log WHERE $W GROUP BY fam ORDER BY c DESC;" \
 | awk -F'\t' '{printf "| %s | %s | %s%% |\n",$1,$2,$3}'
echo
echo "### 🆕 User-agents jamais vus auparavant"
echo
if [ -n "$NEW_UA" ]; then
  printf '%s\n' "$NEW_UA" | sed 's/^/- `/;s/$/`/'
else
  echo "_Aucun nouvel user-agent cette période._"
fi
echo
echo "## Source — structuré vs vitrine"
echo
echo "| Source | Accès | % |"
echo "|---|---|---|"
q "SELECT COALESCE(source,'(nc)') s, COUNT(*) c, ROUND(100*COUNT(*)/NULLIF($TOTAL,0),1)
   FROM ps_fondouk_access_log WHERE $W GROUP BY s ORDER BY c DESC;" \
 | awk -F'\t' '{printf "| %s | %s | %s%% |\n",$1,$2,$3}'
echo
STRUCT="$(q "SELECT COUNT(*) FROM ps_fondouk_access_log WHERE $W AND source IN ('ucp','mcp','llms');")"; STRUCT="${STRUCT:-0}"
FRONT="$(q "SELECT COUNT(*) FROM ps_fondouk_access_log WHERE $W AND source='storefront';")"; FRONT="${FRONT:-0}"
echo "### Série de référence — adoption UCP"
echo
echo "| | Absolu | Ratio |"
echo "|---|---|---|"
if [ "$TOTAL" -gt 0 ]; then
  echo "| **Structuré** (UCP+MCP+llms) | **$STRUCT** | **$(awk -v s=$STRUCT -v t=$TOTAL 'BEGIN{printf "%.1f%%", (s/t)*100}')** |"
  echo "| Vitrine (HTML) | $FRONT | $(awk -v f=$FRONT -v t=$TOTAL 'BEGIN{printf "%.1f%%", (f/t)*100}') |"
else
  echo "| **Structuré** (UCP+MCP+llms) | 0 | n/a |"
  echo "| Vitrine (HTML) | 0 | n/a |"
fi
echo
echo "> ⚠️ **Les deux chiffres se lisent ENSEMBLE.** Le ratio seul peut monter par simple"
echo "> accalmie du crawl HTML, sans qu'un seul agent UCP de plus soit passé — le dénominateur"
echo "> est dominé par la vitrine. L'**absolu structuré** est le compteur d'adoption réelle ;"
echo "> le ratio mesure la bascule. Suivre la paire, semaine après semaine."
echo
echo "## Endpoints"
echo
echo "| Endpoint | Accès |"
echo "|---|---|"
q "SELECT COALESCE(endpoint,'(nc)'), COUNT(*) c FROM ps_fondouk_access_log WHERE $W GROUP BY endpoint ORDER BY c DESC;" \
 | awk -F'\t' '{printf "| %s | %s |\n",$1,$2}'
echo
echo "## Performance"
echo
echo "| Métrique | Valeur |"
echo "|---|---|"
echo "| Latence p50 | ${P50} ms |"
echo "| Latence p95 | ${P95} ms |"
echo "| Latence max | ${PMAX} ms |"
echo "| Réponses 429 | $R429 ($RATE429) |"
echo
echo "## Top produits consultés"
echo
echo "| # | Produit | Vues |"
echo "|---|---|---|"
q "SELECT COALESCE(pl.name, CONCAT('id ', a.id_product)), COUNT(*) c
   FROM ps_fondouk_access_log a
   LEFT JOIN ps_product_lang pl ON pl.id_product = a.id_product AND pl.id_lang = 1 AND pl.id_shop = 1
   WHERE $W AND a.id_product IS NOT NULL AND a.id_product > 0
   GROUP BY a.id_product ORDER BY c DESC LIMIT 10;" \
 | awk -F'\t' '{printf "| %d | %s | %s |\n",NR,$1,$2}'
echo
echo "---"
echo "_Généré le $(date '+%d/%m/%Y %H:%M') par \`scripts/telemetry-weekly.sh\`._"
} > "$REPORT"

# --- mémoriser les UA vus (pour repérer les nouveaux la prochaine fois) -------
{ cat "$KNOWN"; printf '%s\n' "$CUR_UA"; } | sed '/^$/d' | sort -u > "$KNOWN.tmp" && mv "$KNOWN.tmp" "$KNOWN"

echo "✓ rapport écrit : $REPORT  ($TOTAL accès, $(printf '%s\n' "$NEW_UA" | sed '/^$/d' | wc -l) nouvel(s) UA)"
