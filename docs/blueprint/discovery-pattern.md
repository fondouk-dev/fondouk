# Pattern de découverte — Blueprint artefact n°3

> Playbook des conventions `/.well-known/` et de la génération de manifest par introspection. Extrait du module PrestaShop (implémentation de référence n°1). En cours ; complété en S4.

## Principe fondateur — *a manifest is a promise*

Un manifest de découverte (UCP `/.well-known/ucp`, `llms.txt`, …) est généré **par introspection** : il décrit **ce qui EST, pas ce qui est prévu**.

**Règle non négociable : ne déclarer que des capabilities opérationnelles.** Tant qu'un endpoint est un stub ou désactivé, sa capability — et son transport s'il n'en porte aucune autre — **n'apparaît pas** dans le manifest (`services`/`capabilities` valent `{}`).

Pourquoi : un agent lit le manifest comme un **contrat**. S'il y trouve `catalog.search` puis reçoit un `501`, il considère la boutique cassée — pire qu'une boutique qui n'annonce rien. Le jour d'une démo publique ou d'un test agent réel, **un manifest menteur coûte plus cher qu'un manifest vide**.

Mise en œuvre (référence PrestaShop) :
- `Contract\ShopProfileSource::enabledCapabilities()` est la **source de vérité** : elle ne renvoie que les capabilities dont l'endpoint est réellement câblé.
- `Adapter\Ucp\UcpManifestBuilder` ne sérialise que ces transports/capabilities ; sinon `{}`.
- Corollaire : activer une capability = un seul endroit à changer, une fois l'endpoint testé.

## Défauts de sécurité de la découverte

- **HTTPS obligatoire** pour le manifest (exigence du spec UCP). Base URL non-HTTPS → détectée et signalée, jamais annoncée comme conforme.
- **Pas de redirection 3xx** sur le manifest ; `Cache-Control: public, max-age ≥ 60` (Fondouk : 300) + `ETag`/`304`.
- **CORS ouvert** en lecture (`Access-Control-Allow-Origin: *`) pour permettre la découverte.
- **Filtre public** appliqué en amont (rien qu'un visiteur anonyme ne verrait — voir `adapter-contract.md`).

## Routing `/.well-known/` (résumé — détail spec §3.2/§4.2)

- Mécanisme portable PS8/PS9 : hook `moduleRoutes` → `ModuleFrontController` (le front-office n'est pas Symfony).
- Prérequis : réécriture d'URL activée ; détecter le **conflit `.well-known` physique** (Apache sert les fichiers existants et court-circuite la route).
- Manifest seul sous `/.well-known/` ; endpoints REST/MCP sous une base module.
