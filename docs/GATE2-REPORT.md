# Gate 2 — Rapport de revue sécurité

> Revue formelle avant soumission Addons, sur l'ensemble figé (REST + MCP + durcissement). Grille en six familles. Chaque item : méthode/commande, preuve, verdict, correctifs (avec commit), et ce qui reste accepté-avec-justification.
>
> **Environnement de test** : boutiques Docker PS8 (`:8081`) + PS9 (`:8091`), catalogue seedé (`docker/seed/seed.php`). Harnais reproductibles : `scripts/agent-proofs.py`, `scripts/hardening-proofs.py`, `scripts/mcp-proofs.py`, `scripts/proof-cache-invalidation.sh`.
>
> **Statut global : PASS** — 2 trouvailles (F2a, F2b) corrigées + vérifiées ; 1 règle actée (F3) ; 4 familles conformes. La signature qui clôt le gate est celle du mainteneur, pas celle de l'auteur de ce rapport.

---

## Famille 1 — Oracle d'énumération

**Enjeu** : la réponse `not_found` d'un produit caché (B2B-only, AGT-0031 = `#112`) doit être **indistinguable** de celle d'un produit inexistant (`#9999`) — sinon balayage d'IDs = cartographie du catalogue caché.

**Méthode** : `POST /catalog/product` et `tools/call get_product` sur `#112` vs `#9999`, comparaison code/corps/longueur/en-têtes/timing, sur REST **et** MCP.

**Preuve** :
```
REST  #112 (B2B) : code=200 len=234 ctype=application/json  body={... "status":"error"... "Product not found." }
REST  #9999      : code=200 len=234 ctype=application/json  body=IDENTIQUE (octet pour octet)
      timing médian : 112 = 28 ms | 9999 = 26 ms (dans le bruit)
MCP   #112 / #9999 : code=200, isError=true, texte identique au SEUL id échoé près
                     ("Aucun produit public ne correspond à l'identifiant \"112|9999\"...")
```

**Verdict : PASS.** REST byte-identique (aucune fuite). MCP : structure identique, seule variation = l'id **fourni par l'appelant** (pas une information sur le catalogue). Le message REST est **générique** (n'échoie pas l'id). Un produit caché est traité exactement comme un absent, aux deux transports. Idem `lookup` (produits filtrés → absents) et `search` (jamais listés).

---

## Famille 2 — Injections sur les entrées

**Méthode** : payloads adverses sur `query`, curseur, corps JSON, et batch JSON-RPC MCP.

**Preuves & verdicts** :

