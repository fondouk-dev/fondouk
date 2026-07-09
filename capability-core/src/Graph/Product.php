<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/** Produit du catalogue. `public=true` même en mode catalogue (variants sans prix) — décision Q2. */
final class Product
{
    /**
     * @param array<string,string> $title
     * @param list<Variant>        $variants
     * @param array<string,string> $description
     * @param array<string,string> $descriptionShort
     * @param array<string,string> $url
     * @param list<string>         $categoryIds
     * @param list<Media>          $media
     * @param list<OptionAxis>     $optionAxes
     */
    public function __construct(
        public readonly string $id,
        public readonly array $title,
        public readonly bool $public,
        public readonly array $variants,
        public readonly ?string $handle = null,
        public readonly ?string $reference = null,
        public readonly array $description = [],
        public readonly array $descriptionShort = [],
        public readonly array $url = [],
        public readonly ?string $visibilityReason = null,
        public readonly array $categoryIds = [],
        public readonly ?string $manufacturer = null,
        public readonly array $media = [],
        public readonly array $optionAxes = [],
        public readonly ?Rating $rating = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'title' => $this->title,
            'public' => $this->public,
            'variants' => array_map(static fn (Variant $v) => $v->toArray(), $this->variants),
        ];
        if ($this->handle !== null) {
            $out['handle'] = $this->handle;
        }
        if ($this->reference !== null) {
            $out['reference'] = $this->reference;
        }
        if ($this->description !== []) {
            $out['description'] = $this->description;
        }
        if ($this->descriptionShort !== []) {
            $out['description_short'] = $this->descriptionShort;
        }
        if ($this->url !== []) {
            $out['url'] = $this->url;
        }
        if ($this->visibilityReason !== null) {
            $out['visibility_reason'] = $this->visibilityReason;
        }
        if ($this->categoryIds !== []) {
            $out['category_ids'] = $this->categoryIds;
        }
        if ($this->manufacturer !== null) {
            $out['manufacturer'] = $this->manufacturer;
        }
        if ($this->media !== []) {
            $out['media'] = array_map(static fn (Media $m) => $m->toArray(), $this->media);
        }
        if ($this->optionAxes !== []) {
            $out['option_axes'] = array_map(static fn (OptionAxis $a) => $a->toArray(), $this->optionAxes);
        }
        if ($this->rating !== null) {
            $out['rating'] = $this->rating->toArray();
        }

        return $out;
    }
}
