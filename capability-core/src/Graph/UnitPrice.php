<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/** Prix à l'unité de mesure (optionnel). */
final class UnitPrice
{
    /**
     * @param array{value:float|int,unit:string} $measure
     * @param array{value:float|int,unit:string} $reference
     */
    public function __construct(
        public readonly Money $price,
        public readonly array $measure,
        public readonly array $reference,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'price' => $this->price->toArray(),
            'measure' => $this->measure,
            'reference' => $this->reference,
        ];
    }
}
