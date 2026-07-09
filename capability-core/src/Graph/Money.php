<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/**
 * Montant monétaire en unités mineures ISO 4217 (centimes), + code devise.
 * Miroir du type UCP common/types/amount.json + price.json.
 */
final class Money
{
    public function __construct(
        public readonly int $amountMinor,
        public readonly string $currency,
    ) {
    }

    /** @return array{amount_minor:int,currency:string} */
    public function toArray(): array
    {
        return ['amount_minor' => $this->amountMinor, 'currency' => $this->currency];
    }
}
