<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Tests;

use Fondouk\CapabilityCore\Adapter\Ucp\UcpCatalogMapper;
use Fondouk\CapabilityCore\Graph\Availability;
use Fondouk\CapabilityCore\Graph\Barcode;
use Fondouk\CapabilityCore\Graph\CapabilityGraph;
use Fondouk\CapabilityCore\Graph\Media;
use Fondouk\CapabilityCore\Graph\Money;
use Fondouk\CapabilityCore\Graph\OptionAxis;
use Fondouk\CapabilityCore\Graph\Product;
use Fondouk\CapabilityCore\Graph\Rating;
use Fondouk\CapabilityCore\Graph\SelectedOptionValue;
use Fondouk\CapabilityCore\Graph\Shop;
use Fondouk\CapabilityCore\Graph\Subset;
use Fondouk\CapabilityCore\Graph\Variant;
use Fondouk\CapabilityCore\Graph\VariantPrice;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * PROOF #1 — les réponses UCP produites par l'adapter Catalog valident contre les
 * schémas PUBLIÉS de la release 2026-04-08 (vendorés dans tests/resources), pas
 * seulement contre le schéma interne du graphe. Le caveat de version §0 devient réel.
 */
final class UcpCatalogMapperTest extends TestCase
{
    private const SCHEMAS_DIR = __DIR__ . '/resources/ucp-2026-04-08/schemas';

