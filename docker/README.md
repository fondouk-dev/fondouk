# Environnement Docker — Fondouk (Semaine 1)

Deux boutiques PrestaShop indépendantes pour développer et tester les adapters sur PS8 **et** PS9.

| Stack | Version | PHP | URL vitrine | Back-office | DB (host) |
|---|---|---|---|---|---|
| `ps8` | PrestaShop 8.2.x | 8.1 | http://localhost:8081 | http://localhost:8081/admin-dev | `127.0.0.1:33061` |
| `ps9` | PrestaShop 9.1.x | 8.2 | http://localhost:8091 | http://localhost:8091/admin-dev | `127.0.0.1:33091` |

Identifiants BO par défaut : `admin@fondouk.test` / `Fondouk2026` (voir `.env`).

## Démarrer

```bash
cp .env.example .env          # optionnel (des défauts existent)
docker compose up -d          # 1re fois : ~2-4 min d'auto-install par boutique
docker compose logs -f ps8    # suivre l'install
```

La boutique est prête quand `http://localhost:8081` répond (HTTP 200/302) et que le
dossier `install-dev` a été retiré automatiquement par l'entrypoint.

## Seed du catalogue de test (rejouable)

Le seed crée un catalogue réaliste couvrant les cas de la spec (déclinaisons, rupture,
specific_price public, prix restreint B2B, multi-langue fr/en, multi-devise EUR/USD).

```bash
docker compose exec ps8 php -d memory_limit=-1 /seed/seed.php
docker compose exec ps9 php -d memory_limit=-1 /seed/seed.php
```

Le script est **idempotent** : il identifie ses objets par `reference` préfixée `AGT-`
et les recrée proprement à chaque exécution.

## Module Fondouk (dev)

Le module (`prestashop-module/`, monté dans les conteneurs sous `modules/fondouk`)
embarque `capability-core` via un dépôt Composer `path`. En dev, générer le `vendor/`
du module une fois (copie `capability-core` dedans) :

```bash
docker run --rm -e COMPOSER_ALLOW_SUPERUSER=1 -v "$PWD":/repo -w /repo/prestashop-module \
  --entrypoint bash prestashop/prestashop:8.2-8.1 -lc \
  'php -r "copy(\"https://getcomposer.org/installer\",\"/tmp/ci\");" && php /tmp/ci --install-dir=/usr/local/bin --filename=composer -q && composer install'
```

Puis installer le module sur chaque boutique :

```bash
docker compose exec ps8 php bin/console prestashop:module install fondouk
docker compose exec ps9 php bin/console prestashop:module install fondouk
docker compose exec ps8 php -d memory_limit=-1 /seed/seed.php   # active aussi les URL simplifiées
docker compose exec ps9 php -d memory_limit=-1 /seed/seed.php
```

Le manifest est alors servi sur `http://localhost:8081/.well-known/ucp` et `:8091`.

> **Note PS9** : les modules marketplace pré-installés (`ps_accounts`/`ps_checkout`)
> peuvent fataler dans `actionFrontControllerSetMedia` si `var/cache` n'est pas
> inscriptible. Nos endpoints y sont insensibles (ils surchargent `setMedia()`), mais
> pour un front-office sain : `docker compose exec -u root ps9 chown -R www-data:www-data var && docker compose exec -u root ps9 rm -rf var/cache/*`.

## Développement — après édition d'un fichier PHP du module

L'image active **OPcache** : une édition d'un contrôleur/classe du module n'est pas prise
en compte tant qu'OPcache n'est pas vidé. Et un `restart` remet le cache PrestaShop
(`var/cache`) en propriété root → erreurs « Cannot rename … var/cache ». La séquence sûre :

```bash
docker compose restart ps8 ps9
for S in ps8 ps9; do docker compose exec -u root $S bash -lc 'rm -rf var/cache/* && chown -R www-data:www-data var'; done
```

## Preuves (reproductibles)

```bash
python3 scripts/agent-proofs.py        http://localhost:8081 http://localhost:8091  # découverte + filtre B2B
python3 scripts/hardening-proofs.py    http://localhost:8081 http://localhost:8091  # rate-limit, cache, pagination
bash    scripts/proof-cache-invalidation.sh ps8 http://localhost:8081               # invalidation par hook
python3 scripts/mcp-proofs.py           http://localhost:8081 http://localhost:8091  # serveur MCP (JSON-RPC)
```

## Réinitialiser

```bash
docker compose down -v         # supprime les volumes (DB + fichiers PS) → install propre au prochain up
```
