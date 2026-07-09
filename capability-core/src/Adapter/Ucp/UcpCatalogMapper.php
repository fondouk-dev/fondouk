<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Adapter\Ucp;

use Fondouk\CapabilityCore\Graph\CapabilityGraph;
use Fondouk\CapabilityCore\Graph\Product;
use Fondouk\CapabilityCore\Graph\Variant;

/**
 * Adapter UCP — projette un sous-ensemble du Capability Graph vers les réponses
 * de la capability Catalog (search / lookup / product), conformes aux schémas
 * publiés de la release 2026-04-08.
 *
 * Neutre : ne dépend que des DTO du graphe. Résout langue + devise de présentation.
 * Applique la RÈGLE D'ASYMÉTRIE (décision Q2) : un produit dont aucun variant n'a de
 * prix (mode catalogue) est EXCLU, car le schéma UCP rend price_range/price requis.
 */
final class UcpCatalogMapper
{
    public const CAP_SEARCH = 'dev.ucp.shopping.catalog.search';
    public const CAP_LOOKUP = 'dev.ucp.shopping.catalog.lookup';

    public function __construct(
        private readonly string $language,
        private readonly string $currency,
    ) {
    }

    /** @return array<string,mixed> */
    public function searchResponse(CapabilityGraph $graph): array
    {
        $products = $this->mapProducts($graph);

        $response = [
            'ucp' => $this->ucpEnvelope(self::CAP_SEARCH),
            'products' => $products,
        ];

        if ($graph->subset !== null) {
            $pagination = ['has_next_page' => (bool) $graph->subset->hasNext];
            if ($graph->subset->cursor !== null) {
                $pagination['cursor'] = $graph->subset->cursor;
            }
            if ($graph->subset->totalCount !== null) {
                $pagination['total_count'] = $graph->subset->totalCount;
            }
            $response['pagination'] = $pagination;
        }

        return $response;
    }

    /**
     * @param array<string,list<array{id:string,match:string}>> $inputsByVariantId
     *
     * @return array<string,mixed>
     */
    public function lookupResponse(CapabilityGraph $graph, array $inputsByVariantId = []): array
    {
        $products = [];
        foreach ($graph->products as $product) {
            $mapped = $this->mapProduct($product);
            if ($mapped === null) {
                continue;
            }
            // Chaque variant d'une réponse lookup DOIT porter `inputs` (corrélation).
            foreach ($mapped['variants'] as $i => $variant) {
                $vid = $variant['id'];
                $mapped['variants'][$i]['inputs'] = $inputsByVariantId[$vid]
                    ?? [['id' => $vid, 'match' => 'exact']];
            }
            $products[] = $mapped;
        }

        return [
            'ucp' => $this->ucpEnvelope(self::CAP_LOOKUP),
            'products' => $products,
        ];
    }

    /**
     * @return array<string,mixed> réponse get_product (product singulier) ou enveloppe d'erreur not_found
     */
    public function getProductResponse(CapabilityGraph $graph): array
    {
        $product = $graph->products[0] ?? null;
        $mapped = $product !== null ? $this->mapProduct($product) : null;

        if ($mapped === null) {
            return [
                'ucp' => $this->ucpEnvelope(self::CAP_LOOKUP, 'error'),
                'messages' => [[
                    'type' => 'error',
                    'code' => 'not_found',
                    'content' => 'Product not found.',
                    'severity' => 'unrecoverable',
                ]],
            ];
        }

        return [
            'ucp' => $this->ucpEnvelope(self::CAP_LOOKUP),
            'product' => $mapped,
        ];
    }

    // ------------------------------------------------------------------ interne