    private function validateUcp(array $data, string $schemaUri): void
    {
        $validator = new Validator();
        $validator->resolver()->registerPrefix('https://ucp.dev/schemas/', self::SCHEMAS_DIR);

        // json_decode(json_encode(...)) : force des objets stdClass comme attend opis.
        $payload = json_decode((string) json_encode($data), false, 512, JSON_THROW_ON_ERROR);
        $result = $validator->validate($payload, $schemaUri);

        if (!$result->isValid()) {
            self::fail(
                "Réponse non conforme au schéma publié 2026-04-08 ($schemaUri) : "
                . json_encode((new ErrorFormatter())->format($result->error()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }
        $this->addToAssertionCount(1);
    }

    private function graph(string $subsetKind, bool $withCatalogModeProduct = true): CapabilityGraph
    {
        $products = [
            new Product(
                id: 'gid://boutique.example/1/product/21',
                title: ['fr' => 'T-shirt démo 21', 'en' => 'Demo t-shirt 21'],
                public: true,
                variants: [
                    new Variant(
                        id: 'gid://boutique.example/1/variant/21-101',
                        prices: [new VariantPrice(new Money(5388, 'EUR'), new Money(5988, 'EUR'), true, 'FR')],
                        availability: new Availability(true, Availability::IN_STOCK, 20),
                        sku: 'AGT-0021-S-R',
                        barcodes: [new Barcode(Barcode::EAN, '3401579000210')],
                        title: ['fr' => 'S / Rouge', 'en' => 'S / Red'],
                        optionValues: [
                            new SelectedOptionValue(['fr' => 'Taille', 'en' => 'Taille'], ['fr' => 'S', 'en' => 'S']),
                            new SelectedOptionValue(['fr' => 'Couleur', 'en' => 'Couleur'], ['fr' => 'Rouge', 'en' => 'Red']),
                        ],
                    ),
                    new Variant(
                        id: 'gid://boutique.example/1/variant/21-109',
                        prices: [new VariantPrice(new Money(5388, 'EUR'), null, true, 'FR')],
                        availability: new Availability(false, Availability::OUT_OF_STOCK, 0),
                        sku: 'AGT-0021-L-V',
                        title: ['fr' => 'L / Vert', 'en' => 'L / Green'],
                    ),
                ],
                handle: 'agt-0021-t-shirt-demo-21',
                reference: 'AGT-0021',
                description: ['fr' => 'T-shirt à déclinaisons.', 'en' => 'T-shirt with variants.'],
                url: ['fr' => 'https://boutique.example/fr/21-agt-0021.html', 'en' => 'https://boutique.example/en/21-agt-0021.html'],
                categoryIds: ['10'],
                media: [new Media(Media::IMAGE, 'https://boutique.example/img/p/21.jpg', 'T-shirt')],
                optionAxes: [
                    new OptionAxis(['fr' => 'Taille', 'en' => 'Taille'], [['fr' => 'S', 'en' => 'S'], ['fr' => 'L', 'en' => 'L']]),
                    new OptionAxis(['fr' => 'Couleur', 'en' => 'Couleur'], [['fr' => 'Rouge', 'en' => 'Red'], ['fr' => 'Vert', 'en' => 'Green']]),
                ],
                rating: new Rating(4.5, 5.0, 12),
            ),
        ];

        if ($withCatalogModeProduct) {
            // Produit mode-catalogue : public=true mais aucun prix → doit être EXCLU des réponses UCP.
            $products[] = new Product(
                id: 'gid://boutique.example/1/product/500',
                title: ['fr' => 'Mode catalogue', 'en' => 'Catalog mode'],
                public: true,
                variants: [new Variant('gid://boutique.example/1/variant/500-0', [], new Availability(true, Availability::IN_STOCK))],
                description: ['fr' => 'Prix masqué.', 'en' => 'Hidden price.'],
            );
        }

        return new CapabilityGraph(
            shop: new Shop('1', 'https://boutique.example', 'fr', 'EUR', ['fr', 'en'], ['EUR'], gidBase: 'boutique.example'),
            generatedAt: '2026-07-04T12:00:00+00:00',
            products: $products,
            subset: new Subset($subsetKind, 'eyJwIjoxfQ==', true, 30),
        );
    }

    public function testSearchResponseValidatesAndExcludesCatalogModeProduct(): void
    {
        $mapper = new UcpCatalogMapper('fr', 'EUR');
        $response = $mapper->searchResponse($this->graph(Subset::SEARCH));

        $this->validateUcp($response, 'https://ucp.dev/schemas/shopping/catalog_search.json#/$defs/search_response');

        self::assertCount(1, $response['products'], 'Le produit mode-catalogue (sans prix) est exclu.');
        self::assertSame('gid://boutique.example/1/product/21', $response['products'][0]['id']);
        self::assertSame(5388, $response['products'][0]['price_range']['min']['amount']);
        self::assertSame('EUR', $response['products'][0]['price_range']['min']['currency']);
    }

    public function testLookupResponseValidatesWithInputs(): void
    {
        $mapper = new UcpCatalogMapper('fr', 'EUR');
        $response = $mapper->lookupResponse($this->graph(Subset::LOOKUP));

        $this->validateUcp($response, 'https://ucp.dev/schemas/shopping/catalog_lookup.json#/$defs/lookup_response');

        self::assertNotEmpty($response['products'][0]['variants'][0]['inputs']);
    }

    public function testGetProductResponseValidates(): void
    {
        $mapper = new UcpCatalogMapper('fr', 'EUR');
        $graph = $this->graph(Subset::PRODUCT, withCatalogModeProduct: false);
        $response = $mapper->getProductResponse($graph);

        $this->validateUcp($response, 'https://ucp.dev/schemas/shopping/catalog_lookup.json#/$defs/get_product_response');

        self::assertSame('gid://boutique.example/1/product/21', $response['product']['id']);
    }

    public function testGetProductNotFoundReturnsErrorEnvelope(): void
    {
        $mapper = new UcpCatalogMapper('fr', 'EUR');
        $empty = new CapabilityGraph(
            shop: new Shop('1', 'https://boutique.example', 'fr', 'EUR', ['fr'], ['EUR']),
            generatedAt: '2026-07-04T12:00:00+00:00',
            products: [],
            subset: new Subset(Subset::PRODUCT),
        );

        $response = $mapper->getProductResponse($empty);
        self::assertSame('error', $response['ucp']['status']);
        self::assertSame('not_found', $response['messages'][0]['code']);
        self::assertSame('unrecoverable', $response['messages'][0]['severity']);
    }
}
