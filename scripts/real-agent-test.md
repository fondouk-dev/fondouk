# Protocole — test avec un agent RÉEL (Gate S4)

> Objectif : prouver qu'un agent tiers (Claude, Gemini), sans connaissance préalable de la boutique, **découvre** le manifest sur `https://demo.fondouk.dev/.well-known/ucp` puis **enchaîne une recherche** — le tout en HTTPS public. C'est la réserve honnête ouverte depuis le checkpoint des 3 preuves (le chemin protocolaire est prouvé ; il manquait l'agent externe réel en HTTPS).

## Pré-requis
- Démo déployée et vérifiée (`docs/demo-deployment.md`) : `https://demo.fondouk.dev/.well-known/ucp` renvoie un manifest dont l'`endpoint` est public.

## A. Claude (via l'outil de fetch web / MCP fetch)
1. Prompt neutre, sans donner l'URL de l'endpoint : *« Cette boutique expose une interface de commerce agentique. Découvre ses capacités depuis https://demo.fondouk.dev et trouve-moi deux t-shirts. »*
2. Attendu : l'agent **fetch** `https://demo.fondouk.dev/.well-known/ucp`, lit `services["dev.ucp.shopping"][transport=rest].endpoint` et les `capabilities`, puis appelle `POST {endpoint}/catalog/search`.
3. **Archiver** : le transcript complet (prompt → fetch manifest → appel search → réponse) dans votre archive de preuves + capture d'écran.

## B. Gemini (ou un autre agent navigateur/UCP)
Même protocole. Archiver dans votre archive de preuves.

## C. Contrôle « client MCP » (reproductible, non-LLM)
Rejouer le harnais de découverte contre la démo publique :
```bash
python3 scripts/agent-proofs.py https://demo.fondouk.dev
python3 scripts/mcp-proofs.py   https://demo.fondouk.dev
```
Archiver la sortie dans votre archive de preuves.

## Points à observer (ce que le test réel peut révéler, cf. finding tunnel)
- L'`endpoint` du manifest est-il bien le domaine **public** (pas localhost) ? *(dépend de PS_SHOP_DOMAIN)*
- Un `User-Agent` d'agent est-il bloqué quelque part (WAF, hébergeur, PrestaShop) ?
- Le comportement du fetch sur `/.well-known/` (redirections, cache, CORS) est-il correct côté agent réel ?
- Les descriptions d'outils MCP sont-elles assez claires pour que l'agent choisisse le bon outil ?

## Critère de succès
Au moins **un agent réel** (A ou B) découvre le manifest et renvoie des produits réels de la démo, transcript archivé dans votre archive de preuves. Le contrôle C doit être vert.
