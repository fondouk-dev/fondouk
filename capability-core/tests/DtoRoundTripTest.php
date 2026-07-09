<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Tests;

use Fondouk\CapabilityCore\Graph\Availability;
use Fondouk\CapabilityCore\Graph\CapabilityGraph;
use Fondouk\CapabilityCore\Graph\Category;
use Fondouk\CapabilityCore\Graph\Money;
use Fondouk\CapabilityCore\Graph\Product;
use Fondouk\CapabilityCore\Graph\Shop;
use Fondouk\CapabilityCore\Graph\Subset;
use Fondouk\CapabilityCore\Graph\Variant;
use Fondouk\CapabilityCore\Graph\VariantPrice;

/**
 * Prouve que la sérialisation des DTO (toArray/toJson) produit du JSON conforme
 * au schéma v0 — y compris le cas mode-catalogue (variant.prices = []).
 */
final class DtoRoundTripTest extends GraphSchemaTestCase
{
    private function buildGraph(): CapabilityGraph
    {
        return new CapabilityGraph(
            shop: new Shop(
                id: '1',
                baseUrl: 'https://boutique.example',
                defaultLanguage: 'fr',
                defaultCurrency: 'EUR',
                languages: ['fr', 'en'],
                currencies: ['EUR', 'USD'],
                gidBase: 'boutique.example',
                name: 'Boutique Démo',
                defaultCountry: 'FR',
                pricesIncludeTax: true,
            ),
            generatedAt: '2026-07-04T12:00:00+00:00',
            products: [
                new Product(
                    id: 'gid://boutique.example/1/product/1',
                    title: ['fr' => 'Produit démo 1', 'en' => 'Demo product 1'],
                    public: true,
                    variants: [
                        new Variant(
                            id: 'gid://boutique.example/1/variant/1-0',
                            prices: [
                                new VariantPrice(new Money(1308, 'EUR'), taxIncluded: true, country: 'FR'),
                                new VariantPrice(new Money(1413, 'USD'), taxIncluded: true, country: 'FR'),
                            ],
                            availability: new Availability(true, Availability::IN_STOCK, 50),
                            sku: 'AGT-0001',
                        ),
                    ],
                    reference: 'AGT-0001',
                    categoryIds: ['10'],
                ),
                // Cas mode-catalogue : public=true, aucun prix.
                new Product(
                    id: 'gid://boutique.example/1/product/500',
                    title: ['fr' => 'Mode catalogue', 'en' => 'Catalog mode'],
                    public: true,
                    variants: [
                        new Variant(
                            id: 'gid://boutique.example/1/variant/500-0',
                            prices: [],
                            availability: new Availability(true, Availability::IN_STOCK),
                        ),
                    ],
                ),
            ],
            subset: new Subset(Subset::SEARCH, 'eyJwIjoxfQ==', true, 2),
            categories: [new Category('10', 'AGT Démo', 'Accueil > AGT Démo', '2')],
            capabilities: ['catalog.search', 'catalog.lookup'],
        );
    }

    public function testDtoOutputValidatesAgainstSchema(): void
    {
        $json = $this->buildGraph()->toJson();
        $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $this->assertValidGraph($data, 'DTO round-trip');
    }

    public function testEmptyPricesArePreservedInOutput(): void
    {
        $array = $this->buildGraph()->toArray();
        self::assertSame([], $array['products'][1]['variants'][0]['prices']);
        self::assertTrue($array['products'][1]['public']);
    }
}
