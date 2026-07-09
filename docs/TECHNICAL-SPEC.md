# Fondouk — Spécification technique (Phase 0)

> **Statut : Gate 0 VALIDÉ (2026-07-04) — AUCUN code de production.** Ce document se conforme au document de cadrage du projet. Il tranche les décisions techniques structurantes à partir des **sources officielles** (spec UCP réel + DevDocs PrestaShop), pas de mémoire. Les 12 décisions et 3 corrections validées sont intégrées ; le récapitulatif est en §12. Prochaine barrière : **Gate 1** (schéma du Capability Graph) avant écriture des adapters.
>
> Toute décision ci-dessous est justifiée et rattachée à une source citée. Les points non tranchables seuls sont regroupés en §12 sous forme de questions fermées.

---

## 0. Sources de vérité et versions figées

| Source | Version / réf. figée | Usage |
|---|---|---|
| **UCP** (repo cloné) | Repo `github.com/universal-commerce-protocol/ucp`, commit `7e5fc42` (2026-07-03), branche `main` (schémas marqués `draft`). **Version stable ciblée : `2026-04-08`** (dernière release stable, cf. `mkdocs.yml:236` et le cadrage du projet). | Le spec est la source de vérité (cadrage du projet). |
| **PrestaShop 8** | Branche source `8.2.x`, Symfony 4.4, PHP 7.2–8.1 (8.1 recommandé). | Cible de compatibilité n°1. |
| **PrestaShop 9** | Branche source `9.1.x` (il n'existe **pas** de branche `9.0.x`), Symfony 6.4 LTS, PHP 8.1 min (8.2–8.5 supportés). | Cible de compatibilité n°2. |

**⚠️ Caveat de version UCP.** Les schémas lus dans ce document proviennent de la branche `main` (`ucp_version = "draft"`, `mkdocs.yml:107`). La **structure** de la capability Catalog (search / lookup / product) et du manifest est stable, mais l'implémentation **DEVRA valider ses réponses contre les schémas publiés de la release `2026-04-08`** (`https://ucp.dev/2026-04-08/schemas/...`), et le manifest DEVRA déclarer `version: "2026-04-08"`. **Décision Gate 0 (Q1) : cible figée à `2026-04-08`, validation obligatoire contre ses schémas publiés.**

**Chemins de fichiers cités du repo UCP** (racine = repo cloné) :
- Manifest / profil : `source/schemas/profile.json`, `source/schemas/ucp.json`
- Entités de découverte : `source/schemas/service.json`, `source/schemas/capability.json`
- Capability Catalog : `source/schemas/shopping/catalog_search.json`, `source/schemas/shopping/catalog_lookup.json`
- Types produit : `source/schemas/shopping/types/{product,variant,price_range,availability,category,product_option,option_value,selected_option,detail_option_value,rating,input_correlation}.json`
- Types communs : `source/schemas/common/types/{price,amount,media,description,pagination}.json`
- Binding REST : `source/services/shopping/rest.openapi.json` ; binding MCP : `source/services/shopping/mcp.openrpc.json`
- Doc normative : `docs/specification/overview.md`, `docs/specification/catalog/{index,rest}.md`, `docs/versioning.md`

---

## 1. Périmètre de la spec (rappel de scope, le cadrage du projet)

**Dans cette spec (v1 gratuite, juillet) :** introspection → capability graph → **Adapter UCP** (manifest `/.well-known/ucp` + Catalog `search`/`lookup`/`product` en REST) + **Adapter MCP** lecture seule (priorité 2) + cache/rate-limiting + opt-out minimal + télémétrie locale. **Lecture seule stricte.** PS 8 et 9 uniquement.

**Hors spec :** checkout / panier / paiement (UCP Cart/Checkout, AP2), WebMCP (priorité 3, backlog si le temps manque), WooCommerce, PrestaShop 1.7, toute écriture, tout prix client/groupe B2B, toute donnée client.

---

## 2. Le spec UCP réel — ce qu'on implémente

### 2.1 Découverte et manifest `/.well-known/ucp`

Le manifest est un **profil business** (`source/schemas/profile.json#/$defs/business_schema`) : un objet JSON avec un membre requis `ucp` (`source/schemas/ucp.json#/$defs/base`). Pour un profil business, `ucp.version`, `ucp.services` et `ucp.payment_handlers` sont **requis** (même vides) ; `ucp.capabilities` est optionnel mais on le fournit (`docs/specification/overview.md:450-455`).

**Exigences d'hébergement du profil** (`overview.md:1288-1304`) :
- Servi en **HTTPS** obligatoirement.
- **Aucune redirection 3xx** (le manifest ne doit pas rediriger).
- En-tête `Cache-Control` avec `public` et `max-age ≥ 60` s. Interdit : `private`, `no-store`, `no-cache`.

**Manifest cible de Fondouk** (business profile, transport REST prioritaire ; MCP ajouté en semaine 3) :

```json
{
  "ucp": {
    "version": "2026-04-08",
    "services": {
      "dev.ucp.shopping": [
        {
          "version": "2026-04-08",
          "transport": "rest",
          "endpoint": "https://boutique.example/fondouk/ucp",
          "schema": "https://ucp.dev/2026-04-08/services/shopping/rest.openapi.json",
          "spec": "https://ucp.dev/2026-04-08/specification/overview"
        },
        {
          "version": "2026-04-08",
          "transport": "mcp",
          "endpoint": "https://boutique.example/fondouk/ucp/mcp",
          "schema": "https://ucp.dev/2026-04-08/services/shopping/mcp.openrpc.json",
          "spec": "https://ucp.dev/2026-04-08/specification/overview"
        }
      ]
    },
    "capabilities": {
      "dev.ucp.shopping.catalog.search": [
        {
          "version": "2026-04-08",
          "schema": "https://ucp.dev/2026-04-08/schemas/shopping/catalog_search.json",
          "spec": "https://ucp.dev/2026-04-08/specification/catalog/search"
        }
      ],
      "dev.ucp.shopping.catalog.lookup": [
        {
          "version": "2026-04-08",
          "schema": "https://ucp.dev/2026-04-08/schemas/shopping/catalog_lookup.json",
          "spec": "https://ucp.dev/2026-04-08/specification/catalog/lookup"
        }
      ]
    },
    "payment_handlers": {}
  }
}
```

Notes de conformité :
- **`schema` d'un service/capability est *authority-bound*** : l'hôte de l'URL `schema`, labels inversés, doit préfixer le nom (`overview.md:106-160`). Nos `schema` pointent sur `ucp.dev` → `dev.ucp` préfixe `dev.ucp.shopping.*` ✔. En revanche `endpoint` **n'est pas** authority-bound : il pointe sur la boutique ✔ (`service.json`, `overview.md:213-242`).
- Le service business `rest` **exige `endpoint`** ; la capability business **exige `schema`** (`service.json#/$defs/business_schema`, `capability.json#/$defs/business_schema`).
- Pour la capability **Lookup**, dès qu'elle est déclarée, **`/catalog/lookup` ET `/catalog/product` DOIVENT exister** (`docs/specification/catalog/rest.md:603`).
- **Principe de véracité du manifest — « a manifest is a promise » (règle non négociable).** Le manifest étant généré par introspection, il décrit **ce qui EST, pas ce qui est prévu** : il ne déclare **que des capabilities opérationnelles**. Tant qu'un endpoint est un stub, sa capability (et son transport s'il n'en porte aucune autre) **n'apparaît pas** — `services`/`capabilities` valent `{}`. Un manifest vide vaut mieux qu'un manifest menteur (fatal le jour d'un test agent ou de la démo publique). Implémenté dans `ShopProfileSource::enabledCapabilities()` (source de vérité côté adapter) + `UcpManifestBuilder`. Voir `docs/blueprint/discovery-pattern.md`.

### 2.2 Versioning UCP

Identifiants de version **date-based `YYYY-MM-DD`** = dernière date d'un changement rétro-incompatible (`docs/versioning.md:1-5`, `source/schemas/ucp.json:8-12`). On fige `2026-04-08`. `supported_versions` permet d'annoncer d'anciennes versions ; non nécessaire en v1 (une seule version publiée).

### 2.3 Capability Catalog — transport REST (binding v1)

Endpoints, tous relatifs à `endpoint` du manifest, tous en **POST** (`source/services/shopping/rest.openapi.json`, `catalog/rest.md:62-68`) :

