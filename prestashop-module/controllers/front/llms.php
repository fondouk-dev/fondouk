<?php
/**
 * Contrôleur llms.txt (spec §8ter) — pont de découverte inter-écosystèmes.
 * Markdown auto-généré par introspection, pointant vers le manifest UCP.
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
        $name = $shop->name ?: $this->module->l('Store');

        $txt = '# ' . $name . "\n\n"
            . '> ' . $this->module->l('This store is queryable by AI agents through structured interfaces.') . "\n\n"
            . '- ' . $this->module->l('Languages:') . ' ' . implode(', ', $shop->languages) . "\n"
            . '- ' . $this->module->l('Currencies:') . ' ' . implode(', ', $shop->currencies) . "\n\n"
            . '## ' . $this->module->l('Agent interfaces') . "\n\n"
            . '- ' . $this->module->l('UCP Manifest (real-time queryable catalog — price, stock):') . ' ' . $base . "/.well-known/ucp\n"
            . '- ' . $this->module->l('Catalog search (GET convenience for agentic browsers):') . ' ' . $base . "/fondouk/ucp/catalog/search?q={text}\n"
            . '- ' . $this->module->l('UCP protocol documentation:') . ' https://ucp.dev/' . UcpManifestBuilder::UCP_VERSION . "/\n";

        header('Content-Type: text/markdown; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        header('Access-Control-Allow-Origin: *');
        $this->ajaxRender($txt);
        exit;
    }
}
