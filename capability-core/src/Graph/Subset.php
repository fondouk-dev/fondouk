<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/**
 * Qualifie la matérialisation courante du graphe (jamais matérialisé en entier).
 * Correction Gate 0 n°1 : le graphe est un modèle logique, une instance est un sous-ensemble.
 */
final class Subset
{
    public const SEARCH = 'search';
    public const LOOKUP = 'lookup';
    public const PRODUCT = 'product';
    public const FULL = 'full';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $cursor = null,
        public readonly ?bool $hasNext = null,
        public readonly ?int $totalCount = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = ['kind' => $this->kind];
        if ($this->cursor !== null) {
            $out['cursor'] = $this->cursor;
        }
        if ($this->hasNext !== null) {
            $out['has_next'] = $this->hasNext;
        }
        if ($this->totalCount !== null) {
            $out['total_count'] = $this->totalCount;
        }

        return $out;
    }
}
