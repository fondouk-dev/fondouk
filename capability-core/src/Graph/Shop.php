<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/** Identité de la boutique + axes de présentation (langues/devises). */
final class Shop
{
    /**
     * @param list<string> $languages  codes BCP 47 actifs
     * @param list<string> $currencies codes ISO 4217 actifs
     */
    public function __construct(
        public readonly string $id,
        public readonly string $baseUrl,
        public readonly string $defaultLanguage,
        public readonly string $defaultCurrency,
        public readonly array $languages,
        public readonly array $currencies,
        public readonly ?string $gidBase = null,
        public readonly ?string $name = null,
        public readonly ?string $defaultCountry = null,
        public readonly ?bool $pricesIncludeTax = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'base_url' => $this->baseUrl,
            'default_language' => $this->defaultLanguage,
            'default_currency' => $this->defaultCurrency,
            'languages' => $this->languages,
            'currencies' => $this->currencies,
        ];
        if ($this->gidBase !== null) {
            $out['gid_base'] = $this->gidBase;
        }
        if ($this->name !== null) {
            $out['name'] = $this->name;
        }
        if ($this->defaultCountry !== null) {
            $out['default_country'] = $this->defaultCountry;
        }
        if ($this->pricesIncludeTax !== null) {
            $out['prices_include_tax'] = $this->pricesIncludeTax;
        }

        return $out;
    }
}
