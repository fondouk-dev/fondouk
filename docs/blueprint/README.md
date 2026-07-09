# Blueprint Fondouk — guide de standardisation

> Les 4 artefacts copiables (le Blueprint du projet), **extraits du travail réel**. Point d'entrée public : [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md).

1. **Le schéma du Capability Graph** — [`../../capability-core/schema/capability-graph.v0.json`](../../capability-core/schema/capability-graph.v0.json) *(v0, Gate 1 clos)*.
2. **Le contrat d'adapter** — [`adapter-contract.md`](adapter-contract.md) *(ce que reçoit/produit un adapter, ses 6 obligations, l'asymétrie graphe↔adapter)*.
3. **Le pattern de découverte** — [`discovery-pattern.md`](discovery-pattern.md) *(« a manifest is a promise », `/.well-known/`, défauts de sécurité)*.
4. **Les modes de déploiement** — [`deployment-modes.md`](deployment-modes.md) *(embarqué / librairie [Sylius] / proxy)*.

**Ligne rouge** : playbook d'implémentation des standards existants (UCP, MCP), jamais une spec concurrente.
