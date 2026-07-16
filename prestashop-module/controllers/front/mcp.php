<?php
/**
 * Serveur MCP lecture seule (JSON-RPC 2.0 / Streamable HTTP) — mêmes capacités
 * catalogue que le REST, MÊME chaîne (PrestaShopCatalogSource → UcpCatalogMapper) et
 * MÊMES gardes (rate limiter + télémétrie) : un agent MCP ne contourne rien.
 *
 * Méthodes : initialize, notifications/initialized, ping, tools/list, tools/call.
 * Outils : search_catalog, get_product, lookup_catalog — descriptions « quand l'utiliser »
 * et erreurs en langage clair qu'un agent peut exploiter pour se corriger.
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'fondouk/vendor/autoload.php';

use Fondouk\CapabilityCore\Adapter\Ucp\UcpCatalogMapper;
use Fondouk\CapabilityCore\Query\GetProductQuery;
use Fondouk\CapabilityCore\Query\LookupQuery;
use Fondouk\CapabilityCore\Query\Pagination;
use Fondouk\CapabilityCore\Query\SearchQuery;
use PrestaShop\Module\Fondouk\PrestaShop\AccessLogger;
use PrestaShop\Module\Fondouk\PrestaShop\PrestaShopCatalogSource;
use PrestaShop\Module\Fondouk\PrestaShop\RateLimiter;
use PrestaShop\Module\Fondouk\PrestaShop\RequestGuard;
use PrestaShop\Module\Fondouk\PrestaShop\SearchLocale;

class FondoukMcpModuleFrontController extends ModuleFrontController
{
    public $ssl = false;

    private const PROTOCOL_VERSION = '2025-06-18';
    private const RATE_LIMIT_PER_MIN = 60;
    private const RATE_LIMIT_BURST = 120;
    private const MAX_BATCH = 20;

    public function canonicalRedirection($canonicalURL = '')
    {
    }

    public function setMedia()
    {
        return true;
    }

    public function initContent()
    {
        $start = microtime(true);
        header('Access-Control-Allow-Origin: *');

        // Garde de taille de corps (Gate 2 F2a).
        [$tooLarge, $payload] = RequestGuard::jsonBody();
        if ($tooLarge) {
            http_response_code(413);
            AccessLogger::log($this->context, 'mcp', null, 413, null, (int) round((microtime(true) - $start) * 1000));
            $this->send($this->error(null, -32600, 'Request body too large (max 256 KiB).'));
        }
        if ($payload === null) {
            $this->send($this->error(null, -32700, 'Parse error: body is not valid JSON.'));
        }

        $isBatch = array_is_list($payload) && $payload !== [];
        $requests = $isBatch ? $payload : [$payload];

        // Cap de batch (Gate 2 F2b) : borne le travail d'une seule requête HTTP.
        if (count($requests) > self::MAX_BATCH) {
            http_response_code(400);
            $this->send($this->error(null, -32600, 'Batch too large (max ' . self::MAX_BATCH . ' requests per call).'));
        }

        // Rate limiting PAR SOUS-REQUÊTE : un batch ne contourne pas le limiteur (Gate 2 F2b).
        $limiter = new RateLimiter(self::RATE_LIMIT_PER_MIN, self::RATE_LIMIT_BURST);
        $ip = (string) Tools::getRemoteAddr();
        $responses = [];
        $rateLimited = false;
        $retryAfter = 1;

        foreach ($requests as $req) {
            $id = is_array($req) ? ($req['id'] ?? null) : null;
            $rl = $limiter->hit($ip);
            if (!$rl['allowed']) {
                $rateLimited = true;
                $retryAfter = $rl['retryAfter'];
                $responses[] = ['jsonrpc' => '2.0', 'id' => $id, 'error' => [
                    'code' => -32000, 'message' => 'Rate limit exceeded', 'data' => ['retry_after' => $rl['retryAfter']],
                ]];
                continue;
            }
            $res = is_array($req) ? $this->handle($req, $start) : $this->error(null, -32600, 'Invalid Request.');
            if ($res !== null) {
                $responses[] = $res;
            }
        }

        if ($responses === []) {
            // Que des notifications → 202 sans corps.
            http_response_code(202);
            exit;
        }
        if ($rateLimited) {
            http_response_code(429);
            header('Retry-After: ' . $retryAfter);
        }
        $this->send($isBatch ? $responses : $responses[0]);
    }

    /**
     * @param array<string,mixed> $req
     *
     * @return array<string,mixed>|null null pour une notification
     */
    private function handle(array $req, float $start): ?array
    {
        $id = $req['id'] ?? null;
        $method = (string) ($req['method'] ?? '');
        $isNotification = !array_key_exists('id', $req);

        switch ($method) {
            case 'initialize':
                return $this->result($id, [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'Fondouk', 'version' => '0.1.0'],
                    'instructions' => $this->module->l('READ-ONLY access to the merchant\'s public catalog. Use search_catalog to discover products by keywords, get_product for the details of a specific product, lookup_catalog to fetch several products by identifiers. Prices are public (anonymous visitor), tax included according to the shop\'s default country.'),
                ]);

            case 'notifications/initialized':
                return null; // notification

            case 'ping':
                return $this->result($id, new stdClass());

            case 'tools/list':
                return $this->result($id, ['tools' => $this->tools()]);

            case 'tools/call':
                return $this->callTool($id, is_array($req['params'] ?? null) ? $req['params'] : [], $start);

            default:
                if ($isNotification) {
                    return null;
                }

                return $this->error($id, -32601, 'Method not found: ' . $method . '. Supported: initialize, tools/list, tools/call, ping.');
        }
    }

    /** Définitions d'outils — noms explicites, « quand l'utiliser », schémas d'entrée. */
    private function tools(): array
    {
        $context = [
            'type' => 'object',
            'description' => $this->module->l('Optional localization: language (BCP 47), currency (ISO 4217), address_country (ISO 3166-1 alpha-2).'),
            'properties' => [
                'language' => ['type' => 'string'],
                'currency' => ['type' => 'string'],
                'address_country' => ['type' => 'string'],
            ],
        ];

        return [
            [
                'name' => 'search_catalog',
                'description' => $this->module->l('Searches products in the merchant\'s PUBLIC catalog by free text and optional filters (categories, price). Use it when you do NOT have a product identifier — e.g. "blue running shoes under 100 €". Returns products with prices (tax incl.), variants and availability. Paginated (cursor). Read-only.'),
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => $this->module->l('Free search text.')],
                        'filters' => ['type' => 'object', 'description' => $this->module->l('e.g. {"categories":["3"],"price":{"max":10000}} (price in minor units).')],
                        'pagination' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer'], 'cursor' => ['type' => 'string']]],
                        'context' => $context,
                    ],
                ],
            ],
            [
                'name' => 'get_product',
                'description' => $this->module->l('Fetches the full details of ONE product by its identifier (obtained via search_catalog or lookup_catalog). Use it when the customer wants the details, variants or availability of a specific product. Returns a clear "not_found" error if the identifier matches no public product. Read-only.'),
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => ['id' => ['type' => 'string', 'description' => $this->module->l('Product or variant identifier.')], 'context' => $context],
                ],
            ],
            [
                'name' => 'lookup_catalog',
                'description' => $this->module->l('Fetches several products by identifiers in a single call (batch). Use it when you ALREADY have product/variant identifiers and want their current price/availability. Max 100 identifiers. Read-only.'),
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['ids'],
                    'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'string']], 'context' => $context],
                ],
            ],
        ];
    }

    private function callTool($id, array $params, float $start): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $ctx = is_array($args['context'] ?? null) ? $args['context'] : [];

        $locale = SearchLocale::resolve((string) ($ctx['language'] ?? ''), (string) ($ctx['currency'] ?? ''), (string) ($ctx['address_country'] ?? ''));
        $source = new PrestaShopCatalogSource($this->context, $locale);
        $mapper = new UcpCatalogMapper(
            strtolower((string) Language::getIsoById($locale->idLang)),
            strtoupper((string) (new Currency($locale->idCurrency))->iso_code)
        );

        switch ($name) {
            case 'search_catalog':
                $pg = is_array($args['pagination'] ?? null) ? $args['pagination'] : [];
                $graph = $source->search(new SearchQuery(
                    query: isset($args['query']) ? (string) $args['query'] : null,
                    filters: is_array($args['filters'] ?? null) ? $args['filters'] : [],
                    pagination: new Pagination((int) ($pg['limit'] ?? Pagination::DEFAULT_LIMIT), $pg['cursor'] ?? null),
                ));
                $ucp = $mapper->searchResponse($graph);
                $text = sprintf($this->module->l('%d product(s) found.'), count($ucp['products']));
                $cap = UcpCatalogMapper::CAP_SEARCH;
                $count = count($ucp['products']);
                break;

            case 'lookup_catalog':
                $ids = array_values(array_filter(array_map('strval', (array) ($args['ids'] ?? []))));
                if (count($ids) > 100) {
                    return $this->toolError($id, $this->module->l('Too many identifiers (max 100). Shorten the list and try again.'));
                }
                $ucp = $mapper->lookupResponse($source->lookup(new LookupQuery($ids)));
                $text = sprintf($this->module->l('%d product(s) resolved.'), count($ucp['products']));
                $cap = UcpCatalogMapper::CAP_LOOKUP;
                $count = count($ucp['products']);
                break;

            case 'get_product':
                $pid = (string) ($args['id'] ?? '');
                if ($pid === '') {
                    return $this->toolError($id, $this->module->l('Missing "id" parameter. Provide a product identifier obtained via search_catalog.'));
                }
                $graph = $source->getProduct(new GetProductQuery($pid));
                if ($graph === null) {
                    return $this->toolError($id, sprintf($this->module->l('No public product matches the identifier "%s". Use search_catalog to find products, then retry with a returned identifier.'), $pid));
                }
                $ucp = $mapper->getProductResponse($graph);
                $text = sprintf($this->module->l('Product: %s'), $ucp['product']['title'] ?? $pid);
                $cap = UcpCatalogMapper::CAP_LOOKUP;
                $count = 1;
                break;

            default:
                return $this->error($id, -32602, sprintf($this->module->l('Unknown tool: "%s". Available tools: search_catalog, get_product, lookup_catalog.'), $name));
        }

        AccessLogger::log($this->context, 'mcp', $cap, 200, $count, (int) round((microtime(true) - $start) * 1000), $locale->idLang, $locale->idCurrency);

        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => $ucp,
            'isError' => false,
        ]);
    }

    /** Erreur d'OUTIL (isError=true) : l'agent peut la lire et se corriger (vs erreur protocole). */
    private function toolError($id, string $message): array
    {
        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ]);
    }

    private function result($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function send($payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->ajaxRender(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        exit;
    }
}
