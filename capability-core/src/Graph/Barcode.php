<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/** Code-barres normalisé (EAN/UPC/ISBN). En PS la colonne DB reste `ean13` (décision Q12). */
final class Barcode
{
    public const EAN = 'EAN';
    public const UPC = 'UPC';
    public const ISBN = 'ISBN';

    public function __construct(
        public readonly string $type,
        public readonly string $value,
    ) {
    }

    /** @return array{type:string,value:string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'value' => $this->value];
    }
}
