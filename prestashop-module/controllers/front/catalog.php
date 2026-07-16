<?php
/**
 * Endpoints catalogue REST UCP : search / lookup / product.
 * Introspection réelle (PrestaShopCatalogSource, filtre anonyme §5) → Capability Graph
 * → réponses UCP (UcpCatalogMapper, schémas 2026-04-08).
 *
 * Durcissement (spec §4.4 / §2.3) : rate limiting (429 + Retry-After), cache de réponses
 * invalidé par hooks (HIT/MISS), et validation de pagination (modèle d'erreur 2 couches :
 * erreurs transport = HTTP 4xx ; résultats métier = 200 + enveloppe UCP). Lecture seule.
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
use Fondouk\CapabilityCore\Graph\CapabilityGraph;
use Fondouk\CapabilityCore\Query\GetProductQuery;
use Fondouk\CapabilityCore\Query\LookupQuery;
use Fondouk\CapabilityCore\Query\Pagination;
use Fondouk\CapabilityCore\Query\SearchQuery;
use PrestaShop\Module\Fondouk\PrestaShop\AccessLogger;
use PrestaShop\Module\Fondouk\PrestaShop\PrestaShopCatalogSource;
use PrestaShop\Module\Fondouk\PrestaShop\PrestaShopShopProfileSource;
use PrestaShop\Module\Fondouk\PrestaShop\RateLimiter;
use PrestaShop\Module\Fondouk\PrestaShop\RequestGuard;
use PrestaShop\Module\Fondouk\PrestaShop\ResponseCache;
use PrestaShop\Module\Fondouk\PrestaShop\SearchLocale;
use Symfony\Component\HttpFoundation\Request;

class FondoukCatalogModuleFrontController extends ModuleFrontController
{
    public $ssl = false;

    private const MAX_LOOKUP_IDS = 100;
    private const RATE_LIMIT_PER_MIN = 60;
    private const RATE_LIMIT_BURST = 120;

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
        // Action from the route param; on the bare /fondouk/ucp entry (what agentic browsers
        // probe) derive it from the `capability` query param. Default: search.
        $action = (string) Tools::getValue('action', '');
        if ($action === '') {
            $action = $this->actionFromCapability((string) Tools::getValue('capability', ''));
        }

        // --- 1. Rate limiting (erreur transport 429) ---
        $rl = (new RateLimiter(self::RATE_LIMIT_PER_MIN, self::RATE_LIMIT_BURST))->hit((string) Tools::getRemoteAddr());
        if (!$rl['allowed']) {
            header('Retry-After: ' . $rl['retryAfter']);
            $this->respond(429, ['code' => 'rate_limited', 'content' => 'Too many requests.'], $action, null, $start, null, $rl);
        }

        // Garde de taille de corps (Gate 2 F2a).
        [$tooLarge, $body] = RequestGuard::jsonBody();
        if ($tooLarge) {
            $this->respond(413, ['code' => 'request_too_large', 'content' => 'Request body too large (max 256 KiB).'], $action, null, $start, null, $rl);
        }
        if (!is_array($body)) {
            $body = [];
        }

        // GET bridge (v1.2.1, « meet agents where they are »). The canonical UCP transport is
        // POST (unchanged), but 2026 agentic browsers (Comet…) query in GET. We therefore
        // accept query params as a NON-NORMATIVE convenience:
        //   GET /catalog/search?q=…|query=…&limit=…&cursor=…
        //   GET /catalog/product?id=…
        //   GET /catalog/lookup?ids=a,b,c
        // Rate-limit, cache and telemetry are identical to POST.
        if (Request::createFromGlobals()->getMethod() === 'GET') {
            if ($action === 'search') {
                $q = (string) Tools::getValue('q', Tools::getValue('query', ''));
                if ($q !== '' && !isset($body['query'])) {
                    $body['query'] = $q;
                }
                $limit = (int) Tools::getValue('limit', 0);
                if ($limit > 0) {
                    $body['pagination']['limit'] = $limit;
                }
                $cursor = (string) Tools::getValue('cursor', '');
                if ($cursor !== '') {
                    $body['pagination']['cursor'] = $cursor;
                }
            } elseif ($action === 'product') {
                $id = (string) Tools::getValue('id', '');
                if ($id !== '' && !isset($body['id'])) {
                    $body['id'] = $id;
                }
            } elseif ($action === 'lookup') {
                $ids = (string) Tools::getValue('ids', '');
                if ($ids !== '' && !isset($body['ids'])) {
                    $body['ids'] = array_values(array_filter(array_map('trim', explode(',', $ids))));
                }
            }
        }

        $ctx = is_array($body['context'] ?? null) ? $body['context'] : [];

        // --- 2. Validation de pagination / batch (erreurs transport 400) ---
        if ($action === 'search') {
            $cursor = $body['pagination']['cursor'] ?? null;
            if (!$this->validCursor(is_string($cursor) ? $cursor : ($cursor === null ? null : ''))) {
                $this->respond(400, ['code' => 'invalid_cursor', 'content' => 'Malformed pagination cursor.'], $action, null, $start, null, $rl);
            }
        }
        if ($action === 'lookup') {
            $ids = (array) ($body['ids'] ?? []);
            if (count($ids) > self::MAX_LOOKUP_IDS) {
                $this->respond(400, ['code' => 'request_too_large', 'content' => 'Batch exceeds ' . self::MAX_LOOKUP_IDS . ' identifiers.'], $action, null, $start, null, $rl);
            }
        }

        $langIso = (string) ($ctx['language'] ?? '');
        if ($langIso === '') {
            $langIso = (string) Request::createFromGlobals()->headers->get('Accept-Language', '');
        }
        $locale = SearchLocale::resolve($langIso, (string) ($ctx['currency'] ?? ''), (string) ($ctx['address_country'] ?? ''));

        // --- 3. Cache (invalidé par version bumpée par les hooks) ---
        $cache = new ResponseCache();
        $cacheKey = $cache->key($action, [
            'l' => $locale->idLang, 'c' => $locale->idCurrency, 'co' => $locale->idCountry, 's' => (int) $this->context->shop->id,
        ], md5((string) json_encode($body)));

        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            $this->emit($cached, 'HIT', $rl);
            AccessLogger::log($this->context, $action, $this->capabilityFor($action), 200, null, (int) round((microtime(true) - $start) * 1000), $locale->idLang, $locale->idCurrency);
            exit;
        }

        // --- 4. Calcul ---
        $source = new PrestaShopCatalogSource($this->context, $locale);
        $mapper = new UcpCatalogMapper(
            strtolower((string) Language::getIsoById($locale->idLang)),
            strtoupper((string) (new Currency($locale->idCurrency))->iso_code)
        );
        [$response, $capability, $count] = $this->dispatch($action, $body, $source, $mapper);

        $json = (string) json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $cache->set($cacheKey, $json);

        AccessLogger::log($this->context, $action, $capability, 200, $count, (int) round((microtime(true) - $start) * 1000), $locale->idLang, $locale->idCurrency);
        $this->emit($json, 'MISS', $rl);
        exit;
    }

    /** Émet une réponse 200 JSON avec les en-têtes standard. */
    private function emit(string $json, string $cacheState, array $rl): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=60');
        header('Access-Control-Allow-Origin: *');
        header('X-Fondouk-Cache: ' . $cacheState);
        header('X-Fondouk-RateLimit-Remaining: ' . $rl['remaining']);
        $this->ajaxRender($json);
    }

    /** Erreur transport (HTTP 4xx) — modèle à deux couches §2.3. Termine la requête. */
    private function respond(int $status, array $error, string $action, ?string $cap, float $start, ?int $count, array $rl): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('X-Fondouk-RateLimit-Remaining: ' . $rl['remaining']);
        $this->ajaxRender(json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        AccessLogger::log($this->context, $action, $cap, $status, $count, (int) round((microtime(true) - $start) * 1000));
        exit;
    }

    /** Maps a UCP capability id (from ?capability=) to an action; defaults to search. */
    private function actionFromCapability(string $capability): string
    {
        if (stripos($capability, 'product') !== false) {
            return 'product';
        }
        if (stripos($capability, 'lookup') !== false) {
            return 'lookup';
        }

        return 'search';
    }

    private function validCursor(?string $cursor): bool
    {
        if ($cursor === null || $cursor === '') {
            return $cursor === null; // absent = ok ; chaîne vide = invalide
        }
        $decoded = base64_decode($cursor, true);
        if ($decoded === false) {
            return false;
        }
        $json = json_decode($decoded, true);

        return is_array($json) && isset($json['o']) && is_int($json['o']) && $json['o'] >= 0;
    }

    private function capabilityFor(string $action): string
    {
        return $action === 'search' ? UcpCatalogMapper::CAP_SEARCH : UcpCatalogMapper::CAP_LOOKUP;
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return array{0:array<string,mixed>,1:string,2:?int}
     */
    private function dispatch(string $action, array $body, PrestaShopCatalogSource $source, UcpCatalogMapper $mapper): array
    {
        switch ($action) {
            case 'lookup':
                $ids = array_values(array_filter(array_map('strval', (array) ($body['ids'] ?? []))));
                $graph = $source->lookup(new LookupQuery($ids));
                $resp = $mapper->lookupResponse($graph);

                return [$resp, UcpCatalogMapper::CAP_LOOKUP, count($resp['products'])];

            case 'product':
                $graph = $source->getProduct(new GetProductQuery((string) ($body['id'] ?? '')));
                if ($graph === null) {
                    $empty = new CapabilityGraph(
                        shop: (new PrestaShopShopProfileSource($this->context))->shop(),
                        generatedAt: date('c'),
                        products: []
                    );

                    return [$mapper->getProductResponse($empty), UcpCatalogMapper::CAP_LOOKUP, 0];
                }

                return [$mapper->getProductResponse($graph), UcpCatalogMapper::CAP_LOOKUP, 1];

            case 'search':
            default:
                $pg = is_array($body['pagination'] ?? null) ? $body['pagination'] : [];
                $filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];
                $query = new SearchQuery(
                    query: isset($body['query']) ? (string) $body['query'] : null,
                    filters: $filters,
                    pagination: new Pagination((int) ($pg['limit'] ?? Pagination::DEFAULT_LIMIT), $pg['cursor'] ?? null),
                );
                $graph = $source->search($query);
                $resp = $mapper->searchResponse($graph);

                return [$resp, UcpCatalogMapper::CAP_SEARCH, count($resp['products'])];
        }
    }
}
