<?php
/**
 * Contrôleur du manifest UCP servi à /.well-known/ucp (spec §2.1).
 * Headers : Content-Type application/json, Cache-Control public max-age=300, ETag,
 * CORS ouvert pour la découverte. Aucune redirection (canonicalRedirection neutralisée).
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'fondouk/vendor/autoload.php';

use Fondouk\CapabilityCore\Adapter\Ucp\UcpManifestBuilder;
use PrestaShop\Module\Fondouk\PrestaShop\AccessLogger;
use PrestaShop\Module\Fondouk\PrestaShop\PrestaShopShopProfileSource;

class FondoukUcpModuleFrontController extends ModuleFrontController
{
    public $ssl = false;

    /** Endpoint brut : jamais de redirection canonique. */
    public function canonicalRedirection($canonicalURL = '')
    {
    }

    /** Endpoint brut : aucun asset de thème, et on évite les hooks setMedia tiers. */
    public function setMedia()
    {
        return true;
    }

    public function initContent()
    {
        $start = microtime(true);
        $profile = new PrestaShopShopProfileSource($this->context);
        $base = rtrim($this->context->shop->getBaseURL(true, true), '/');

        $builder = new UcpManifestBuilder($profile, [
            'rest' => $base . '/fondouk/ucp',
            'mcp' => $base . '/fondouk/ucp/mcp',
        ]);
        $json = $this->annotateGetConvenience($builder->toJson(), $base);
        $etag = '"' . md5($json) . '"';

        $ifNoneMatch = trim((string) \Symfony\Component\HttpFoundation\Request::createFromGlobals()->headers->get('If-None-Match', ''));

        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=300');
        header('ETag: ' . $etag);

        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            header('HTTP/1.1 304 Not Modified');
            AccessLogger::log($this->context, 'manifest', null, 304, null, (int) round((microtime(true) - $start) * 1000));
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        AccessLogger::log($this->context, 'manifest', null, 200, null, (int) round((microtime(true) - $start) * 1000));
        echo $json;
        exit;
    }

    /**
     * Adds a NON-NORMATIVE GET-convenience hint to the manifest (v1.2.1). Canonical UCP
     * transport stays POST; this advertises the GET query-param convenience so agentic
     * browsers (which query in GET) can find it.
     */
    private function annotateGetConvenience(string $json, string $base): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }
        $data['x-fondouk'] = [
            'get_convenience' => [
                'note' => 'Non-normative convenience. Canonical UCP catalog transport is POST (per spec); these GET forms return the same schema-conformant payloads for GET-oriented agents.',
                'search' => $base . '/fondouk/ucp/catalog/search?q={text}&limit={n}',
                'product' => $base . '/fondouk/ucp/catalog/product?id={id}',
                'lookup' => $base . '/fondouk/ucp/catalog/lookup?ids={a,b,c}',
            ],
        ];

        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
