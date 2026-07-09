<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/** Prix public d'un variant pour une devise de présentation donnée. */
final class VariantPrice
{
    public function __construct(
        public readonly Money $price,
        public readonly ?Money $listPrice = null,
        public readonly ?bool $taxIncluded = null,
        public readonly ?string $country = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = ['price' => $this->price->toArray()];
        if ($this->listPrice !== null) {
            $out['list_price'] = $this->listPrice->toArray();
        }
        if ($this->taxIncluded !== null) {
            $out['tax_included'] = $this->taxIncluded;
        }
        if ($this->country !== null) {
            $out['country'] = $this->country;
        }

        return $out;
    }
}
