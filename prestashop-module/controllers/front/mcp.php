<?php
/**
 * Serveur MCP lecture seule (JSON-RPC 2.0 / Streamable HTTP) — mêmes capacités
 * catalogue que le REST, MÊME chaîne (PrestaShopCatalogSource → UcpCatalogMapper) et
 * MÊMES gardes (rate limiter + télémétrie) : un agent MCP ne contourne rien.
 *
 * Dual-era (McpEnvelope) : MCP 2026-07-28 et révisions antérieures ; Origin validé (OriginPolicy).
 * Outils : search_catalog, get_product, lookup_catalog, erreurs en clair exploitables par l'agent.
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
use PrestaShop\Module\Fondouk\PrestaShop\ClientIp;
use PrestaShop\Module\Fondouk\PrestaShop\McpEnvelope;
use PrestaShop\Module\Fondouk\PrestaShop\OriginPolicy;
use PrestaShop\Module\Fondouk\PrestaShop\PrestaShopCatalogSource;
use PrestaShop\Module\Fondouk\PrestaShop\RateLimiter;
use PrestaShop\Module\Fondouk\PrestaShop\RequestGuard;
use PrestaShop\Module\Fondouk\PrestaShop\SearchLocale;
use Symfony\Component\HttpFoundation\Request;

class FondoukMcpModuleFrontController extends ModuleFrontController
{
    public $ssl = false;

    /** Indication de fraîcheur (ms) de server/discover et tools/list en 2026-07-28. */
    private const TTL_MS = 300000;
    private const RATE_LIMIT_PER_MIN = 60;
    private const RATE_LIMIT_BURST = 120;
    private const MAX_BATCH = 20;
    /** F-02 : identifiants de produits demandés au plus par requête HTTP, lot compris. */
    private const MAX_IDS_PER_REQUEST = 100;
    /** F-02 : produits qu'une requête HTTP peut faire construire au plus (consultations, pages de recherche, fiches). */
    private const MAX_PRODUCTS_PER_REQUEST = 200;

    public function canonicalRedirection($canonicalURL = '')
    {
    }

    public function setMedia()
    {
        return true;
    }

    public function initContent()
    {
        RequestGuard::closeIfStandby($this->module);
        $start = microtime(true);
        $headers = Request::createFromGlobals()->headers;

        // M4 : Origin validé avant tout traitement, pré-vol compris (rebinding DNS). L'origine
        // acceptée est renvoyée telle quelle en CORS ; sans Origin, aucun en-tête CORS.
        [$originOk, $allowedOrigin] = OriginPolicy::evaluate($headers->get('Origin'), (int) $this->context->shop->id);
        header('Vary: Origin');
        if (!$originOk) {
            http_response_code(403);
            AccessLogger::log($this->context, 'mcp', null, 403, null, (int) round((microtime(true) - $start) * 1000));
            $this->send($this->error(null, -32600, 'Forbidden: requests from this Origin are not allowed by this MCP server.'));
        }
        if ($allowedOrigin !== null) {
            header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        }

        // Transport Streamable HTTP : POST uniquement ; pas de flux SSE ni de session, donc GET
        // et DELETE → 405 ; pré-vol CORS pour les agents dans un navigateur.
        $method = RequestGuard::method();
        if ($method === 'OPTIONS') {
            RequestGuard::sendPreflight('POST, OPTIONS', $allowedOrigin);
        }
        if ($method !== 'POST') {
            http_response_code(405);
            header('Allow: POST, OPTIONS');
            exit;
        }
        $protocolHeader = (string) $headers->get('MCP-Protocol-Version', '');

        // Garde de taille de corps (Gate 2 F2a).
        [$tooLarge, $payload] = RequestGuard::jsonBody();
        if ($tooLarge) {
            http_response_code(413);
            AccessLogger::log($this->context, 'mcp', null, 413, null, (int) round((microtime(true) - $start) * 1000));
            $this->send($this->error(null, -32600, 'Request body too large (max 256 KiB).'));
        }
        if ($payload === null) {
            http_response_code(400);
            $this->send($this->error(null, -32700, 'Parse error: body is not valid JSON.'));
        }

        $isBatch = array_is_list($payload) && $payload !== [];
        $requests = $isBatch ? $payload : [$payload];

        // M1 : ère et en-têtes. En 2026-07-28, une seule requête par POST (pas de lot) ; les lots
        // restent servis aux clients anciens, dans les limites ci-dessous.
        if ($isBatch) {
            foreach ($requests as $req) {
                if (McpEnvelope::claimsModern($req, $protocolHeader)) {
                    http_response_code(400);
                    $this->send($this->error(null, -32600, 'Invalid Request: JSON-RPC batches are not part of MCP ' . McpEnvelope::MODERN . '; send one request per HTTP POST.'));
                }
            }
            $envelope = McpEnvelope::analyze([], $protocolHeader, null, null);
        } else {
            $envelope = McpEnvelope::analyze($payload, $protocolHeader, $headers->get('Mcp-Method'), $headers->get('Mcp-Name'));
        }
        if ($envelope['error'] !== null) {
            http_response_code($envelope['status']);
            $res = ['jsonrpc' => '2.0', 'id' => $isBatch ? null : ($payload['id'] ?? null), 'error' => $envelope['error']];
            if ($res['id'] === null) {
                unset($res['id']);
            }
            $this->send($res);
        }
        $era = $envelope['era'];

        // Cap de batch (Gate 2 F2b) : borne le travail d'une seule requête HTTP.
        if (count($requests) > self::MAX_BATCH) {
            http_response_code(400);
            $this->send($this->error(null, -32600, 'Batch too large (max ' . self::MAX_BATCH . ' requests per call).'));
        }
        // F-02 : borne globale du travail d'une requête HTTP. Sans elle, un lot de 20 appels
        // lookup_catalog de 100 identifiants construirait 2 000 produits en une requête, et un lot
        // de 20 recherches de 50 résultats, 1 000.
        $work = array_map(fn ($req) => is_array($req) ? $this->workOf($req) : [0, 0], $requests);
        if (array_sum(array_column($work, 0)) > self::MAX_IDS_PER_REQUEST) {
            http_response_code(400);
            $this->send($this->error(null, -32600, 'Too many product identifiers in this HTTP request (max ' . self::MAX_IDS_PER_REQUEST . ' in total across the batch). Split the lookups across several requests.'));
        }
        if (array_sum(array_column($work, 1)) > self::MAX_PRODUCTS_PER_REQUEST) {
            http_response_code(400);
            $this->send($this->error(null, -32600, 'Too many products requested in this HTTP request (max ' . self::MAX_PRODUCTS_PER_REQUEST . ' across the batch: lookup identifiers, search page sizes, get_product calls). Split the batch across several requests.'));
        }

        // Rate limiting PAR SOUS-REQUÊTE : un batch ne contourne pas le limiteur (Gate 2 F2b).
        $limiter = new RateLimiter(self::RATE_LIMIT_PER_MIN, self::RATE_LIMIT_BURST);
        $ip = ClientIp::forRateLimit();
        $responses = [];
        $rateLimited = false;
        $retryAfter = 1;

        foreach ($requests as $i => $req) {
            $id = is_array($req) ? ($req['id'] ?? null) : null;
            // Coût proportionnel : 1 jeton par appel, 1 par tranche de 10 produits demandés.
            $rl = $limiter->hit($ip, RateLimiter::costForIds($work[$i][1]));
            if (!$rl['allowed']) {
                $rateLimited = true;
                $retryAfter = $rl['retryAfter'];
                $responses[] = $this->error($id, $era === 'modern' ? McpEnvelope::RATE_LIMITED : -32000, 'Rate limit exceeded', ['retry_after' => $rl['retryAfter']]);
                continue;
            }
            $res = is_array($req) ? $this->handle($req, $start, $era) : $this->error(null, -32600, 'Invalid Request.');
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
        } elseif ($era === 'modern' && ($responses[0]['error']['code'] ?? null) === -32601) {
            http_response_code(404); // 2026-07-28 : méthode non implémentée → 404 Not Found + -32601
        }
        $this->send($isBatch ? $responses : $responses[0]);
    }

    /**
     * @param array<string,mixed> $req
     *
     * @return array<string,mixed>|null null pour une notification
     */
    private function handle(array $req, float $start, string $era): ?array
    {
        $id = $req['id'] ?? null;
        $method = (string) ($req['method'] ?? '');
        $isNotification = !array_key_exists('id', $req);

        if ($era === 'modern') {
            return $this->handleModern($req, $id, $method, $isNotification, $start);
        }

        switch ($method) {
            case 'initialize':
                return $this->result($id, [
                    'protocolVersion' => $this->negotiatedLegacyVersion($req),
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => $this->serverInfo(),
                    'instructions' => $this->instructions(),
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

    /**
     * MCP 2026-07-28 : requêtes sans état. Chaque résultat porte `resultType` et l'identité du
     * serveur dans `_meta` ; server/discover et tools/list portent des indications de cache
     * (catalogue public, aucune donnée propre au client : portée « public »).
     */
    private function handleModern(array $req, $id, string $method, bool $isNotification, float $start): ?array
    {
        if ($isNotification) {
            return null;
        }
        switch ($method) {
            case 'server/discover':
                return $this->result($id, $this->modernResult([
                    'supportedVersions' => McpEnvelope::supported(),
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'instructions' => $this->instructions(),
                    'ttlMs' => self::TTL_MS,
                    'cacheScope' => 'public',
                ]));

            case 'tools/list':
                return $this->result($id, $this->modernResult(['tools' => $this->tools(), 'ttlMs' => self::TTL_MS, 'cacheScope' => 'public']));

            case 'tools/call':
                $res = $this->callTool($id, is_array($req['params'] ?? null) ? $req['params'] : [], $start);
                if (isset($res['result'])) {
                    $res['result'] = $this->modernResult($res['result']);
                }

                return $res;

            default:
                return $this->error($id, -32601, 'Method not found: ' . $method . '. Supported in ' . McpEnvelope::MODERN . ': server/discover, tools/list, tools/call.');
        }
    }

    /**
     * Négociation `initialize` (2025-03-26, 2025-06-18) : la version demandée si elle est servie,
     * sinon la plus récente des révisions anciennes servies.
     */
    private function negotiatedLegacyVersion(array $req): string
    {
        $asked = $req['params']['protocolVersion'] ?? null;

        return is_string($asked) && in_array($asked, McpEnvelope::LEGACY, true) ? $asked : McpEnvelope::LEGACY[0];
    }

    /** @param array<string,mixed> $result */
    private function modernResult(array $result): array
    {
        return ['resultType' => 'complete'] + $result + ['_meta' => ['io.modelcontextprotocol/serverInfo' => $this->serverInfo()]];
    }

    /** @return array{name:string,version:string} */
    private function serverInfo(): array
    {
        return ['name' => 'Fondouk', 'version' => (string) $this->module->version];
    }

    private function instructions(): string
    {
        return $this->plain($this->module->l('READ-ONLY access to the merchant\'s public catalog. Use search_catalog to discover products by keywords, get_product for the details of a specific product, lookup_catalog to fetch several products by identifiers. Prices are public (anonymous visitor), tax included according to the shop\'s default country.'));
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

        $tools = [
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

        // Titres lisibles et annotations (MCP 2025-06-18) : outils en lecture seule, domaine
        // fermé (le catalogue de la boutique). Textes en clair, sans entités HTML (cf. plain()).
        $titles = [
            'search_catalog' => $this->module->l('Search the catalog'),
            'get_product' => $this->module->l('Get a product'),
            'lookup_catalog' => $this->module->l('Look up products'),
        ];
        foreach ($tools as $i => $tool) {
            $tools[$i]['title'] = $this->plain($titles[$tool['name']]);
            $tools[$i]['description'] = $this->plain($tool['description']);
            $tools[$i]['inputSchema'] = $this->plainDescriptions($tool['inputSchema']);
            $tools[$i]['annotations'] = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        }

        return $tools;
    }

    private function callTool($id, array $params, float $start): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = $this->toolArguments($params);
        if ($args === null) {
            return $this->toolError($id, $this->module->l('The "catalog" argument must be an object containing the tool parameters.'));
        }
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
                $cap = UcpCatalogMapper::CAP_SEARCH;
                $count = count($ucp['products']);
                break;

            case 'lookup_catalog':
                $ids = self::lookupIds($args);
                if (count($ids) > 100) {
                    return $this->toolError($id, $this->module->l('Too many identifiers (max 100). Shorten the list and try again.'));
                }
                $ucp = $mapper->lookupResponse($source->lookup(new LookupQuery($ids)));
                $cap = UcpCatalogMapper::CAP_LOOKUP;
                $count = count($ucp['products']);
                break;

            case 'get_product':
                $pid = (string) ($args['id'] ?? '');
                if ($pid === '') {
                    return $this->toolError($id, $this->module->l('Missing "id" parameter. Provide a product identifier obtained via search_catalog.'));
                }
                $graph = $source->getProduct(new GetProductQuery($pid));
                // Produit introuvable, ou exclu par l'adapter (prix masqué) : erreur d'outil.
                $ucp = $graph !== null ? $mapper->getProductResponse($graph) : [];
                if (!isset($ucp['product']) && $source->pricesHidden()) {
                    return $this->toolError($id, $this->module->l('This merchant does not display prices to anonymous visitors, so its products are not exposed to agents.'));
                }
                if (!isset($ucp['product'])) {
                    return $this->toolError($id, sprintf($this->module->l('No public product matches the identifier "%s". Use search_catalog to find products, then retry with a returned identifier.'), $pid));
                }
                $cap = UcpCatalogMapper::CAP_LOOKUP;
                $count = 1;
                break;

            default:
                return $this->error($id, -32602, $this->plain(sprintf($this->module->l('Unknown tool: "%s". Available tools: search_catalog, get_product, lookup_catalog.'), $name)));
        }

        if ($source->pricesHidden()) {
            $ucp = UcpCatalogMapper::withPricesHiddenNotice($ucp);
        }

        AccessLogger::log($this->context, 'mcp', $cap, 200, $count, (int) round((microtime(true) - $start) * 1000), $locale->idLang, $locale->idCurrency);

        return $this->result($id, [
            // Texte = JSON sérialisé de structuredContent (MCP 2025-06-18, SHOULD) : un client qui
            // ne lit que le texte reçoit exactement les mêmes données.
            'content' => [['type' => 'text', 'text' => (string) json_encode($ucp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            'structuredContent' => $ucp,
            'isError' => false,
        ]);
    }

    /** Erreur d'OUTIL (isError=true) : l'agent peut la lire et se corriger (vs erreur protocole). */
    /**
     * Arguments d'un appel d'outil. Binding MCP d'UCP (2026-04-08) : paramètres sous `catalog`,
     * métadonnées sous `meta` (corrigé en 2.0.0). La forme à plat historique reste acceptée ;
     * si les deux sont fournies, `catalog` l'emporte. `meta` n'est pas exploité ici.
     *
     * @return array<string,mixed>|null null si `catalog` n'est pas un objet
     */
    private function toolArguments(array $params): ?array
    {
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if (array_key_exists('catalog', $args)) {
            if (!is_array($args['catalog'])) {
                return null;
            }
            $catalog = $args['catalog'];
            unset($args['catalog']);
            $args = array_merge($args, $catalog);
        }
        unset($args['meta']);

        return $args;
    }

    /** @return list<string> */
    private static function lookupIds(array $args): array
    {
        return array_values(array_filter(array_map('strval', (array) ($args['ids'] ?? []))));
    }

    /**
     * Travail qu'un appel fera réellement faire (F-02) : [identifiants consultés, produits
     * construits]. lookup_catalog : ses identifiants (0 au-delà de 100 : refusé sans travail) ;
     * search_catalog : sa taille de page bornée ; get_product : 1 ; tout autre appel : rien.
     *
     * @return array{0:int,1:int}
     */
    private function workOf(array $req): array
    {
        $params = is_array($req['params'] ?? null) ? $req['params'] : [];
        $args = ($req['method'] ?? null) === 'tools/call' ? $this->toolArguments($params) : null;
        if ($args === null) {
            return [0, 0];
        }
        switch ($params['name'] ?? null) {
            case 'lookup_catalog':
                $n = count(self::lookupIds($args));

                return $n > 100 ? [0, 0] : [$n, $n];
            case 'search_catalog':
                $pg = is_array($args['pagination'] ?? null) ? $args['pagination'] : [];

                return [0, (new Pagination((int) ($pg['limit'] ?? Pagination::DEFAULT_LIMIT)))->clampedLimit()];
            case 'get_product':
                return [0, 1];
            default:
                return [0, 0];
        }
    }

    private function toolError($id, string $message): array
    {
        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => $this->plain($message)]],
            'isError' => true,
        ]);
    }

    /**
     * Texte d'un message traduit, sans entités HTML : Module::l() échappe pour le HTML, or ces
     * textes partent en JSON vers un agent (écart M11). Ne s'applique jamais aux données produit.
     */
    private function plain(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param array<string,mixed> $schema
     *
     * @return array<string,mixed>
     */
    private function plainDescriptions(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if ($key === 'description' && is_string($value)) {
                $schema[$key] = $this->plain($value);
            } elseif (is_array($value)) {
                $schema[$key] = $this->plainDescriptions($value);
            }
        }

        return $schema;
    }

    private function result($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** Identifiant illisible ou inconnu (erreur de transport) : la réponse n'a pas d'`id` (MCP 2026-07-28). */
    private function error($id, int $code, string $message, ?array $data = null): array
    {
        $e = ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message] + ($data !== null ? ['data' => $data] : [])];
        if ($id === null) {
            unset($e['id']);
        }

        return $e;
    }

    private function send($payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store'); // réponses porteuses de prix : jamais en cache HTTP externe
        $this->ajaxRender(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        exit;
    }
}
