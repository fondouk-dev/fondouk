<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/** Disponibilité d'un variant. `quantity` est interne (jamais exposé par les adapters). */
final class Availability
{
    public const IN_STOCK = 'in_stock';
    public const OUT_OF_STOCK = 'out_of_stock';
    public const BACKORDER = 'backorder';
    public const PREORDER = 'preorder';
    public const DISCONTINUED = 'discontinued';

    public function __construct(
        public readonly bool $available,
        public readonly ?string $status = null,
        public readonly ?int $quantity = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = ['available' => $this->available];
        if ($this->status !== null) {
            $out['status'] = $this->status;
        }
        if ($this->quantity !== null) {
            $out['quantity'] = $this->quantity;
        }

        return $out;
    }
}
