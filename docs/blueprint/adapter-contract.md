# Le contrat d'adapter — Blueprint artefact n°2

> Ce qu'un adapter reçoit, ce qu'il produit, ses obligations. Extrait du code réel (`capability-core/src/`). **Playbook d'implémentation** des standards (UCP, MCP), pas une spec concurrente : le jour où UCP/MCP définit un concept, on pointe vers eux.

## Le principe

Toute plateforme qui sait produire un **Capability Graph** (artefact n°1) hérite gratuitement de tous les adapters. Un adapter est une fonction pure : *Capability Graph (sous-ensemble matérialisé) → surface d'un protocole*.

```
CatalogSource (port, implémenté par la plateforme)
        │  produit un CapabilityGraph
        ▼
   Capability Graph  ── UcpManifestBuilder ──▶  /.well-known/ucp
   (neutre, versionné) ─ UcpCatalogMapper ───▶  REST search/lookup/product
                       ─ (MCP) ──────────────▶  tools/call (mêmes réponses)
```

## Ce que l'adapter REÇOIT

Les **ports** (`capability-core/src/Contract/`) — la plateforme les implémente, l'adapter ne dépend que d'eux :

- **`CatalogSource`** — `search(SearchQuery)`, `lookup(LookupQuery)`, `getProduct(GetProductQuery)`, chacun renvoyant un `CapabilityGraph` (sous-ensemble matérialisé, qualifié par `Subset`). `getProduct` renvoie `null` si l'identifiant ne résout aucun produit **public**.
- **`ShopProfileSource`** — `shop()` (identité + langues/devises), `enabledTransports()`, `enabledCapabilities()`.

Le graphe est un **modèle logique jamais matérialisé en entier** : une instance est une page de recherche, un batch, ou une fiche.

## Ce que l'adapter PRODUIT

- **Manifest** (`UcpManifestBuilder`) : profil business UCP servi à `/.well-known/ucp`.
- **Réponses catalogue** (`UcpCatalogMapper`) : `search_response`, `lookup_response`, `get_product_response`, conformes aux schémas **publiés** de la version cible (Fondouk : `2026-04-08`).

## Obligations (non négociables)

1. **Lecture seule.** Aucune écriture, aucun panier, aucun checkout.
2. **Filtre public appliqué EN AMONT** (dans le port, pas l'adapter) : rien qu'un visiteur anonyme ne verrait. Aucun adapter ne peut ré-exposer une donnée filtrée.
3. **Jamais de champ interne.** La quantité brute (`availability.quantity` du graphe) n'est **jamais** sérialisée ; le spec expose `available`/`status`. Aucun prix client/groupe, aucune donnée client.
4. **Montants en unités mineures** (centimes, ISO 4217), devise explicite.
5. **Versioning de la spec cible.** Chaque réponse porte `ucp.version` + les `capabilities` actives ; validée contre les schémas publiés de cette version.
6. **Véracité du manifest — « a manifest is a promise ».** Ne déclarer que des transports/capabilities **opérationnels**. Un endpoint en stub n'apparaît pas. Manifest vide > manifest menteur. (Détail : `discovery-pattern.md`.)

## La règle d'asymétrie graphe ↔ adapter (décision Q2)

Le graphe est **plus permissif** que l'adapter, par conception :

- Un produit peut être `public: true` avec `variants[].prices = []` (mode catalogue / prix masqué : le visiteur **voit** le produit sans son prix).
- L'adapter UCP **DOIT alors exclure ce produit** de `search`/`lookup`/`product`, car le schéma UCP rend `price_range` (et `variant.price`) **obligatoires**.

Le graphe modélise *« ce que le visiteur voit »* ; l'adapter ne sérialise que *« ce que le schéma cible peut représenter »*. Un futur adapter (flux vitrine sans prix) pourra, lui, exposer ces produits — sans changer le graphe ni le port.

## Ajouter un protocole (contributeur externe)

1. Consommer un `CapabilityGraph` (les DTO de `src/Graph/`).
2. Mapper vers la surface du protocole cible en respectant les 6 obligations.
3. Déclarer le transport/capability dans `ShopProfileSource` **seulement une fois l'endpoint prouvé** (véracité).
4. Valider les réponses contre les schémas publiés du protocole. Tests obligatoires.

Aucune modification du cœur ni de la plateforme n'est requise : c'est tout l'intérêt du pivot.
