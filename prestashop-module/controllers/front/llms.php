<?php
/**
 * Contrôleur llms.txt (spec §8ter) — pont de découverte inter-écosystèmes.
 * Markdown auto-généré par introspection, pointant vers le manifest UCP.
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

class FondoukLlmsModuleFrontController extends ModuleFrontController
{
    public $ssl = false;

    public function canonicalRedirection($canonicalURL = '')
    {
    }

    public function setMedia()
    {
        return true;
    }

    public function initContent()
    {
        $shop = (new PrestaShopShopProfileSource($this->context))->shop();
        $base = $shop->baseUrl;
        $name = $shop->name ?: 'Boutique';

        $txt = '# ' . $name . "\n\n"
            . "> Cette boutique est interrogeable par des agents IA via des interfaces structurées.\n\n"
            . '- Langues : ' . implode(', ', $shop->languages) . "\n"
            . '- Devises : ' . implode(', ', $shop->currencies) . "\n\n"
            . "## Interfaces agent\n\n"
            . '- Manifest UCP (catalogue interrogeable en temps réel — prix, stock) : ' . $base . "/.well-known/ucp\n"
            . '- Documentation du protocole UCP : https://ucp.dev/' . UcpManifestBuilder::UCP_VERSION . "/\n";

        header('Content-Type: text/markdown; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        header('Access-Control-Allow-Origin: *');
        echo $txt;
        exit;
    }
}
