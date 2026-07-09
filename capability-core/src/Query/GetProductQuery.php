<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Query;

/** Requête de fiche produit détaillée, avec narrowing d'options optionnel. */
final class GetProductQuery
{
    /**
     * @param list<array{name:string,label:string}> $selected     sélections d'options partielles
     * @param list<string>                           $preferences  ordre de relâchement des axes
     */
    public function __construct(
        public readonly string $id,
        public readonly array $selected = [],
        public readonly array $preferences = [],
        public readonly string $language = '',
        public readonly string $currency = '',
        public readonly ?string $country = null,
    ) {
    }
}
