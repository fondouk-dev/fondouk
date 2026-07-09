# Les modes de déploiement — Blueprint artefact n°4

> Le même `capability-core` (sans dépendance framework) se déploie de trois façons. Une seule est codée en v1 (le module PrestaShop) ; l'architecture rend les deux autres possibles **sans réécriture** — d'où l'interdiction absolue de dépendances PrestaShop dans le cœur.

## Pourquoi trois modes

`capability-core` définit des **ports** (`CatalogSource`, `ShopProfileSource`) et produit un **Capability Graph** neutre. Ce qui change d'un mode à l'autre, c'est seulement **qui implémente les ports** et **où tourne le cœur**.

## 1. Module embarqué — *codé en v1 (référence n°1)*

Le cœur est embarqué dans l'application, qui implémente les ports avec ses propres classes.

- **Exemple livré** : le module PrestaShop. `PrestaShopCatalogSource` implémente `CatalogSource` via `Product`/`Combination`/`StockAvailable`/`SpecificPrice` + le presenter ; les contrôleurs front servent le manifest, REST et MCP.
- **Quand** : on contrôle le code de la plateforme et on veut une intégration native (zéro config, introspection directe).

## 2. Librairie — *post-v1, référence n°2 prévue*

Le cœur est une **dépendance Composer** d'une application existante ; l'app implémente les ports contre son propre modèle de données et monte les adapters sur ses routes.

- **Exemple nommé : `FondoukBundle` pour Sylius/Symfony.** Sylius (e-commerce Symfony) fournit un `SyliusCatalogSource` (Doctrine/API Platform) ; le bundle expose `/.well-known/ucp` et les endpoints via des contrôleurs Symfony. Deuxième implémentation de référence **prouvant que le cœur se transpose** sans réécriture.
- **Quand** : application maison ou framework e-commerce (Sylius, API Symfony) où l'on ajoute la surface agentique en dépendance.

## 3. Proxy / gateway autonome — *post-v1*

Le cœur tourne **devant** un système intouchable : il se branche sur l'API existante (implémentant `CatalogSource` par des appels HTTP à cette API) et expose la surface agentique **à sa place**, sans modifier l'application cible.

- **Quand** : plateforme SaaS fermée, legacy non modifiable, ou l'on veut découpler la surface agentique du cycle de release du produit.
- **Condition architecturale** : le cœur **ne doit jamais** dépendre de PrestaShop (ni d'aucun framework) — sinon ce mode est impossible. C'est la raison d'être de la séparation physique `capability-core/` ↔ `prestashop-module/`.

## Invariant commun

Quel que soit le mode, les **6 obligations** du contrat d'adapter tiennent (lecture seule, filtre public, versioning, unités mineures, pas de champ interne, manifest honnête), et le **Capability Graph** est le seul point de contact entre la plateforme et les adapters.

| Mode | Qui implémente les ports | Où tourne le cœur | Statut |
|---|---|---|---|
| Embarqué | La plateforme (classes internes) | Dans l'app | **Codé (PrestaShop)** |
| Librairie | L'app hôte (son modèle) | Dans l'app hôte | Prévu (Sylius `FondoukBundle`) |
| Proxy | Un adaptateur HTTP vers l'API cible | À côté, autonome | Prévu |
