# Fondouk — prêt pour les agents IA sur PrestaShop (UCP, MCP)

🌍 [English](README.md) · **Français** · [العربية](README.ar.md)

**Rendez une boutique PrestaShop découvrable et interrogeable par les agents IA — catalogue, prix et stock en temps réel. Un clic, zéro configuration.** Fondouk implémente fidèlement des standards ouverts publiés — [UCP](https://ucp.dev) et [MCP](https://modelcontextprotocol.io) ; il n'invente aucun protocole propre.

[![CI](https://github.com/fondouk-dev/fondouk/actions/workflows/ci.yml/badge.svg)](https://github.com/fondouk-dev/fondouk/actions/workflows/ci.yml)
![license](https://img.shields.io/badge/license-MIT-blue)
![prestashop](https://img.shields.io/badge/PrestaShop-8.2%20%7C%209.1-e2467d)
![ucp](https://img.shields.io/badge/UCP-2026--04--08-5b8def)
![status](https://img.shields.io/badge/read--only-strict-informational)

## Pourquoi « Fondouk »

Un *fondouk* était le caravansérail où l'on hébergeait les marchands de passage — le lieu où une ville **accueillait les agents** venus de la route. Un nom juste pour un module qui ouvre la vitrine aux agents IA. Le nom est la métaphore : votre boutique, hospitalière envers quiconque vient commercer.

## Ce que ça fait

Quand un client demande à ChatGPT, Gemini ou Claude *« où puis-je acheter ça ? »*, une boutique qui ne parle pas aux agents ne peut pas être interrogée en temps réel — pas de prix à jour, pas de stock, pas de réponses structurées. Fondouk corrige ça :

- Sert un **manifest de découverte UCP** à `/.well-known/ucp`.
- Expose la capability **Catalog** (search / lookup / product) via **REST** et **MCP**.
- Tout est généré par **introspection** de la boutique — catalogue, déclinaisons, stock, prix publics, langues, devises. Aucun écran de réglages.
- **Lecture seule et public uniquement** : ce qu'un visiteur anonyme voit sur la vitrine est ce qu'un agent voit. Rien de plus. Pas de données clients, pas de prix B2B/groupe, pas de panier, pas de paiement.

## Démarrage rapide — marchand

1. Téléchargez le zip du module (`fondouk.zip`) depuis la release, ou construisez-le : `MODULE_NAME=fondouk ./build/package.sh`.
2. Dans le back-office PrestaShop : **Modules → Envoyer un module →** déposez le zip.
3. C'est tout. Le manifest est en ligne à `https://votre-boutique/.well-known/ucp`.

Prérequis : PrestaShop **8.2** ou **9.1**, URL simplifiées (réécriture d'URL) activées, HTTPS. L'écran du module affiche un diagnostic d'environnement (réécriture d'URL, HTTPS, conflits `.well-known`).

## Démarrage rapide — agent

Découvrir, puis interroger — l'endpoint est lu dans le manifest, jamais codé en dur :

```bash
# 1. Découvrir
curl -s https://demo.fondouk.dev/.well-known/ucp | jq '.ucp.services."dev.ucp.shopping"'

# 2. Rechercher dans le catalogue (endpoint lu dans le manifest)
curl -s -X POST https://demo.fondouk.dev/fondouk/ucp/catalog/search \
  -H 'Content-Type: application/json' \
  -d '{"query":"t-shirt","pagination":{"limit":2}}' | jq '.products[].title'
```

MCP (JSON-RPC, Streamable HTTP) expose le même catalogue via les outils `search_catalog`, `get_product`, `lookup_catalog` :

```bash
curl -s -X POST https://demo.fondouk.dev/fondouk/ucp/mcp \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | jq '.result.tools[].name'
```

## Architecture

Un design **cœur + adapters** découplé. Le cœur est agnostique du framework (zéro dépendance PrestaShop) et produit un *Capability Graph* neutre ; les adapters le projettent dans chaque protocole.

```
┌──────────────────────────────────────────────┐
│  capability-core  (Fondouk\CapabilityCore)     │
│  DTOs · ports (CatalogSource, ShopProfile)     │
│  Capability Graph (JSON Schema versionné)      │
│  Adapter/Ucp : manifest + mapper catalogue     │
└───────────────┬──────────────────────────────┘
                │ implémente les ports
┌───────────────▼──────────────────────────────┐
│  prestashop-module  (liaison fine)             │
│  introspection via Product/Combination/        │
│  StockAvailable/SpecificPrice + Presenter      │
│  contrôleurs : /.well-known/ucp, REST, MCP     │
└──────────────────────────────────────────────┘
```

Les prix sont calculés en **contexte visiteur anonyme** (`getPriceStatic` avec le groupe invité, sans client) : les prix spécifiques publics s'appliquent, les prix B2B/groupe jamais. Le même graphe alimente REST et MCP, qui exposent donc les mêmes données catalogue, validées contre les schémas UCP publiés.

Durcissement : rate limiting token-bucket (60/min, burst 120) + `Retry-After`, cache de réponses invalidé par les hooks produit, plafonds de taille de requête et de batch, télémétrie locale (IP hachée, jamais stockée en clair).

## Le Blueprint

Fondouk est aussi un **playbook copiable** pour rendre *n'importe quelle* plateforme de commerce lisible par les agents — le module PrestaShop est l'implémentation de référence n°1. Voir [`ARCHITECTURE.md`](ARCHITECTURE.md) et [`docs/blueprint/`](docs/blueprint/) : le schéma du Capability Graph, le contrat d'adapter, le pattern de découverte, et les modes de déploiement (embarqué / librairie / proxy). Le Blueprint est un **playbook d'implémentation des standards existants (UCP, MCP)** — jamais une spec concurrente.

## Feuille de route

- **v1 (maintenant) — gratuit, à vie :** manifest UCP + découverte Catalog (REST + MCP), `llms.txt`, lecture seule, PS 8/9. Rendre votre boutique *visible* pour les agents est l'identité du module — et ça reste gratuit.
- **v2 — couche transactionnelle (Panier / Paiement, AP2) : partie de Fondouk Pro (payant).** Quand un agent passe de *lire* à *acheter*, la couche payante commence — on paie quand l'argent circule, pas pour être vu. Fondouk Pro (automne) l'associe à l'observabilité (tableau de bord, attribution), au contrôle par agent, et aux journaux d'audit / conformité.
- **Portabilité :** `FondoukBundle` pour Sylius/Symfony (mode librairie) — deuxième implémentation de référence prouvant que le cœur se transpose.

## Contribuer

Issues et PR bienvenues. Le cœur porte un JSON Schema versionné et une suite de tests (`composer test` dans `capability-core/`) ; tout adapter de protocole doit respecter le contrat d'adapter (lecture seule, filtre public, versionnage). Voir `docs/blueprint/adapter-contract.md`.

## Sécurité

Une vulnérabilité ? Merci de la signaler en privé — voir [`SECURITY.md`](SECURITY.md) (`security@fondouk.dev`). N'ouvrez pas d'issue publique.

## Licence

MIT — voir [`LICENSE`](LICENSE). Un projet Synapsea.
