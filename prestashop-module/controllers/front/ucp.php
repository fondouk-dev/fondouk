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
        $profile = new PrestaShopShopProfileSource($this->context);
        $base = rtrim($this->context->shop->getBaseURL(true, true), '/');

        $builder = new UcpManifestBuilder($profile, [
            'rest' => $base . '/fondouk/ucp',
            'mcp' => $base . '/fondouk/ucp/mcp',
        ]);
        $json = $builder->toJson();
        $etag = '"' . md5($json) . '"';

        $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) : '';

        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=300');
        header('ETag: ' . $etag);

        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            header('HTTP/1.1 304 Not Modified');
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo $json;
        exit;
    }
}
