<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Contract;

use Fondouk\CapabilityCore\Graph\Shop;

/**
 * Port de profil boutique. Fournit l'identité + les axes de présentation et
 * l'état des transports/capabilities activés, pour construire le manifest UCP
 * (/.well-known/ucp) et l'en-tête `shop` du graphe.
 */
interface ShopProfileSource
{
    public function shop(): Shop;

    /**
     * Transports UCP activés.
     *
     * @return list<string> ex. ['rest', 'mcp']
     */
    public function enabledTransports(): array;

    /**
     * Capabilities UCP activées (noms reverse-domain).
     *
     * @return list<string> ex. ['dev.ucp.shopping.catalog.search', 'dev.ucp.shopping.catalog.lookup']
     */
    public function enabledCapabilities(): array;
}
