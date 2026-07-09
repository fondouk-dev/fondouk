<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Adapter\Ucp;

use Fondouk\CapabilityCore\Contract\ShopProfileSource;

/**
 * Adapter UCP — construit le manifest business servi à /.well-known/ucp,
 * conforme au profil business UCP (spec §2.1, release 2026-04-08).
 * Neutre : ne dépend que du port ShopProfileSource.
 *
 * Principe de véracité (« a manifest is a promise ») : ne sont sérialisés que
 * les transports et capabilities déclarés OPÉRATIONNELS par le ShopProfileSource.
 * Un endpoint en stub ne doit jamais apparaître ici. Manifest vide > manifest menteur.
 */
final class UcpManifestBuilder
{
    public const UCP_VERSION = '2026-04-08';

    private const SERVICE_SCHEMA = [
        'rest' => '/services/shopping/rest.openapi.json',
        'mcp' => '/services/shopping/mcp.openrpc.json',
    ];

    private const CAPABILITY_META = [
        'dev.ucp.shopping.catalog.search' => [
            'schema' => '/schemas/shopping/catalog_search.json',
            'spec' => '/specification/catalog/search',
        ],
        'dev.ucp.shopping.catalog.lookup' => [
            'schema' => '/schemas/shopping/catalog_lookup.json',
            'spec' => '/specification/catalog/lookup',
        ],
    ];

    /** @param array<string,string> $endpoints transport => URL absolue de l'endpoint */
    public function __construct(
        private readonly ShopProfileSource $profile,
        private readonly array $endpoints,
    ) {
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $base = 'https://ucp.dev/' . self::UCP_VERSION;
        $specOverview = $base . '/specification/overview';

        $services = [];
        foreach ($this->profile->enabledTransports() as $transport) {
            if (!isset($this->endpoints[$transport], self::SERVICE_SCHEMA[$transport])) {
                continue;
            }
            $services[] = [
                'version' => self::UCP_VERSION,
                'transport' => $transport,
                'endpoint' => $this->endpoints[$transport],
                'schema' => $base . self::SERVICE_SCHEMA[$transport],
                'spec' => $specOverview,
            ];
        }

        $capabilities = [];
        foreach ($this->profile->enabledCapabilities() as $name) {
            if (!isset(self::CAPABILITY_META[$name])) {
                continue;
            }
            $capabilities[$name] = [[
                'version' => self::UCP_VERSION,
                'schema' => $base . self::CAPABILITY_META[$name]['schema'],
                'spec' => $base . self::CAPABILITY_META[$name]['spec'],
            ]];
        }

        return [
            'ucp' => [
                'version' => self::UCP_VERSION,
                // required même vide : object JSON {} plutôt qu'un tableau, et pas de
                // clé de service quand aucun transport n'est opérationnel (véracité).
                'services' => $services !== [] ? ['dev.ucp.shopping' => $services] : (object) [],
                'capabilities' => $capabilities !== [] ? $capabilities : (object) [],
                'payment_handlers' => (object) [],
            ],
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->build(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