| Endpoint | operationId | Capability | Rôle |
|---|---|---|---|
| `POST /catalog/search` | `search_catalog` | `catalog.search` | Recherche plein-texte + filtres |
| `POST /catalog/lookup` | `lookup_catalog` | `catalog.lookup` | Récupération batch par identifiants |
| `POST /catalog/product` | `get_product` | `catalog.lookup` | Fiche produit détaillée (objet `product` singulier) |

**Modèle d'erreur à deux couches** (`catalog/rest.md:526-579`, `overview.md:894-948`) :
- **Erreurs transport** = codes HTTP : `400` (JSON malformé, params manquants, **ou trop d'éléments métier** — batch `lookup` > 100 ids, batch JSON-RPC MCP > 20 requêtes → `request_too_large` / `batch too large`), `401` (auth), `413` (**corps physiquement trop volumineux**, > 256 KiB — `RequestGuard`, anti-DoS mémoire), `429` (rate limit, avec `Retry-After`), `500`. **Distinction (Gate 2) : `400` = trop d'items métier ; `413` = corps trop gros.**
- **Résultats métier** = **HTTP 200** + enveloppe `ucp` + tableau `messages` optionnel. Recherche sans résultat = `products: []` sans message (pas une erreur). Produit introuvable sur `/catalog/product` = HTTP 200, `ucp.status: "error"`, message `code:"not_found"`, `severity:"unrecoverable"`.
- Toute réponse porte `ucp.version` + `ucp.capabilities` (capabilities actives, `overview.md:861-864`).

**Pagination** : cursor-based, `limit` défaut **10**, l'implémentation PEUT clamper à un max inférieur (`common/types/pagination.json`, `catalog/rest.md:603`). En réponse : `{cursor, has_next_page, total_count?}`, `cursor` requis si `has_next_page:true`.

**En-têtes** : requêtes UCP portent `UCP-Agent: profile="..."` (RFC 8941) — obligatoire par le spec REST (`catalog/rest.md:521-524`). **Décision Q10** : en v1 lecture publique, `UCP-Agent` est **toléré absent** (non bloquant) et **loggé s'il est présent** (§8bis).

**Montants** : entiers en **unités mineures** (centimes) selon l'exposant ISO 4217 de la devise (`common/types/amount.json` : `type:"integer", minimum:0` ; `common/types/price.json` : `{amount, currency}` avec `currency ~ ^[A-Z]{3}$`).

### 2.4 Capability Catalog — transport MCP (priorité 2)

Serveur MCP lecture seule, méthodes JSON-RPC équivalentes (`source/services/shopping/mcp.openrpc.json`) : `search_catalog`, `lookup_catalog`, `get_product`. Transport **Streamable HTTP** (cadrage du projet). Profil MCP porte `meta.ucp-agent.profile` (`overview.md:808-831`). Mêmes DTO internes → même mapping que REST : le core produit le capability graph une fois, deux adapters le sérialisent.

---

## 3. Le socle PrestaShop — mécanismes PS8/PS9

### 3.1 Structure et cycle de vie du module (identique PS8/PS9)

- Dossier `/modules/fondouk/`, nom technique **minuscule alphanumérique** = `fondouk` (contrainte Addons, §6).
- Fichier principal `fondouk.php`, classe `Fondouk extends Module`, en-tête `if (!defined('_PS_VERSION_')) { exit; }`.
- `logo.png` 32×32 ; stub `index.php` (`header('Location:../'); exit;`) à la racine **et dans chaque sous-dossier** ; `config.xml` auto-généré à l'install.
- Propriétés `__construct` : `name`, `author`, `version`, `tab`, `ps_versions_compliancy = ['min'=>'8.0.0','max'=>'9.99.99']`, `bootstrap`, `displayName`, `description`.
- `install()` / `uninstall()` retournent `bool` chaînés en `&&` ; `parent::install()` + `registerHook(...)` + `Configuration::updateValue(...)`. Sur multiboutique : `if (Shop::isFeatureActive()) Shop::setContext(Shop::CONTEXT_ALL);` avant l'install.
- Sources : DevDocs `9/modules/creation/tutorial/`, `9/modules/concepts/module-class/`, `9/modules/concepts/hooks/`.

**Différences PS9 à intégrer** (DevDocs `9/modules/core-updates/9.0/`) : Symfony 4.4→6.4, PHP 8.1 min, libs retirées du core (`guzzlehttp/guzzle`→Symfony HTTP Client, `swiftmailer`, `tactician`…), contrôleurs **Back Office** en services Symfony, `trans()` n'échappe plus, `Context::$cookie` déconseillé au profit de services `ShopContext`/`LanguageContext`/`CurrencyContext`. **Le renommage `ean13`→`gtin` (couche CQRS) est à noter pour le mapping des codes-barres.**

### 3.2 Routing front-office — le point critique

**Fait décisif : le front-office n'est PAS migré vers Symfony, ni en PS8 ni en PS9.** Le `FrontKernel` PS9 est **expérimental** ; la doc de routing Symfony moderne (`9/development/architecture/modern/controller-routing/`) concerne **exclusivement le Back Office** (`src/PrestaShopBundle/Controller/Admin`). Un module **ne peut pas** enregistrer une route front-office Symfony via `config/routes.yml` en PS8 comme en PS9.

⇒ **Le mécanisme portable PS8+PS9 pour une URL front est le couple `ModuleFrontController` + hook `moduleRoutes`** (`8/` et `9/modules/concepts/controllers/front-controllers/`).

- **Front controller** : `controllers/front/<Nom>.php`, classe `Fondouk<Nom>ModuleFrontController extends ModuleFrontController`. URL sans réécriture : `index.php?fc=module&module=fondouk&controller=<nom>`. Avec réécriture : `/module/fondouk/<nom>`.
- **Hook `moduleRoutes`** (`9/modules/concepts/hooks/list-of-hooks/moduleroutes/`, `classes/Dispatcher.php`) : retourne un tableau `rule` / `keywords` (chaque `{param}` → `['regexp'=>…,'param'=>…]`) / `controller` / `params:['fc'=>'module','module'=>'fondouk']`. `Dispatcher::loadRoutes()` charge la règle du module **avant** les règles prédéfinies (priorité plus haute). L'URL cliente est exacte (sans `?fc=...`).

**Servir `/.well-known/ucp` (chemin fixe, racine, sans query string) — analyse du `.htaccess` PrestaShop** (source vérifiée : `classes/Tools.php::generateHtaccess()`, 8.2.x) :

```apache
# Un fichier/lien/dossier PHYSIQUE existant est servi tel quel, court-circuite index.php :
RewriteCond %{REQUEST_FILENAME} -s [OR]
RewriteCond %{REQUEST_FILENAME} -l [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^.*$ - [NC,L]
...
RewriteRule ^api(?:/(.*))?$ %{ENV:REWRITEBASE}webservice/dispatcher.php?url=$1 [QSA,L]
...
RewriteRule ^.*$ %{ENV:REWRITEBASE}index.php [NC,L]
```
Le bloc géré est délimité par `# ~~start~~ … # ~~end~~` ; **aucune règle `.well-known`** n'existe. Conséquences :
1. Si `.well-known/` **existe physiquement** (créé par Let's Encrypt/ACME, panels d'hébergement…), la condition `-d` sert le dossier directement → **la route du module est court-circuitée** et `/.well-known/ucp` renvoie le 404 d'Apache. **C'est le principal risque de compatibilité.**
2. Sinon, sans fichier physique, la requête tombe sur `RewriteRule ^.*$ index.php` → le dispatcher PrestaShop voit l'URL et `moduleRoutes` peut la router — **à condition que la réécriture d'URL (friendly URLs / mod_rewrite) soit activée.**

Voir décision §4.2.

### 3.3 Accès aux données catalogue — classes internes

Toutes les classes étendent `ObjectModel` (`classes/`). Signatures **vérifiées** sur `8.2.x` / `9.1.x` (PS9 ne diffère que par des type-hints) :

- `Product::getProducts($id_lang, $start, $limit, $order_by, $order_way, $id_category=false, $only_active=false, Context $context=null)` — `classes/Product.php` (8.2 L1628 / 9.1 L1572).
- **`Product::getPriceStatic($id_product, $usetax=true, $id_product_attribute=null, $decimals=6, $divisor=null, $only_reduc=false, $usereduc=true, $quantity=1, $force_associated_tax=false, $id_customer=null, $id_cart=null, $id_address=null, &$specific_price_output=null, $with_ecotax=true, $use_group_reduction=true, Context $context=null, $use_customer_price=true, $id_customization=null)`** — `classes/Product.php` (8.2 L3609 / 9.1 L3332). **Point de calcul de prix central.** `$specific_price_output` renvoie par référence la ligne SpecificPrice appliquée.
- `Combination::getAttributeCombinations($id_lang=null, $groupByIdAttributeGroup=true)` — `classes/Combination.php` (8.2 L2792 / 9.1 L2530). Remplit `quantity` via StockAvailable.
- `StockAvailable::getQuantityAvailableByProduct($id_product=null, $id_product_attribute=null, $id_shop=null)` — `classes/stock/StockAvailable.php` (8.2 L433 / 9.1 L227). `id_product_attribute=0/null` → quantité produit entier. PS9 déclenche le hook `actionOverrideQuantityAvailableByProduct`.
- `SpecificPrice::getSpecificPrice($id_product, $id_shop, $id_currency, $id_country, $id_group, $quantity, $id_product_attribute=null, $id_customer=0, $id_cart=0, $real_quantity=0)` — `classes/SpecificPrice.php` (8.2 L502 / 9.1 L489). Renvoie `[]` si `!SpecificPrice::isFeatureActive()`, sinon la meilleure ligne par priorité **shop / currency / country / group / customer** (`reduction`, `reduction_type` `amount|percentage`, `price`).
- `ProductPresenter::present(...)` → `ProductLazyArray` (`src/Adapter/Presenter/Product/ProductPresenter.php` L95→L100 ; `ProductLazyArray.php` L51). `addPriceInformation()` (L750) respecte `ProductPresentationSettings->include_taxes`, calcule `price_amount`, `regular_price_amount`, `has_discount`, `discount_*` via `PriceFormatter`. Hooks de décoration : `actionPresentProduct` / `actionPresentProductListing`.

### 3.4 Webservice `/api` — évalué et écarté (voir §4.1)

Legacy `/api` (PS8+PS9) : passerelle CRUD, auth = **clé WS 32 car. en Basic-auth username sans password**, sortie XML (JSON via `?output_format=JSON`). La ressource `products` renvoie **les champs bruts stockés** (`price` base, `wholesale_price`, `ecotax`) — **aucun prix public calculé** (pas de TTC, pas de réduction appliquée, pas de conversion devise), le stock est un appel **séparé** `stock_availables`. ⇒ reproduire le prix vitrine impose de recomposer côté client (specific_prices + taxes + réductions groupe + devise) et enchaîne les appels (N+1). Sources : DevDocs `8/webservice/resources/products/`, `8/webservice/getting-started/`.

PS9 ajoute une **Admin API** `/admin-api` (API Platform v3, CQRS, **OAuth2 client credentials**) — moderne mais **jeu de ressources étroit à la GA, sans endpoint catalogue prix/stock complet** ⇒ pas un substitut de crawl catalogue. Sources : DevDocs `9/admin-api/`, `9/admin-api/oauth/`.

---

## 4. Décisions tranchées

### 4.1 (a) Accès aux données : **classes internes / ORM + Presenter**, PAS le webservice

**Décision : le `capability-core` définit des *ports* (interfaces) d'introspection ; l'adapter PrestaShop les implémente via les classes internes (`Product`, `Combination`, `StockAvailable`, `SpecificPrice`) et le `ProductPresenter`. Le webservice `/api` et l'Admin API PS9 sont écartés comme source de données.**

Justification, au critère **fiabilité multiboutique + performance sous crawl** (cadrage du projet) :
1. **Fidélité au prix public.** Seuls `getPriceStatic` (paramétré anonyme) et surtout `ProductPresenter/ProductLazyArray` reproduisent **le prix exact vu par un visiteur** (TTC/HT selon réglages, devise, réductions catalogue, ecotax, arrondi). Le webservice renvoie du brut → on réimplémenterait le moteur de prix PrestaShop, source de divergences et de bugs de conformité (règle le cadrage du projet : « est-ce visible par un visiteur anonyme ? »).
2. **Zéro configuration.** Le webservice doit être **activé et une clé créée** par le marchand → viole le principe « installation → conforme » (cadrage du projet). Les classes internes sont disponibles en process dès l'install.
3. **Performance sous crawl.** In-process (pas d'aller-retour HTTP, pas de sérialisation/parse XML, pas de N+1 produit→stock→prix→images). On maîtrise le cache et le batching. Les mutualisés OVH survivent mieux (cadrage du projet).
4. **Multiboutique fiable.** `Shop::setContext` + lecture par `id_shop` donne un contrôle exact du contexte ; le webservice impose `id_shop` par appel et multiplie les requêtes.
5. **Découplage préservé.** Les classes PrestaShop restent **confinées à l'adapter** (`prestashop-module/`) derrière les ports du core ; `capability-core/` reste sans dépendance PrestaShop (indispensable pour les modes librairie/proxy, le cadrage du projet).

**Stratégie à deux niveaux (fidélité + perf) :**
- **Listing / recherche** (`/catalog/search`) : requête SQL bornée (`Db` + filtres visibilité) pour l'ensemble candidat, puis **`getPriceStatic` en contexte anonyme** par produit/variant pour le prix, et lecture stock groupée. Évite le coût du presenter complet sur de longues listes.
- **Fiche détaillée** (`/catalog/product`) : **`ProductPresenter::present()`** pour la fidélité maximale (prix formaté, disponibilité, hooks `actionPresentProduct`).
- Dans les deux cas, la **fonction de prix canonique** est `getPriceStatic` en **contexte visiteur anonyme** : `id_customer=0`, `use_customer_price=false`, `id_group = Configuration::get('PS_UNIDENTIFIED_GROUP')`, `id_currency` = devise de présentation, `id_country` = pays de présentation. Voir §5 (filtre public) et décisions Q2/Q3 (§12).

### 4.2 (b) Routing `/.well-known/` compatible PS8 et PS9

**Décision :**
1. **Le manifest est servi à `/.well-known/ucp` via le hook `moduleRoutes`** (règle `rule => '.well-known/ucp'`, `controller => 'ucp'`), route vers un `ModuleFrontController` legacy (portable PS8+PS9, aucune édition de fichier cœur, URL exacte sans query string).
2. **Les endpoints REST/MCP ne sont PAS sous `/.well-known/`** : ils vivent sous une base module (`endpoint` du manifest = `https://boutique/fondouk/ucp`, routée elle aussi par `moduleRoutes` : `fondouk/ucp/catalog/search`, etc.). Seul le manifest requiert le chemin well-known ; le spec impose uniquement le manifest à `/.well-known/ucp` (`overview.md:440-448`), l'`endpoint` des services est libre.
3. **Prérequis d'environnement détectés et signalés** (écran de diagnostic du module, sans réglage obligatoire) :
   - **Réécriture d'URL (friendly URLs)** activée (`Configuration::get('PS_REWRITING_SETTINGS')`) — sans elle le manifest `/.well-known/ucp` n'est pas routé ; c'est l'écart au « zéro config ». On **n'écrit pas** dans le `.htaccess` du cœur (interdit par Addons, §6).
   - **HTTPS** : le spec UCP **exige** HTTPS pour le manifest (`overview.md:1295`). Une base URL non-HTTPS est détectée et signalée. En production/démo publique (S4), la boutique **doit** être servie en HTTPS, sinon aucun agent réel ne la découvrira — le test de bout en bout avec un vrai agent se fait en HTTPS.
   - **Conflit `.well-known` physique** (voir point 4).
4. **Gestion explicite du conflit `.well-known/` physique** (dossier créé par ACME/hébergeur → court-circuit Apache via `-d`) : détection à l'install **et au runtime** (test d'existence d'un `.well-known` physique masquant). En cas de conflit, un **repli fichier statique** `/.well-known/ucp` (manifest sérialisé, régénéré à chaque modif de config, servi par la règle `-s`) est disponible **mais DÉSACTIVÉ par défaut** (correction Gate 0 n°2) — il n'est activé que par une **action marchande explicite (opt-in)**, car il écrit dans l'arborescence web. Sans opt-in, le conflit est seulement **signalé** au marchand.
5. **À poser à PrestaShop Addons avant soumission — LES DEUX points (décision Q6)** : (i) la route `/.well-known/ucp` via `moduleRoutes`, et (ii) le repli fichier statique dans `.well-known/`. Aucune règle Addons ne les couvre explicitement (§6) ; on ne présume pas de leur acceptation.

Justification : le legacy `ModuleFrontController` est le **seul** mécanisme front-office portable PS8/PS9 (le front n'est pas Symfony) ; `moduleRoutes` produit l'URL exacte, prioritaire sur les routes cœur, sans toucher aux fichiers cœur.

### 4.3 (c) Format v0 du JSON Schema du Capability Graph

Le Capability Graph est le **format pivot interne, indépendant de tout protocole** (le Blueprint du projet artefact n°1). Il vit dans `capability-core/schema/capability-graph.v0.json`, versionné.

**Sémantique (correction Gate 0 n°1) : le graphe est un *modèle logique*, jamais matérialisé en entier.** Une instance concrète est toujours un **sous-ensemble matérialisé à la demande** : une page de recherche, un batch de lookup, ou une fiche produit unique. Le schéma décrit la *forme* des entités ; `products` porte le sous-ensemble pertinent pour la requête courante (0..N), et un bloc `subset` qualifie la matérialisation. Le catalogue complet n'est jamais requis d'un coup — ce qui rend le core compatible crawl paginé, mode librairie et mode proxy.

Il est conçu pour **mapper trivialement vers UCP `product`/`variant`** tout en restant neutre (réutilisable par MCP/WebMCP). Schéma proposé (Draft 2020-12) — **v0, se raffinera** :

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://fondouk.dev/schema/capability-graph.v0.json",
  "title": "Fondouk Capability Graph",
  "description": "Modèle LOGIQUE neutre décrivant ce qu'un système e-commerce sait exposer à un agent, indépendant de UCP/MCP/WebMCP. Jamais matérialisé en entier : une instance est un sous-ensemble (recherche, lookup, fiche) qualifié par 'subset'.",
  "type": "object",
  "required": ["graph_version", "shop", "generated_at", "products"],
  "properties": {
    "graph_version": { "const": "0", "description": "Version du schéma du graphe." },
    "generated_at": { "type": "string", "format": "date-time" },
    "subset": {
      "type": "object",
      "description": "Qualifie la matérialisation courante (le graphe complet n'est jamais requis d'un coup).",
      "required": ["kind"],
      "properties": {
        "kind": { "type": "string", "enum": ["search", "lookup", "product", "full"] },
        "cursor": { "type": "string", "description": "Curseur de pagination opaque, si kind=search." },
        "has_next": { "type": "boolean" },
        "total_count": { "type": "integer", "minimum": 0 }
      }
    },
    "shop": {
      "type": "object",
      "required": ["id", "base_url", "default_language", "default_currency", "languages", "currencies"],
      "properties": {
        "id": { "type": "string", "description": "Identifiant de boutique (id_shop en PrestaShop)." },
        "gid_base": { "type": "string", "description": "Base NEUTRE des GID = domaine de la boutique (ex. 'boutique.example'). Les GID appartiennent au marchand et survivent à une désinstallation de Fondouk (aucune marque dans l'identifiant)." },
        "name": { "type": "string" },
        "base_url": { "type": "string", "format": "uri" },
        "default_language": { "type": "string", "description": "IETF BCP 47 (ex. 'fr')." },
        "default_currency": { "type": "string", "pattern": "^[A-Z]{3}$" },
        "languages": { "type": "array", "items": { "type": "string" } },
        "currencies": { "type": "array", "items": { "type": "string", "pattern": "^[A-Z]{3}$" } },
        "default_country": { "type": "string", "description": "ISO 3166-1 alpha-2, pays d'hypothèse fiscale par défaut." },
        "prices_include_tax": { "type": "boolean", "description": "La présentation par défaut affiche-t-elle le TTC ?" }
      }
    },
    "capabilities": {
      "type": "array",
      "description": "Capacités exposables détectées par introspection.",
      "items": { "type": "string", "examples": ["catalog.search", "catalog.lookup"] }
    },
    "categories": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["id", "name"],
        "properties": {
          "id": { "type": "string" },
          "name": { "type": "string" },
          "path": { "type": "string", "description": "Chemin lisible 'Racine > Parent > Enfant'." },
          "parent_id": { "type": "string" }
        }
      }
    },
    "products": {
      "type": "array",
      "items": { "$ref": "#/$defs/product" }
    }
  },
  "$defs": {
    "money": {
      "type": "object",
      "required": ["amount_minor", "currency"],
      "properties": {
        "amount_minor": { "type": "integer", "minimum": 0, "description": "Unités mineures ISO 4217 (centimes)." },
        "currency": { "type": "string", "pattern": "^[A-Z]{3}$" }
      }
    },
    "localized_text": {
      "type": "object",
      "description": "Texte par langue, clé = code langue BCP 47.",
      "additionalProperties": { "type": "string" }
    },
    "product": {
      "type": "object",
      "required": ["id", "title", "public", "variants"],
      "properties": {
        "id": { "type": "string", "description": "Identifiant stable (id_product). GID neutre = gid://{shop.gid_base}/{shop.id}/product/{id_product} — appartient au marchand, sans marque Fondouk." },
        "handle": { "type": "string", "description": "Slug URL (link_rewrite)." },
        "reference": { "type": "string" },
        "title": { "$ref": "#/$defs/localized_text" },
        "description": { "$ref": "#/$defs/localized_text" },
        "description_short": { "$ref": "#/$defs/localized_text" },
        "url": { "$ref": "#/$defs/localized_text", "description": "URL canonique par langue." },
        "public": { "type": "boolean", "description": "Passe le filtre 'visible par un visiteur anonyme'. En mode catalogue / prix masqué, le produit reste public=true (le visiteur le VOIT) mais ses variants ont prices=[] (il n'en voit pas le prix)." },
        "visibility_reason": { "type": "string", "description": "Si public=false, motif (inactive, group_restricted, catalog_mode, price_hidden…)." },
        "category_ids": { "type": "array", "items": { "type": "string" } },
        "manufacturer": { "type": "string" },
        "media": { "type": "array", "items": { "$ref": "#/$defs/media" } },
        "option_axes": {
          "type": "array",
          "description": "Axes d'options (groupes d'attributs) et valeurs possibles.",
          "items": {
            "type": "object",
            "required": ["name", "values"],
            "properties": {
              "name": { "$ref": "#/$defs/localized_text" },
              "values": { "type": "array", "items": { "$ref": "#/$defs/localized_text" } }
            }
          }
        },
        "rating": {
          "type": "object",
          "properties": {
            "value": { "type": "number" }, "scale_max": { "type": "number" }, "count": { "type": "integer" }
          }
        },
        "variants": { "type": "array", "minItems": 1, "items": { "$ref": "#/$defs/variant" } }
      }
    },
    "variant": {
      "type": "object",
      "required": ["id", "prices", "availability"],
      "properties": {
        "id": { "type": "string", "description": "GID neutre gid://{shop.gid_base}/{shop.id}/variant/{id_product}-{id_product_attribute} (…-0 si pas de déclinaison)." },
        "sku": { "type": "string" },
        "barcodes": {
          "type": "array",
          "items": {
            "type": "object",
            "required": ["type", "value"],
            "properties": { "type": { "type": "string", "enum": ["EAN", "UPC", "ISBN"] }, "value": { "type": "string" } }
          }
        },
        "title": { "$ref": "#/$defs/localized_text" },
        "option_values": {
          "type": "array",
          "items": {
            "type": "object",
            "properties": { "axis": { "$ref": "#/$defs/localized_text" }, "value": { "$ref": "#/$defs/localized_text" } }
          }
        },
        "prices": {
          "type": "array",
          "description": "Prix public par devise de présentation (prix courant + référence). PEUT être vide ([]) quand le prix est légitimement masqué au visiteur (mode catalogue, show_price=0) : le produit reste exposé, sans prix.",
          "items": {
            "type": "object",
            "required": ["price"],
            "properties": {
              "price": { "$ref": "#/$defs/money", "description": "Prix public courant (réductions catalogue anonymes appliquées)." },
              "list_price": { "$ref": "#/$defs/money", "description": "Prix de référence avant réduction." },
              "tax_included": { "type": "boolean" },
              "country": { "type": "string", "description": "Pays d'hypothèse fiscale de ce prix." }
            }
          }
        },
        "unit_price": {
          "type": "object",
          "properties": {
            "price": { "$ref": "#/$defs/money" },
            "measure": { "type": "object", "properties": { "value": { "type": "number" }, "unit": { "type": "string" } } },
            "reference": { "type": "object", "properties": { "value": { "type": "number" }, "unit": { "type": "string" } } }
          }
        },
        "availability": {
          "type": "object",
          "required": ["available"],
          "properties": {
            "available": { "type": "boolean" },
            "status": { "type": "string", "enum": ["in_stock", "out_of_stock", "backorder", "preorder", "discontinued"] },
            "quantity": { "type": "integer", "description": "Interne — NON exposé publiquement par défaut." }
          }
        },
        "media": { "type": "array", "items": { "$ref": "#/$defs/media" } }
      }
    },
    "media": {
      "type": "object",
      "required": ["type", "url"],
      "properties": {
        "type": { "type": "string", "enum": ["image", "video", "model_3d"] },
        "url": { "type": "string", "format": "uri" },
        "alt_text": { "type": "string" },
        "width": { "type": "integer" }, "height": { "type": "integer" }
      }
    }
  }
}
```

Le graphe porte le **texte multilingue** (clé = langue) et **plusieurs devises** par variant, pour que l'adapter UCP projette la langue/devise de présentation résolues depuis `context`. Le champ interne `availability.quantity` **n'est jamais** sérialisé vers UCP (le spec expose `available`/`status`, pas la quantité — `availability.json`). Ce schéma est le **Gate 1** du document de cadrage (validation avant écriture des adapters).

### 4.4 (d) Cache et rate limiting par défaut

**Cache :**
- **Manifest** : `Cache-Control: public, max-age=300` (≥ 60 imposé par UCP, `overview.md:1297`), plus `ETag`. Servi sans redirect, HTTPS.
- **Graphe/réponses catalogue** : cache applicatif PrestaShop (`Cache`/filesystem) clé = `(id_shop, id_lang, id_currency, id_country, hash(requête))`, TTL court (défaut 300 s). **Invalidation** branchée sur les hooks produit : `actionProductUpdate`, `actionUpdateQuantity`, `actionProductSave/Delete`, changements de specific_prices → bump d'un compteur de version inclus dans la clé de cache (invalidation paresseuse, pas de purge coûteuse).
- Réponses HTTP catalogue : `ETag`/`Last-Modified` + `Cache-Control: public, max-age=60` (les réponses catalogue ne sont pas des engagements transactionnels, `catalog/index.md:52-54`).

**Rate limiting** (garde-fou non négociable, le cadrage du projet — « survivre à 50 agents ») :
- **Token-bucket par IP** (et par profil `UCP-Agent` si présent), défaut **60 req/min, burst 120**. Dépassement → **HTTP 429 + `Retry-After`** (conforme `overview.md:940-948`).
- **Pagination clampée** : `limit` défaut 10, **max 50**. **Limites de taille (alignées code↔spec, Gate 2)** : batch `/catalog/lookup` **max 100 ids** (sinon `400 request_too_large`) ; batch JSON-RPC **MCP max 20 requêtes** (sinon `400`) ; corps de requête **max 256 KiB** (sinon `413`, anti-DoS mémoire — `RequestGuard`). Le rate-limit MCP est **facturé par sous-requête** (un batch ne le contourne pas). **Fail-open** du limiteur (erreur base) : requête laissée passer **et loggée** (BO, sévérité 3).
- **Chemin manifest ultra-léger** (quasi statique + cache) pour absorber la découverte.
- Compteurs stockés en cache/DB PrestaShop ; budget de découverte borné (LRU) côté fetch de profils si signatures activées (`overview.md:1322-1336`).
- Tous les défauts sont **configurables** (produit Pro = réglage fin), mais actifs sans configuration.

### 4.5 (e) Monorepo et package Composer `capability-core`

```
/
├── capability-core/                 ← package Composer autonome, ZÉRO dépendance PrestaShop
│   ├── composer.json                  (name: fondouk/capability-core ; PSR-4 Fondouk\CapabilityCore\ ; php >=8.1)
│   ├── src/
│   │   ├── Graph/                     (DTO immuables : CapabilityGraph, Product, Variant, Money, Media, Availability…)
│   │   ├── Contract/                  (PORTS d'introspection : CatalogSource, ShopProfileSource, Clock…)
│   │   ├── Adapter/
│   │   │   └── Ucp/                   (UcpManifestBuilder, UcpCatalogMapper : Graph → JSON UCP)
│   │   ├── Query/                     (SearchQuery, LookupQuery, GetProductQuery, Pagination)
│   │   └── Support/                   (Money en unités mineures, JsonSchemaValidator [dev], RateLimiter abstrait)
│   └── schema/
│       └── capability-graph.v0.json  ← Blueprint artefact n°1
├── prestashop-module/               ← liaison mince (le produit installable)
│   ├── fondouk.php                  (classe Module : install, hooks moduleRoutes, opt-out)
│   ├── controllers/front/
│   │   ├── ucp.php                    (manifest /.well-known/ucp)
│   │   └── catalog.php                (endpoints REST search/lookup/product ; + mcp.php en S3)
│   ├── src/PrestaShop/
│   │   ├── PrestaShopCatalogSource.php  (implémente Contract\CatalogSource via Product/Combination/StockAvailable/SpecificPrice/ProductPresenter)
│   │   └── PrestaShopShopProfileSource.php
│   ├── composer.json                  (require fondouk/capability-core ; "config": {"prepend-autoloader": false})
│   └── vendor/                        (bundlé, dump-autoload -o --no-dev)
├── docs/
│   ├── TECHNICAL-SPEC.md              (ce document)
│   └── blueprint/                     (adapter-contract.md, discovery-pattern.md, deployment-modes.md)
└── ARCHITECTURE.md                    (point d'entrée du Blueprint)
```

- **`capability-core` n'a AUCUNE dépendance PrestaShop** (cadrage du projet). Dépendances runtime minimales (idéalement zéro) ; un validateur JSON Schema (ex. `opis/json-schema`) reste en `require-dev` pour les tests de conformité.
- **Ports/adapters** : `capability-core` définit `interface CatalogSource { search(SearchQuery): ProductPage; lookup(LookupQuery): Product[]; getProduct(GetProductQuery): ?Product; }`. `prestashop-module` fournit l'implémentation. Le sens de la dépendance va **module → core** uniquement.
- **Composer PrestaShop** : `vendor/` bundlé + `vendor/autoload.php` inclus + **`"config": {"prepend-autoloader": false}`** (obligatoire Addons, §6) ; namespace `PrestaShop\Module\Fondouk` pour la partie module.
- **Licence : MIT partout** (décision Gate 0 Q11) — `capability-core` **et** module : cohérence du Blueprint, simplicité, compatible Addons (cadrage du projet).
- En juillet, le lien `capability-core` ↔ module se fait par **repository Composer de type `path`** (monorepo), avant publication Packagist éventuelle.

---

## 5. Filtre « visible par un visiteur anonyme ? » (anticipation Gate 2)

Règle non négociable (cadrage du projet) : **ce qui est public pour un humain anonyme sur la vitrine est public pour un agent — rien de plus.** L'introspection applique systématiquement :

- **Produit exposé** si et seulement si : `active = 1` ; `visibility ∈ {both, search}` (pas `none`, et `catalog` selon endpoint) ; disponible à la vente selon réglages ; **accessible au groupe visiteur anonyme** (`PS_UNIDENTIFIED_GROUP`) via les accès catégorie/groupe (`ps_category_group`) — un produit réservé à un groupe B2B est **exclu**.
- **Prix** : calculé en **contexte visiteur anonyme** (`getPriceStatic` avec `id_customer=0`, `use_customer_price=false`, `id_group=PS_UNIDENTIFIED_GROUP`). Les **specific_prices restreints à un client ou à un groupe non public ne matchent pas** et sont donc naturellement exclus. **Jamais** de prix client/groupe B2B, **jamais** de wholesale_price, **jamais** de donnée client (cadrage du projet).
- **Stock** : on expose `available`/`status` (booléen + état), **pas la quantité brute** par défaut.
- **Mode catalogue / prix masqués** (décision Gate 0 Q2 — asymétrie assumée) : si `PS_CATALOG_MODE` actif ou `show_price = 0`, le visiteur **voit le produit mais pas son prix**. → **Graphe** : `product.public = true`, `variant.prices = []`. → **Adapter UCP** : produit **exclu** de la réponse (le schéma UCP exige `price_range`). Asymétrie graphe↔adapter documentée au contrat d'adapter (§8).

Ce filtre est implémenté **dans l'introspection (`capability-core` via le port), pas dans l'adapter** : aucun adapter ne peut ré-exposer une donnée filtrée.

---

## 6. Contraintes de validation Addons intégrées dès le départ

Sources : DevDocs `1.7/modules/sell/techvalidation-checklist/`, `8/modules/creation/good-practices/`, `8/modules/concepts/composer/`, `validator.prestashop.com/documentation`, `docs.cloud.prestashop.com/10-validation-checklist/`.

- **Nom** : technique minuscule alphanumérique (`fondouk`) ; nom produit **ne peut pas contenir « PrestaShop »** (marque réglementée) — d'où le titre marketplace « Fondouk — AI Agent Ready (UCP, MCP) » (cadrage du projet). Préfixer clés de config, tables, variables Smarty, CSS.
- **Sécurité** : garde `_PS_VERSION_` en tête de chaque PHP ; stub `index.php` dans chaque dossier ; `.htaccess` racine ; SQL : cast `(int)`, `pSQL()` pour les chaînes, `bqSQL()` pour identifiants ; échapper la sortie Smarty (`|escape:'htmlall':'UTF-8'`) ; contrôleurs AJAX/cron protégés par token ; passer **debug mode ON sans notice/warning** ; pas d'obfuscation.
- **Composer** : bundler `vendor/`, `composer dump-autoload -o --no-dev`, inclure `vendor/autoload.php`, **`prepend-autoloader: false`**, aucun téléchargement externe post-installation (module auto-suffisant). Namespace `PrestaShop\Module\Fondouk`.
- **`/.well-known` / route racine** : **aucune règle Addons ne l'autorise ni l'interdit explicitement** ; modifier les fichiers cœur ou le bloc `# ~~start~~/# ~~end~~` du `.htaccess` est **interdit**. Notre routage repose sur `moduleRoutes` + config serveur (mod_rewrite). **Décision Q6 : poser LES DEUX points à PrestaShop avant soumission** — (i) route `/.well-known/ucp` via `moduleRoutes`, (ii) repli fichier statique (opt-in). Ne PAS présenter une autorisation `.well-known` comme documentée — elle ne l'est pas.
- **Licence** : **MIT** (décision Q11), compatible OSL/AFL/Addons. **RGPD** : implémenter les hooks du module officiel `psgdpr` (`registerGDPRConsent`, `actionExportGDPRData`, `actionDeleteGDPRCustomer`) — pertinent pour la télémétrie locale (§8bis).
- Passer le **validateur** (`validator.prestashop.com`) avant soumission (fin S3, le cadrage du projet).

---

## 7. Mapping complet — capability Catalog UCP ↔ données PrestaShop

Colonnes : **champ UCP** (schéma source) → **source PrestaShop** (classe/méthode/table.champ) → **notes & cas limites**. Montants UCP = entiers en unités mineures.

### 7.1 `product` (`source/schemas/shopping/types/product.json`)

| Champ UCP | Requis | Source PrestaShop | Notes / cas limites |
|---|---|---|---|
| `id` | ✔ | `Product.id` (`product.id_product`) → GID **neutre** `gid://{domaine-boutique}/{id_shop}/product/{id_product}` | GID **sans marque Fondouk** : il appartient au marchand et **survit à une désinstallation** (décision Q9). |
| `handle` | — | `product_lang.link_rewrite` (par langue) | Slug SEO. |
| `title` | ✔ | `product_lang.name` (`Product::name` pour `id_lang`) | **Multilangue** : par langue de présentation, fallback langue défaut. |
| `description` | ✔ | `product_lang.description` (→ `html`) + `description_short` (→ `plain`) | `description.json` exige ≥ 1 format ; **sanitizer le HTML** (le consommateur doit assainir, `description.json`). |
| `url` | — | `Link::getProductLink(id_product, link_rewrite, category, …, id_lang, id_shop)` | URL canonique par langue/boutique. |
| `categories[]` | — | `Product::getCategories()` / `Category` (`category_lang.name`) | `value` = chemin lisible ou id ; `taxonomy:"merchant"`. Pas de `google_product_category` en zéro-config. |
| `price_range` | ✔ | min/max des `variant.price` calculés (§7.2) | Sur produit sans déclinaison : min=max=prix produit. |
| `list_price_range` | — | min/max des `variant.list_price` (prix avant réduction) | Pour l'affichage barré. |
| `media[]` | — | `Image::getImages(id_lang, id_product)` ; URL via `Link::getImageLink(link_rewrite, id_image, type)` ; `alt_text` = `image_lang.legend` | 1er élément = image de couverture (`cover=1`). `type` d'image configurable (ex. `large_default`). |
| `options[]` | — | Groupes d'attributs des déclinaisons : `attribute_group_lang.public_name` + `attribute_lang.name` (via `Combination::getAttributeCombinations`) | Vide si produit sans déclinaison. |
| `variants[]` | ✔ (minItems 1) | Déclinaisons (§7.2) ; **si aucune déclinaison → 1 variant synthétique = le produit** | Voir §7.2 cas « pas de déclinaison ». |
| `rating` | — | Module `ps_productcomments` si présent (`product_comment`) | **Non natif** → exposé seulement si le module est installé, sinon omis (décision Q8). |
| `tags` | — | `product_tag` / `tag_lang.name` | Par langue. |
| `metadata` | — | (libre) | Features produit possibles en v1.1. |

### 7.2 `variant` (`source/schemas/shopping/types/variant.json`)

| Champ UCP | Requis | Source PrestaShop | Notes / cas limites |
|---|---|---|---|
| `id` | ✔ | `product_attribute.id_product_attribute` → GID neutre `gid://{domaine-boutique}/{id_shop}/variant/{id_product}-{id_product_attribute}` (`…-0` si pas de déclinaison) | Sert d'`item.id` au checkout futur (`catalog/index.md:47-49`). Sans marque (Q9). |
| `sku` | — | `product_attribute.reference` sinon `product.reference` | — |
| `barcodes[]` | — | `product_attribute.ean13`/`product.ean13` → `type:"EAN"` ; `upc` → `UPC` ; `isbn` → `ISBN` | **PS9 renomme `ean13`→`gtin` en couche CQRS** ; la colonne DB reste `ean13`. Mapper la colonne DB (décision Q12). |
| `title` | ✔ | Concat. des attributs (`attribute_lang.name`, ex. « Bleu / L ») sinon `product_lang.name` | — |
| `description` | ✔ | Hérite de `product.description` | Le variant exige `description` (schéma) → réutiliser celle du produit. |
| `price` | ✔ | **`Product::getPriceStatic(id_product, usetax=<PS_TAX & include_taxes>, id_product_attribute, decimals, …, id_customer=0, …, use_customer_price=false, id_group=PS_UNIDENTIFIED_GROUP)`** → **unités mineures** = `round(prix * 10^exposant(devise))` | **Contexte anonyme obligatoire** (§5). `usetax` selon réglage d'affichage boutique ; pays fiscal = `context.address_country` sinon `PS_COUNTRY_DEFAULT`, indicatif (Q3). **Multidevise** : devise défaut + `context.currency` si active (Q4). Vide si prix masqué (Q2). |
| `list_price` | — | idem avec `usereduc=false` (ou `getPriceWithoutReduct`) | Prix de référence (barré) = prix avant specific_price. |
| `unit_price` | — | `product.unit_price_ratio`, `product.unity` | Optionnel ; calcul `price / measure * reference` requis par le schéma (`variant.json:78-80`). |
| `availability` | — | `StockAvailable::getQuantityAvailableByProduct(id_product, id_product_attribute, id_shop)` + `StockAvailable::outOfStock(id_product)` + `product.available_for_order` | `available = (qty>0) OU (rupture autorisée)`. `status`: `in_stock` si qty>0 ; `out_of_stock` si qty≤0 et vente en rupture interdite ; `backorder`/`preorder` si autorisée. **Quantité brute non exposée.** |
| `options[]` (selected_option) | — | Attributs de la déclinaison : `name`=`attribute_group.public_name`, `label`=`attribute.name` | — |
| `media[]` | — | `product_attribute_image` (images de la déclinaison) sinon images produit | — |
| `rating`, `tags`, `metadata`, `seller` | — | Optionnels | `seller` non pertinent en v1 (mono-vendeur). |

### 7.3 `/catalog/product` — signaux d'options (`detail_option_value`)

Sur `get_product` avec `selected`/`preferences`, chaque valeur d'option porte `available` (une déclinaison achetable existe pour cette valeur + sélection courante) et `exists` (`detail_option_value.json`). Source : matrice des `Combination` × stock. Le narrowing (`preferences`) relâche les options depuis la fin de la liste (`catalog_lookup.json:103-108`).

### 7.4 Cas limites transverses (exigés par la mission, étape 4)

| Cas limite | Traitement |
|---|---|
| **Déclinaisons** | 1 `variant` UCP par `Combination`. Produit sans déclinaison → 1 variant synthétique `id = {id_product}-0`, options vides. |
| **Prix spécifiques publics vs privés** | Seuls les specific_prices matchant `id_customer=0` + `id_group=PS_UNIDENTIFIED_GROUP` + devise/pays de présentation sont appliqués (via `getPriceStatic`/`getSpecificPrice`). Ceux restreints à un client ou un groupe non public **n'apparaissent jamais** (§5). |
| **Multilangue** | Champs `title/description/handle/url/catégories/legend` résolus par `id_lang` = langue de présentation (depuis `context.language`/`Accept-Language`, `context.json`), fallback langue par défaut. Le graphe stocke **toutes les langues actives** (décision Q5). |
| **Multidevise** | Prix calculé par devise de présentation = `context.currency` **si active dans la boutique**, sinon **devise par défaut** (décision Q4). Conversion via le moteur PrestaShop (`Tools::convertPrice`/contexte devise). Réponse UCP porte `currency` explicite (`price.json`). |
| **Pays / taxe (TTC)** | Le TTC dépend d'un pays. En l'absence de `context.address_country`, hypothèse = `PS_COUNTRY_DEFAULT` (décision Q3), **documentée comme indicative**, non transactionnelle (`catalog/index.md:52-54`). |
| **Mode catalogue / prix masqué** | `PS_CATALOG_MODE` ou `show_price=0` → **graphe** : `public=true`, `prices=[]` (visible sans prix) ; **adapter UCP** : produit exclu (`price_range` requis). Asymétrie assumée (décision Q2, §8). |
| **Stock partagé multiboutique** | `StockAvailable` résolu selon contexte boutique (`Shop::setContext`) ; respecter le partage de stock par groupe de boutiques (config). Lire avec `id_shop` explicite. |
| **Produit non visible** | Filtre §5 (active/visibility/groupe). Exclu du graphe (`public=false` + `visibility_reason`). |
| **Rupture** | `available` reflète la politique de vente en rupture (`out_of_stock`) ; `status` qualifie. |

---

## 8. Contrat d'adapter (esquisse Blueprint artefact n°2)

L'adapter UCP **reçoit** un sous-ensemble matérialisé du `CapabilityGraph` (§4.3) et **produit** : le manifest, et les réponses `search`/`lookup`/`product`. Obligations (à formaliser dans `docs/blueprint/adapter-contract.md`) : **lecture seule**, application du **filtre public** déjà garantie en amont, respect du **versioning de la spec cible**, montants en **unités mineures**, `ucp.version`+`ucp.capabilities` dans chaque réponse, **jamais** de champ interne (`quantity`, wholesale, données client).

**Règle d'asymétrie graphe↔adapter (décision Gate 0 Q2) — à documenter explicitement dans le contrat :** un produit peut être `public=true` dans le graphe **avec `variant.prices = []`** (mode catalogue / prix masqué : le visiteur voit le produit sans prix). L'adapter UCP **DOIT alors exclure ce produit** de `search`/`lookup`/`product`, car le schéma UCP rend `price_range` (et `variant.price`) **obligatoires** (`product.json`, `variant.json`). Le graphe reste donc plus permissif que l'adapter : le graphe modélise « ce que le visiteur voit », l'adapter ne sérialise que « ce que le schéma UCP peut représenter ». Un futur adapter (ex. un flux vitrine sans prix) pourra, lui, exposer ces produits.

C'est le **Gate 1** (schéma du graphe) qui débloque l'écriture de ce contrat.

---

## 8bis. Télémétrie locale (fondation analytics Pro — correction Gate 0 n°3)

Objectif : collecter **dès la v1**, même sans dashboard, les accès agents — fondation du Pro « quelles IA visitent, quels produits » (cadrage du projet). **Strictement locale** : stockée dans la base de la boutique, **aucune exfiltration externe** (cohérent avec la règle Addons « aucun téléchargement/appel externe », §6).

**Table** (unique) : `` `_DB_PREFIX_`fondouk_access_log `` (préfixe boutique via `_DB_PREFIX_`, nom d'actif préfixé conformément à Addons §6). Créée à l'install, supprimée à la désinstallation.

| Champ | Type | Contenu |
|---|---|---|
| `id_access` | INT PK auto | — |
| `date_add` | DATETIME | Horodatage de la requête. |
| `id_shop` | INT | Boutique (multiboutique). |
| `endpoint` | ENUM/VARCHAR | `manifest` \| `search` \| `lookup` \| `product` \| `mcp`. |
| `capability` | VARCHAR | `dev.ucp.shopping.catalog.search` / `.lookup`, ou `null` (manifest). |
| `ip_hash` | CHAR(64) | **SHA-256(IP + sel secret)** — **jamais l'IP en clair** (voir ci-dessous). |
| `ucp_agent_profile` | VARCHAR NULL | Valeur de l'en-tête `UCP-Agent` **si présente** (décision Q10) ; sinon `null`. |
| `user_agent` | VARCHAR NULL | User-Agent HTTP brut (agent identifiant, pas une donnée personnelle client). |
| `id_lang`, `id_currency` | INT NULL | Contexte de présentation résolu. |
| `http_status` | SMALLINT | Code retourné (200, 429, 400…). |
| `response_ms` | INT | Latence de traitement (perf + détection de crawl). |
| `result_count` | INT NULL | Nb de produits renvoyés (search/lookup). |

**Minimisation / RGPD / AI Act** (cadrage du projet) :
- **IP jamais stockée en clair.** `ip_hash = SHA-256(ip || secret)` ; le **sel secret** est généré aléatoirement à l'install et stocké en `Configuration` (`FONDOUK_IP_SALT`), jamais exposé. Le hash est un **pseudonyme non réversible** (résistant au dictionnaire d'IPv4 grâce au sel), permettant le comptage de visiteurs uniques sans conserver d'identifiant direct.
- **Aucune donnée client** : la surface est publique (visiteur anonyme, §5) ; aucun `id_customer`, aucun panier, aucune donnée personnelle client.
- **Rétention par défaut : 90 jours.** Purge automatique (hook cron / `hookActionCronJob` ou tâche à l'appel) supprimant `date_add < NOW() - 90j`. Durée configurable (Pro).
- **Hooks GDPR** du module officiel `psgdpr` implémentés (`§6`) ; l'`ip_hash` étant un pseudonyme, la table reste conforme à la minimisation.
- **Échappement à l'affichage (règle Gate 2 F3, non négociable)** : `user_agent` et `ucp_agent_profile` viennent d'en-têtes contrôlés par le client et sont stockés **bruts** (sûrs en base via `pSQL`, mais potentielle **XSS stockée**). Tout consommateur (dashboard Pro, export, admin) **DOIT** les échapper au rendu (`htmlspecialchars`/Twig) — jamais d'affichage HTML brut. Gravé dans `AccessLogger` avant que le moindre code d'affichage n'existe.
- **Opt-out** : la journalisation peut être désactivée dans l'écran opt-out minimal (S3).

Cette table est la **seule** écriture en base du module côté runtime — elle ne concerne pas les données catalogue (lecture seule stricte, le cadrage du projet).

---

## 8ter. Adapter llms.txt — le pont inter-écosystèmes

**Objectif : résoudre la « découverte de la découverte ».** Chaque écosystème d'agents parle son dialecte — UCP (Google/Gemini), MCP (Claude), crawl web + feeds produit (OpenAI/ChatGPT), crawl + Schema.org (Perplexity). `llms.txt` est la convention **lisible par TOUS** : un agent qui ne connaît pas UCP découvre, en lisant `/llms.txt`, que des interfaces structurées existent et où les trouver. C'est le point d'entrée en langage naturel qui pointe vers les surfaces machine.

**Contenu auto-généré** (zéro-config, par introspection comme le reste, le cadrage du projet) : un fichier Markdown servi à la racine `/llms.txt` déclarant en langage naturel :
- nom de la boutique, langues et devises actives ;
- l'URL du **manifest UCP** (`/.well-known/ucp`) ;
- l'URL du **serveur MCP** ;
- la mention « **catalogue interrogeable en temps réel (prix, stock)** » ;
- un lien vers la **documentation du spec UCP** (`https://ucp.dev/2026-04-08/`).

**Routing** : même mécanisme que le manifest (§3.2/§4.2) — règle `moduleRoutes` `rule => 'llms.txt'` → `ModuleFrontController`. Mêmes contraintes et **mêmes détections réutilisées** : réécriture d'URL (friendly URLs) requise, conflit avec un `llms.txt` physique existant traité par la logique de détection déjà spécifiée (signalement ; repli fichier statique opt-in).

**Priorité** : **v1 si le temps le permet en S3** (coût très faible : ~1 contrôleur + 1 template), sinon **premier item de la v1.1**.

**Couverture par écosystème :**

| Écosystème | Dialecte | Statut Fondouk |
|---|---|---|
| Google / Gemini | UCP | **Couvert v1** (adapter UCP) |
| Claude / MCP | MCP (Streamable HTTP) | **Couvert v1 (S3)** (adapter MCP) |
| OpenAI / ChatGPT | Crawl web + feeds produit | **Partiel** : crawl vitrine inchangé ; **adapter feed = v2** (§10) |
| Perplexity | Crawl + Schema.org | **Hors module** : les thèmes PrestaShop émettent déjà nativement une partie du JSON-LD |
| **Tous** | — | **`llms.txt` comme pont** de découverte |

---

## 9. Tests de conformité (rappel le cadrage du projet)

Chaque réponse d'endpoint sera **validée contre le JSON Schema publié de la release `2026-04-08`** (`catalog_search.json`, `catalog_lookup.json`, types) et testée **sur PS8 (8.2.x) et PS9 (9.1.x)**. Test bout-en-bout : un agent (Claude via MCP fetch / Gemini) découvre `/.well-known/ucp` et interroge le catalogue de la boutique démo (document de cadrage S2).

---

## 10. Ce que ce document NE tranche pas (délégué au Blueprint / semaines suivantes)

- Détail des templates de réponse MCP (S3) et WebMCP (backlog).
- Estimation de frais de port (`Carrier`) — mentionnée dans le spec produit UCP comme enrichissement ; **hors capability Catalog stricte**, à cadrer si le temps le permet.
- Format des `google_product_category` (taxonomie externe) — non zéro-config.
- **Adapter feed produit (écosystème OpenAI/ChatGPT) — v2.**
- **Enrichissement Schema.org JSON-LD des pages vitrine — à évaluer** : les thèmes PrestaShop en émettent déjà une partie ; **mesurer le delta avant de coder**.
- **`FondoukBundle` Sylius/Symfony — post-v1** : implémentation du mode « librairie » du Blueprint (`capability-core` en dépendance Composer d'une app Symfony), **deuxième implémentation de référence** prouvant la portabilité du core. Aucun travail en juillet. *(À citer comme exemple nommé du mode « librairie » dans `docs/blueprint/deployment-modes.md` lors de sa rédaction en S4.)*
- **Formulation multiboutique prudente (règle de claim, Gate 2 suivi n°3)** : tant que le **seed multiboutique intégral** (2 boutiques dans une install, URLs/domaines) n'existe pas, la page Addons et le README **ne revendiquent pas** « compatible multiboutique » ferme. Formulation autorisée : **« conçu pour le multiboutique (scoping `id_shop`), validation intégrale en cours »**. Une promesse ferme non tenue = une étoile en moins ; une promesse honnête = un rapport de bug bienveillant.

---

## 11. Synthèse des décisions

| # | Décision | Choix retenu |
|---|---|---|
| a | Accès données | **Classes internes / ORM + `ProductPresenter`**, contexte visiteur anonyme. Webservice écarté. |
| b | Routing `/.well-known/` | **`moduleRoutes` + `ModuleFrontController` legacy** (portable PS8/PS9) ; endpoints REST/MCP sous base module ; friendly URLs = prérequis détecté ; repli fichier statique **opt-in** ; les deux points à valider par Addons. |
| c | Capability Graph v0 | **Schéma pivot neutre** (`capability-core/schema/capability-graph.v0.json`), multilangue + multidevise, mappe vers UCP `product`/`variant`. |
| d | Cache + rate limiting | Manifest `public,max-age=300`+ETag ; cache applicatif TTL 300 s invalidé par hooks produit ; **token-bucket 60 req/min/IP**, 429+Retry-After ; `limit` max 50. |
| e | Monorepo + Composer | `capability-core/` (sans dépendance PS, `fondouk/capability-core`, ports/adapters) + `prestashop-module/` (liaison mince, `prepend-autoloader:false`) + `docs/blueprint/`. Licence **MIT partout** (core + module, décision Q11). |

---

## 12. Décisions Gate 0 — validées (2026-07-04)

Les 12 questions fermées de la Phase 0 ont été tranchées par le mainteneur. La spec ci-dessus a été **mise à jour en conséquence**. Récapitulatif :

| # | Décision retenue | Sections mises à jour |
|---|---|---|
| Q1 | **Cibler la release stable `2026-04-08`** ; valider contre ses schémas publiés. | §0, §2.1, §9 |
| Q2 | **Asymétrie assumée.** Graphe : `public=true` + `prices=[]` (le visiteur anonyme VOIT le produit en mode catalogue, pas son prix). Adapter UCP : **exclusion** (car `price_range` requis). Documentée au contrat d'adapter. | §4.3, §5, §7.4, §8 |
| Q3 | **`PS_COUNTRY_DEFAULT`** comme hypothèse fiscale, **documentée comme indicative**. | §7.2, §7.4 |
| Q4 | **Devise défaut + résolution `context.currency`** si la devise est active. | §7.2, §7.4 |
| Q5 | **Toutes les langues actives.** | §4.3, §7.4 |
| Q6 | **Friendly URLs = prérequis détecté et signalé** ; jamais d'écriture `.htaccess`. Repli fichier statique `.well-known` = **opt-in explicite, désactivé par défaut**. **Poser LES DEUX points** (route `.well-known` + repli statique) à PrestaShop avant soumission. | §4.2, §6 |
| Q7 | **Manifest seul sous `/.well-known/`** ; endpoints sous base module. | §2.1, §4.2 |
| Q8 | **`rating` exposé seulement si `ps_productcomments` présent.** | §7.1 |
| Q9 | Structure OK, mais **GID neutre SANS marque** : `gid://{domaine-boutique}/{id_shop}/product/{id}`. L'identifiant appartient au marchand et **survit à une désinstallation de Fondouk**. | §4.3, §7.1, §7.2 |
| Q10 | **Lecture publique non signée**, protection par rate-limit ; **logger `UCP-Agent`** quand présent (télémétrie). | §4.4, §8bis |
| Q11 | **MIT partout** (core + module). | §4.5, §6 |
| Q12 | **Colonne DB `ean13` suffit en v1.** | §7.2 |

**Corrections appliquées avant Gate 1 :**
1. **Sémantique du graphe** = modèle logique matérialisé par sous-ensembles → schéma v0 corrigé (bloc `subset`, `products` = sous-ensemble 0..N). §4.3.
2. **Repli statique `.well-known`** = opt-in explicite, désactivé par défaut. §4.2, §6.
3. **Spec de télémétrie locale** ajoutée (données, table préfixée, IP hashée salée, rétention 90 j, RGPD). §8bis.

---

**Gate 0 : VALIDÉ.** Livrable Phase 0 clos et à jour. **Aucun code de production écrit.**

**Prochaine étape : Gate 1** = validation du JSON Schema v0 du Capability Graph (§4.3) avant l'écriture des adapters. En attente du feu vert du mainteneur pour démarrer l'implémentation (squelette module + `capability-core` + introspection), conformément à la roadmap Semaine 1 (cadrage du projet).
