<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/**
 * Valeur d'option portée par un variant (ex. axe « Couleur » = « Bleu »).
 * Axe et valeur sont multilingues (clé = code langue BCP 47).
 */
final class SelectedOptionValue
{
    /**
     * @param array<string,string> $axis
     * @param array<string,string> $value
     */
    public function __construct(
        public readonly array $axis,
        public readonly array $value,
    ) {
    }

    /** @return array{axis:array<string,string>,value:array<string,string>} */
    public function toArray(): array
    {
        return ['axis' => $this->axis, 'value' => $this->value];
    }
}
