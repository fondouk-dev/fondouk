<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/** Axe d'option d'un produit (ex. « Taille ») et ses valeurs possibles, multilingues. */
final class OptionAxis
{
    /**
     * @param array<string,string>       $name
     * @param list<array<string,string>> $values
     */
    public function __construct(
        public readonly array $name,
        public readonly array $values,
    ) {
    }

    /** @return array{name:array<string,string>,values:list<array<string,string>>} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'values' => $this->values];
    }
}
