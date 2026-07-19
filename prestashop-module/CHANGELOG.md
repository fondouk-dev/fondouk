# Changelog — Fondouk (gratuit)

Toutes les modifications notables de ce module sont consignées ici.
Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) ;
versionnage [SemVer](https://semver.org/lang/fr/).

> **Règle projet** : avant toute nouvelle version, mettre à jour ce fichier
> (nouveau numéro + changements) **puis** bumper `$this->version` dans `fondouk.php`.

## [1.2.6] — 2026-07-19
### Modifié
- Documentation : README (EN/FR/AR) — section « Fondouk Pro » (open-core), badge et
  prérequis de compatibilité **8.0 – 9.2**, feuille de route réalignée (Pro = analytics,
  disponible ; checkout = backlog). Aucun changement fonctionnel du module.

## [1.2.5] — 2026-07-17
### Corrigé
- **Cohabitation** : la désinstallation du gratuit ne détruit plus la table de
  télémétrie partagée (`fondouk_access_log`/`fondouk_rate_limit`) ni le salt IP /
  compteur de version **lorsque Fondouk Pro est installé** — il s'en sert encore.
  Auparavant, désinstaller le gratuit dans une boutique free+Pro effaçait la
  télémétrie et le salt du Pro. Garde : `if (Module::isInstalled(PRO_MODULE)) return parent::uninstall();`.
### Validé
- Compatibilité étendue vérifiée sur **PrestaShop 8.0.5 et 9.2.0** (3 harnais
  ALL_PASS, cohabitation 4 états + migration 22/22 chacune).

## [1.2.4] — 2026-07-16
### Modifié
- Conformité Code Style Addons : `declare(strict_types=1)`, `echo` → `$this->ajaxRender()`
  sur les 4 contrôleurs front, et `fully_qualified_strict_types` (import + nom court).

## [1.2.3] — 2026-07-15
### Sécurité
- Durcissement : remplacement des superglobales brutes (`$_SERVER`, `php://input`)
  par `Symfony\Component\HttpFoundation\Request` (faux positifs SQL_Injection du
  validateur Addons ; aucune n'atteignait de requête).

## [1.2.2] — 2026-07-14
### Corrigé
- Conformité validateur Addons : garde `_PS_VERSION_` remontée avant tout code
  exécutable, `array()` → `[]` dans les traductions, et rendu HTML du Diagnostic
  (bug d'affichage des balises `{l}`).

## [1.2.1] — 2026-07-13
### Ajouté
- **Passerelle GET** (commodité non-normative) : `GET /catalog/{search?q=,product?id=,lookup?ids=}`
  et entrée nue `GET /fondouk/ucp?capability=&query=`, pour les navigateurs agentiques.
  Rate-limit / cache / télémétrie identiques au transport POST canonique.

## [1.2.0] — 2026-07-13
### Ajouté
- **Coexistence polie** avec Fondouk Pro : quand le Pro est installé et actif, le
  gratuit défère toute la surface agent (`hookModuleRoutes` renvoie `[]`) ;
  désinstaller le Pro fait reprendre le gratuit automatiquement, historique intact.

## [1.1.0] — 2026-07-12
### Ajouté
- Version initiale suivie du module gratuit (surface agent UCP/MCP en lecture seule :
  manifeste `/.well-known/ucp`, catalogue REST, serveur MCP, `llms.txt` ;
  télémétrie des agents, IP hachées SHA-256).