    /** @return list<array<string,mixed>> */
    private function mapProducts(CapabilityGraph $graph): array
    {
        $out = [];
        foreach ($graph->products as $product) {
            $mapped = $this->mapProduct($product);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null null si le produit doit être exclu (aucun prix — mode catalogue) */
    private function mapProduct(Product $product): ?array
    {
        $descriptionText = $this->pick($product->description) ?: $this->pick($product->descriptionShort);

        $variants = [];
        $prices = [];
        $listPrices = [];
        foreach ($product->variants as $variant) {
            $mappedVariant = $this->mapVariant($variant, $product, $descriptionText);
            if ($mappedVariant === null) {
                continue; // variant sans prix (mode catalogue) → ignoré
            }
            $variants[] = $mappedVariant;
            $prices[] = $mappedVariant['price']['amount'];
            if (isset($mappedVariant['list_price'])) {
                $listPrices[] = $mappedVariant['list_price']['amount'];
            }
        }

        // Asymétrie Q2 : sans variant vendable avec prix, le produit n'est pas représentable en UCP.
        if ($variants === []) {
            return null;
        }

        $currency = $variants[0]['price']['currency'];

        $out = [
            'id' => $product->id,
            'title' => $this->pick($product->title),
            'description' => ['plain' => $descriptionText !== '' ? $descriptionText : $this->pick($product->title)],
            'price_range' => [
                'min' => ['amount' => min($prices), 'currency' => $currency],
                'max' => ['amount' => max($prices), 'currency' => $currency],
            ],
            'variants' => $variants,
        ];

        if ($product->handle !== null) {
            $out['handle'] = $product->handle;
        }
        $url = $this->pick($product->url);
        if ($url !== '') {
            $out['url'] = $url;
        }
        if ($listPrices !== []) {
            $out['list_price_range'] = [
                'min' => ['amount' => min($listPrices), 'currency' => $currency],
                'max' => ['amount' => max($listPrices), 'currency' => $currency],
            ];
        }
        if ($product->media !== []) {
            $out['media'] = array_map([$this, 'mapMedia'], $product->media);
        }
        if ($product->optionAxes !== []) {
            $out['options'] = array_map(function ($axis) {
                return [
                    'name' => $this->pick($axis->name),
                    'values' => array_map(fn (array $v) => ['label' => $this->pick($v)], $axis->values),
                ];
            }, $product->optionAxes);
        }
        if ($product->rating !== null) {
            $r = ['value' => $product->rating->value, 'scale_max' => $product->rating->scaleMax ?? 5];
            if ($product->rating->count !== null) {
                $r['count'] = $product->rating->count;
            }
            $out['rating'] = $r;
        }
        if ($product->categoryIds !== []) {
            $out['categories'] = array_map(
                static fn (string $id) => ['value' => $id, 'taxonomy' => 'merchant'],
                $product->categoryIds
            );
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function mapVariant(Variant $variant, Product $product, string $productDescription): ?array
    {
        $price = $this->pickPrice($variant);
        if ($price === null) {
            return null; // prix masqué (mode catalogue)
        }

        $title = $this->pick($variant->title);
        if ($title === '' && $variant->optionValues !== []) {
            $title = implode(' / ', array_map(fn ($ov) => $this->pick($ov->value), $variant->optionValues));
        }
        if ($title === '') {
            $title = $this->pick($product->title);
        }

        $out = [
            'id' => $variant->id,
            'title' => $title,
            'description' => ['plain' => $productDescription !== '' ? $productDescription : $title],
            'price' => ['amount' => $price->price->amountMinor, 'currency' => $price->price->currency],
            'availability' => $this->mapAvailability($variant),
        ];

        if ($variant->sku !== null) {
            $out['sku'] = $variant->sku;
        }
        if ($price->listPrice !== null) {
            $out['list_price'] = ['amount' => $price->listPrice->amountMinor, 'currency' => $price->listPrice->currency];
        }
        if ($variant->optionValues !== []) {
            $out['options'] = array_map(fn ($ov) => [
                'name' => $this->pick($ov->axis),
                'label' => $this->pick($ov->value),
            ], $variant->optionValues);
        }
        if ($variant->barcodes !== []) {
            $out['barcodes'] = array_map(static fn ($b) => ['type' => $b->type, 'value' => $b->value], $variant->barcodes);
        }
        if ($variant->media !== []) {
            $out['media'] = array_map([$this, 'mapMedia'], $variant->media);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function mapAvailability(Variant $variant): array
    {
        // `quantity` interne n'est JAMAIS exposé (spec §5).
        $a = ['available' => $variant->availability->available];
        if ($variant->availability->status !== null) {
            $a['status'] = $variant->availability->status;
        }

        return $a;
    }

    /** @return array<string,mixed> */
    private function mapMedia(object $media): array
    {
        $m = ['type' => $media->type, 'url' => $media->url];
        if ($media->altText !== null) {
            $m['alt_text'] = $media->altText;
        }
        if ($media->width !== null) {
            $m['width'] = $media->width;
        }
        if ($media->height !== null) {
            $m['height'] = $media->height;
        }

        return $m;
    }

    /** Choisit le prix de la devise de présentation, sinon le premier. */
    private function pickPrice(Variant $variant)
    {
        foreach ($variant->prices as $p) {
            if ($p->price->currency === $this->currency) {
                return $p;
            }
        }

        return $variant->prices[0] ?? null;
    }

    /** @param array<string,string> $localized */
    private function pick(array $localized): string
    {
        if ($localized === []) {
            return '';
        }

        return $localized[$this->language] ?? (string) reset($localized);
    }

    /** @return array<string,mixed> */
    private function ucpEnvelope(string $capability, string $status = 'success'): array
    {
        $env = [
            'version' => UcpManifestBuilder::UCP_VERSION,
            'capabilities' => [$capability => [['version' => UcpManifestBuilder::UCP_VERSION]]],
        ];
        if ($status !== 'success') {
            $env['status'] = $status;
        }

        return $env;
    }
}
