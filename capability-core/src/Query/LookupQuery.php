<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Query;

/** Requête de lookup batch par identifiants (product/variant GID ou id interne). */
final class LookupQuery
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        public readonly array $ids,
        public readonly string $language = '',
        public readonly string $currency = '',
        public readonly ?string $country = null,
    ) {
    }
}
