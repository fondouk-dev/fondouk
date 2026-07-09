<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Tests;

/**
 * Gate 1 : chaque fixture d'instance du Capability Graph valide contre le schéma v0.
 * Couvre les quatre sous-ensembles : search (paginé), lookup, product, et le cas
 * mode-catalogue (public=true, prices=[]).
 */
final class SchemaValidationTest extends GraphSchemaTestCase
{
    /** @return array<string,array{string}> */
    public static function fixtureProvider(): array
    {
        return [
            'search (paginé, multi-produits, déclinaisons)' => ['graph.search.json'],
            'lookup (specific_price public + prix B2B masqué)' => ['graph.lookup.json'],
            'product (fiche + option_axes + rating + barcode)' => ['graph.product.json'],
            'catalog-mode (public=true, prices=[])' => ['graph.catalog-mode.json'],
        ];
    }

    /** @dataProvider fixtureProvider */
    public function testFixtureIsValid(string $file): void
    {
        $path = __DIR__ . '/fixtures/' . $file;
        $json = file_get_contents($path);
        self::assertNotFalse($json, "Fixture introuvable : $file");

        $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $this->assertValidGraph($data, $file);
    }

    public function testCatalogModeVariantHasEmptyPrices(): void
    {
        $data = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/graph.catalog-mode.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertTrue($data['products'][0]['public'], 'Le produit mode-catalogue reste public.');
        self::assertSame([], $data['products'][0]['variants'][0]['prices'], 'Ses prix sont vides.');
    }
}
