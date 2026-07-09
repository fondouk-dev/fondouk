<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/** Variant achetable. `prices` PEUT être vide (mode catalogue / prix masqué — décision Q2). */
final class Variant
{
    /**
     * @param list<VariantPrice>        $prices
     * @param array<string,string>      $title
     * @param list<Barcode>             $barcodes
     * @param list<SelectedOptionValue> $optionValues
     * @param list<Media>               $media
     */
    public function __construct(
        public readonly string $id,
        public readonly array $prices,
        public readonly Availability $availability,
        public readonly ?string $sku = null,
        public readonly array $barcodes = [],
        public readonly array $title = [],
        public readonly array $optionValues = [],
        public readonly ?UnitPrice $unitPrice = null,
        public readonly array $media = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        // `prices` est requis par le schéma même vide : toujours présent.
        $out = [
            'id' => $this->id,
            'prices' => array_map(static fn (VariantPrice $p) => $p->toArray(), $this->prices),
            'availability' => $this->availability->toArray(),
        ];
        if ($this->sku !== null) {
            $out['sku'] = $this->sku;
        }
        if ($this->barcodes !== []) {
            $out['barcodes'] = array_map(static fn (Barcode $b) => $b->toArray(), $this->barcodes);
        }
        if ($this->title !== []) {
            $out['title'] = $this->title;
        }
        if ($this->optionValues !== []) {
            $out['option_values'] = array_map(static fn (SelectedOptionValue $o) => $o->toArray(), $this->optionValues);
        }
        if ($this->unitPrice !== null) {
            $out['unit_price'] = $this->unitPrice->toArray();
        }
        if ($this->media !== []) {
            $out['media'] = array_map(static fn (Media $m) => $m->toArray(), $this->media);
        }

        return $out;
    }
}
