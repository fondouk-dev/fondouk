<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Tests;

use Fondouk\CapabilityCore\Adapter\Ucp\UcpManifestBuilder;
use Fondouk\CapabilityCore\Contract\ShopProfileSource;
use Fondouk\CapabilityCore\Graph\Shop;
use PHPUnit\Framework\TestCase;

final class UcpManifestBuilderTest extends TestCase
{
    private function profile(): ShopProfileSource
    {
        return new class implements ShopProfileSource {
            public function shop(): Shop
            {
                return new Shop('1', 'https://boutique.example', 'fr', 'EUR', ['fr', 'en'], ['EUR', 'USD']);
            }

            public function enabledTransports(): array
            {
                return ['rest'];
            }

            public function enabledCapabilities(): array
            {
                return ['dev.ucp.shopping.catalog.search', 'dev.ucp.shopping.catalog.lookup'];
            }
        };
    }

    public function testManifestMatchesUcpBusinessProfileShape(): void
    {
        $builder = new UcpManifestBuilder(
            $this->profile(),
            ['rest' => 'https://boutique.example/fondouk/ucp']
        );
        $m = $builder->build();

        // Champs requis du profil business (spec §2.1).
        self::assertSame('2026-04-08', $m['ucp']['version']);
        self::assertArrayHasKey('dev.ucp.shopping', $m['ucp']['services']);
        self::assertArrayHasKey('payment_handlers', $m['ucp']);

        $svc = $m['ucp']['services']['dev.ucp.shopping'][0];
        self::assertSame('rest', $svc['transport']);
        self::assertSame('https://boutique.example/fondouk/ucp', $svc['endpoint']);
        self::assertStringStartsWith('https://ucp.dev/2026-04-08/', $svc['schema']);

        // Capabilities authority-bound sur ucp.dev.
        self::assertArrayHasKey('dev.ucp.shopping.catalog.search', $m['ucp']['capabilities']);
        self::assertArrayHasKey('dev.ucp.shopping.catalog.lookup', $m['ucp']['capabilities']);
        self::assertStringStartsWith(
            'https://ucp.dev/2026-04-08/schemas/shopping/catalog_search.json',
            $m['ucp']['capabilities']['dev.ucp.shopping.catalog.search'][0]['schema']
        );
    }

    public function testJsonEncodesEmptyPaymentHandlersAsObject(): void
    {
        $json = (new UcpManifestBuilder($this->profile(), ['rest' => 'https://x/fondouk/ucp']))->toJson();
        self::assertStringContainsString('"payment_handlers": {}', $json);
    }

    /**
     * Véracité (« a manifest is a promise ») : un ShopProfileSource sans capability
     * opérationnelle produit un manifest HONNÊTEMENT vide — services {} et capabilities {},
     * jamais une capability dont l'endpoint serait un stub.
     */
    public function testManifestOnlyDeclaresOperationalCapabilities(): void
    {
        $empty = new class implements ShopProfileSource {
            public function shop(): Shop
            {
                return new Shop('1', 'https://x', 'fr', 'EUR', ['fr'], ['EUR']);
            }

            public function enabledTransports(): array
            {
                return [];
            }

            public function enabledCapabilities(): array
            {
                return [];
            }
        };

        $m = (new UcpManifestBuilder($empty, ['rest' => 'https://x/fondouk/ucp']))->build();
        self::assertSame('2026-04-08', $m['ucp']['version']);
        self::assertEquals(new \stdClass(), $m['ucp']['services']);
        self::assertEquals(new \stdClass(), $m['ucp']['capabilities']);

        $json = (new UcpManifestBuilder($empty, []))->toJson();
        self::assertStringContainsString('"services": {}', $json);
        self::assertStringContainsString('"capabilities": {}', $json);
    }
}
