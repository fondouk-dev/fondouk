<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/**
 * Racine du Capability Graph — modèle pivot neutre (Blueprint artefact n°1).
 * Une instance est TOUJOURS un sous-ensemble matérialisé (voir Subset).
 * Le JSON produit valide contre schema/capability-graph.v0.json.
 */
final class CapabilityGraph
{
    public const GRAPH_VERSION = '0';

    /**
     * @param list<Product>  $products
     * @param list<Category> $categories
     * @param list<string>   $capabilities
     */
    public function __construct(
        public readonly Shop $shop,
        public readonly string $generatedAt,
        public readonly array $products,
        public readonly ?Subset $subset = null,
        public readonly array $categories = [],
        public readonly array $capabilities = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = [
            'graph_version' => self::GRAPH_VERSION,
            'generated_at' => $this->generatedAt,
            'shop' => $this->shop->toArray(),
            'products' => array_map(static fn (Product $p) => $p->toArray(), $this->products),
        ];
        if ($this->subset !== null) {
            $out['subset'] = $this->subset->toArray();
        }
        if ($this->capabilities !== []) {
            $out['capabilities'] = $this->capabilities;
        }
        if ($this->categories !== []) {
            $out['categories'] = array_map(static fn (Category $c) => $c->toArray(), $this->categories);
        }

        return $out;
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
