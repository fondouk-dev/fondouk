<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Query;

/** Requête de recherche catalogue, neutre. La langue/devise sont déjà résolues (décisions Q4/Q5). */
final class SearchQuery
{
    /**
     * @param array<string,mixed> $filters catégories, prix, etc. (combinés en AND)
     */
    public function __construct(
        public readonly ?string $query = null,
        public readonly string $language = '',
        public readonly string $currency = '',
        public readonly ?string $country = null,
        public readonly array $filters = [],
        public readonly Pagination $pagination = new Pagination(),
    ) {
    }
}