| Vecteur | Test | Résultat | Verdict |
|---|---|---|---|
| SQLi sur `query` | `" OR "1"="1`, `'; DROP TABLE ps_product;--`, `x%" UNION SELECT 1,2,3--` | `200`, `products` sain, table intacte (`pSQL` échappe) | PASS |
| Caractères de contrôle | `query` avec `\t \x00 \n` | `200` sain | PASS |
| Filtre catégories | valeurs arbitraires | `array_map('intval')` → cast entier | PASS |
| Curseur forgé | `base64({"o":99999999999})` | `200`, `products:[]` (offset `(int)` borné, pas de désérialisation d'objets — `json_decode` en tableau, pas `unserialize`) | PASS |
| JSON profondeur extrême | tableau 600 niveaux | `json_decode` échoue (limite 512) → corps traité vide, `200` normal | PASS |
| **Corps massif** | body ~8 Mo | **AVANT : `200` en 309 ms (accepté) — DoS mémoire** | **F2a → CORRIGÉ** |
| **Batch JSON-RPC MCP** | 500 `tools/call` en 1 requête | **AVANT : `200`, 500 réponses, 1 seul jeton rate-limit consommé** | **F2b → CORRIGÉ** |

### F2a — Absence de limite de taille de corps (DoS mémoire) — CORRIGÉ
`RequestGuard::jsonBody()` borne le corps à **256 KiB** (Content-Length + lecture plafonnée), REST et MCP. Au-delà → `413`.
```
Après : REST 8 Mo -> 413 request_too_large
```
Correctif : commit `cb938f5` (`src/PrestaShop/RequestGuard.php`, `controllers/front/{catalog,mcp}.php`).

### F2b — Batch MCP contourne le rate limiter — CORRIGÉ
La vérification de débit était faite **une fois par requête HTTP**, avant de traiter un batch de N appels. Correctif : batch **cappé à 20**, et rate-limit **facturé par sous-requête** (chaque `tools/call` consomme un jeton).
```
Après : batch 500 -> 400 "Batch too large (max 20 requests per call)"
        batch 20  -> 200, 20 réponses, jetons restants = 99.4 (120 - ~20, PAS 119)
```
Correctif : commit `cb938f5` (`controllers/front/mcp.php`).

**Verdict famille 2 : PASS après correctifs.**

---

## Famille 3 — Injection différée via la télémétrie

**Enjeu** : `user_agent` et `ucp_agent_profile` (en-têtes contrôlés par le client) sont stockés **bruts** (sûrs en base via `pSQL`) — mais **XSS stockée en puissance** pour le futur dashboard Pro qui les affichera.

**Décision (règle actée, pas de code d'affichage à ce jour)** : contrat **« échappement à l'affichage »** gravé maintenant. Tout consommateur DOIT échapper ces deux colonnes au rendu (`htmlspecialchars`/Twig), jamais d'HTML brut.

**Preuve** : règle inscrite dans `src/PrestaShop/AccessLogger.php` (docbloc) et dans la spec `docs/TECHNICAL-SPEC.md` §8bis. Commit `cb938f5`.

**Verdict : ACTÉ.** Choix « stocker brut / échapper à l'affichage » (fidélité de la donnée) + règle contraignante consignée avant que le dashboard n'existe.

---

## Famille 4 — En-têtes et cache

**En-têtes réfléchis** — revue de tous les `header()` des contrôleurs front :
```
Content-Type / Cache-Control / Access-Control-Allow-Origin: *   → constantes
ETag                → md5 du corps (pas d'entrée utilisateur)
Retry-After         → entier (rate limiter)
X-Fondouk-Cache   → constante HIT|MISS
X-Fondouk-RateLimit-Remaining → entier
```
Aucune valeur contrôlée par le client n'est réfléchie dans un en-tête. **PASS.** Le `CORS *` est volontaire (lecture publique, découverte).

**Empoisonnement de cache par dimension oubliée** — la clé (`ResponseCache::key`) couvre : `endpoint` + `id_lang` + `id_currency` + `id_country` + `id_shop` + `hash(corps)` + `version`.
Preuve (dimension langue) :
```
même produit, context.language=fr -> "T-shirt démo 21"
                        =en -> "Demo t-shirt 21"   (entrées de cache distinctes, réponses correctes)
```
**Verdict : PASS.** Toutes les dimensions de variation sont dans la clé ; pas de service croisé.

---

## Famille 5 — Isolation multiboutique

**Enjeu** : une requête sur la boutique A peut-elle faire fuiter un produit exclusif à la boutique B ?

**Code** : chaque requête est scellée sur `id_shop` (JOIN `product_shop... id_shop = <contexte>` dans `publicWhere()` ; `new Product($id, false, $idLang, $idShop)` ; `StockAvailable::...($idShop)`).

**Preuve** (bascule d'un produit sur la seule boutique 2, requête sur la boutique 1) :
```
AGT-0002 (#83) rattaché à id_shop=2 uniquement
'Produit démo 2' visible sur boutique 1 : False   (attendu False)   → restauré
```
**Verdict : PASS.** Isolation par `id_shop` prouvée au niveau requête.
*Réserve consignée* : les deux stacks Docker sont des installs **mono-boutique** distinctes ; un test multiboutique intégral (2 boutiques dans une install, URLs/domaines) est une **amélioration de seed recommandée** avant la mise en avant multiboutique commerciale. Le garde-fou (`id_shop`) est toutefois prouvé.

---

## Famille 6 — Audit de l'historique Git

**Méthode** : `gitleaks detect` sur **tout l'historique** (pas le HEAD).
```
$ docker run --rm -v $PWD:/repo zricethezav/gitleaks:latest detect --source=/repo --redact -v
INF 13 commits scanned. (~499 KB)
INF no leaks found
```
**Verdict : PASS.** Aucun secret : le sel IP est généré au **runtime** (`Tools::passwdGen`, jamais committé), aucun token ngrok n'a été committé, pas d'URL interne sensible.

*Accepté-avec-justification* : `docker-compose.yml` et `.env.example` contiennent des identifiants **par défaut de développement** (`prestashop`/`rootpwd`, `ADMIN_PASSWD=Fondouk2026`) — non signalés par gitleaks, sans valeur en production (localhost only), surchargés par `.env` (gitignoré). Acceptés tels quels pour l'environnement de dev.

**Décision squash-ou-pas** : **conserver l'historique** (13 commits, pas de squash). Justification : historique propre (0 secret), il documente le process gaté (Phase 0 → Gates → durcissement → Gate 2) — atout de crédibilité « référent » pour un repo public. À rejouer si un secret venait à être committé plus tard.

---

## Synthèse

| Famille | Verdict | Action |
|---|---|---|
| 1. Oracle d'énumération | **PASS** | — |
| 2. Injections | **PASS** (après correctifs) | F2a + F2b corrigés (`cb938f5`) |
| 3. Injection différée télémétrie | **ACTÉ** | Règle échappement-à-l'affichage (`cb938f5`) |
| 4. En-têtes & cache | **PASS** | — |
| 5. Isolation multiboutique | **PASS** | Réserve : seed multiboutique recommandé |
| 6. Historique Git | **PASS** | gitleaks clean ; historique conservé |

**Non-régression après correctifs** : `agent-proofs`, `hardening-proofs`, `mcp-proofs` → **ALL_PASS** sur PS8 et PS9.

**Restes hors code (dépendances externes)** : envoyer la question Addons Q6 ; créer l'org publique (`fondouk-dev`) ; démo S4 en HTTPS avec `PS_SHOP_DOMAIN` public.

---

## Clôture

**Gate 2 clos le 2026-07-05, signé par le mainteneur (Synapsea)**, après revue conjointe, avec trois suivis (aucun bloquant, tous traités dans la foulée) :

1. **Amendement spec `413`/`400`/batch-20** : la spec §2.3 documente désormais la distinction `400` (trop d'éléments métier — lookup > 100, batch MCP > 20) vs `413` (corps physiquement > 256 KiB) ; §4.4 aligne les limites code↔spec. *(fait)*
2. **Vérification log fail-open** : le fail-open du rate limiter (dégradé passant sur erreur base) est désormais **loggé** (BO, sévérité 3) en plus d'être documenté — il n'était que documenté. `RateLimiter.php`. *(fait)*
3. **Formulation multiboutique prudente** : règle de claim gravée (spec §10) — pas de « compatible multiboutique » ferme avant le seed multiboutique intégral ; formulation « conçu pour le multiboutique (scoping `id_shop`), validation intégrale en cours ». *(actée)*

Le Gate 2 est derrière nous. La signature débloque le **packaging Addons** (fin S3) puis la **semaine 4** (démo HTTPS, test agent réel, README, Blueprint, contenu de lancement).

---

## Addendum S4 — re-audit historique avant passage public (2026-07-05)

La famille 6 (audit Git) a été **rejouée sur l'historique complet** après les commits de renommage (wakil → Fondouk) : ces commits ont pu introduire des secrets absents au premier scan.

```
$ docker run --rm -v $PWD:/repo zricethezav/gitleaks:latest detect --source=/repo --redact -v
INF 25 commits scanned. (~633 KB)
INF no leaks found
exit=0
```

**Verdict : PASS.** Aucun secret sur l'historique complet post-renommage. Sel IP toujours généré au runtime, aucun token committé. **Décision inchangée : conserver l'historique** (0 secret, il documente le process gaté). Prêt pour le passage public.
