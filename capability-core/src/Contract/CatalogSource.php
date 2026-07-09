<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Contract;

use Fondouk\CapabilityCore\Graph\CapabilityGraph;
use Fondouk\CapabilityCore\Query\GetProductQuery;
use Fondouk\CapabilityCore\Query\LookupQuery;
use Fondouk\CapabilityCore\Query\SearchQuery;

/**
 * Port d'introspection catalogue. Implémenté par l'adapter (PrestaShop, Sylius, proxy…).
 *
 * Chaque méthode retourne un CapabilityGraph = sous-ensemble matérialisé (Subset).
 * L'implémentation applique le filtre « visible par un visiteur anonyme » (§5) :
 * aucune donnée non publique ne franchit ce port.
 */
interface CatalogSource
{
    /** Recherche : subset.kind = "search", paginée. */
    public function search(SearchQuery $query): CapabilityGraph;

    /** Lookup batch : subset.kind = "lookup". Identifiants introuvables → simplement absents. */
    public function lookup(LookupQuery $query): CapabilityGraph;

    /**
     * Fiche produit : subset.kind = "product", 0 ou 1 produit.
     * Retourne null si l'identifiant ne résout aucun produit (→ not_found côté adapter).
     */
    public function getProduct(GetProductQuery $query): ?CapabilityGraph;
}
