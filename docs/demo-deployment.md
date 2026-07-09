# Déploiement de la démo publique HTTPS

> Boutique de démonstration interrogeable par n'importe quel agent : `https://demo.fondouk.dev`. Une seule boutique PS9 derrière Caddy (TLS automatique). `docker-compose.demo.yml` + `docker/demo/Caddyfile`.

## Prérequis
- Un serveur avec Docker + les ports **80** et **443** ouverts.
- Un domaine public **`demo.fondouk.dev`** dont le DNS (A/AAAA) pointe vers l'IP du serveur. *(Let's Encrypt exige un domaine résolvable ; le certificat est émis automatiquement par Caddy.)*

## Procédure
```bash
# 1. Cloner le repo sur le serveur, puis :
docker compose -f docker-compose.demo.yml up -d        # Caddy + MariaDB + PS9 (auto-install ~3 min)

# 2. Seed du catalogue de démo
docker compose -f docker-compose.demo.yml exec ps9 php -d memory_limit=-1 /seed/seed.php

# 3. Installer le module
docker compose -f docker-compose.demo.yml exec ps9 php bin/console prestashop:module install fondouk
```

## Points critiques (finding tunnel — checkpoint S3)
Le manifest **s'auto-décrit avec le domaine configuré de la boutique**, pas le Host de la requête. Pour que l'`endpoint` annoncé soit `https://demo.fondouk.dev/...` (et non `localhost`), il faut :

1. **`PS_DOMAIN=demo.fondouk.dev` + `PS_ENABLE_SSL=1`** (déjà dans le compose) → à l'install, PrestaShop stocke `PS_SHOP_DOMAIN` et `PS_SHOP_DOMAIN_SSL` = `demo.fondouk.dev`.
2. **Vérifier après install** (Paramètres avancés → ou en base) que `PS_SHOP_DOMAIN` / `PS_SHOP_DOMAIN_SSL` valent bien `demo.fondouk.dev` :
   ```bash
   docker compose -f docker-compose.demo.yml exec ps9 php -r \
     'define("_PS_ROOT_DIR_","/var/www/html");require "/var/www/html/config/config.inc.php";
      echo Configuration::get("PS_SHOP_DOMAIN")." / ".Configuration::get("PS_SHOP_DOMAIN_SSL").PHP_EOL;'
   ```
   Si besoin : `UPDATE ps_configuration SET value="demo.fondouk.dev" WHERE name IN ("PS_SHOP_DOMAIN","PS_SHOP_DOMAIN_SSL");`
3. **Trusted proxy** : PS9 est derrière Caddy (TLS terminé par Caddy). Pour que PrestaShop traite la requête comme HTTPS, Caddy envoie `X-Forwarded-Proto: https` (voir Caddyfile). Si des boucles de redirection HTTP↔HTTPS apparaissent, déclarer le proxy de confiance côté PrestaShop (config `_PS_TRUSTED_PROXIES_` ou réglage SSL BO « Activer SSL sur toutes les pages »).
4. **Friendly URLs** : activées par le seed (prérequis du routage `/.well-known/`).

## Vérification post-déploiement
```bash
curl -s https://demo.fondouk.dev/.well-known/ucp | jq '.ucp.services."dev.ucp.shopping"[].endpoint'
#   → doit renvoyer "https://demo.fondouk.dev/fondouk/ucp" (PAS localhost)
curl -s -X POST https://demo.fondouk.dev/fondouk/ucp/catalog/search \
     -H 'Content-Type: application/json' -d '{"query":"t-shirt","pagination":{"limit":2}}' | jq '.products | length'
```
Puis dérouler `scripts/real-agent-test.md` (test agent réel) et archiver les preuves de votre côté.

## Sécurité de la démo
- Mot de passe admin **à changer** (`ADMIN_PASSWD`), dossier admin renommé (`admin-demo`).
- Lecture seule stricte (le module n'expose que le public) ; rate limiting actif.
- `PS_DEV_MODE=0` (prod) → cache compilé, pas de fuite de trace.
