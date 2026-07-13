<?php
/**
 * Fondouk — AI Agent Ready for PrestaShop (UCP, MCP).
 * Read-only, zero configuration. Compatible with PS 8.x and 9.x.
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 */

use PrestaShop\Module\Fondouk\PrestaShop\ResponseCache;

if (!defined('_PS_VERSION_')) {
    exit;
}

$fondoukAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($fondoukAutoload)) {
    require_once $fondoukAutoload;
}

class Fondouk extends Module
{
    public function __construct()
    {
        $this->name = 'fondouk';
        $this->tab = 'front_office_features';
        $this->version = '1.2.0';
        $this->author = 'Synapsea';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Fondouk — AI Agent Ready (UCP, MCP)');
        $this->description = $this->l('Makes the store visible and queryable by AI agents (UCP, MCP) — read-only, zero configuration.');
        $this->confirmUninstall = $this->l('Uninstall Fondouk? The manifest and agent endpoints will no longer be served.');
    }

    public function install(): bool
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        return parent::install()
            && $this->registerHook('moduleRoutes')
            && $this->registerHook(self::CACHE_INVALIDATION_HOOKS)
            && $this->installDb()
            && $this->initConfig();
    }

    public function uninstall(): bool
    {
        return $this->uninstallDb()
            && Configuration::deleteByName('FONDOUK_IP_SALT')
            && Configuration::deleteByName('FONDOUK_CATALOG_VERSION')
            && parent::uninstall();
    }

    /** Hooks dont le déclenchement invalide le cache catalogue (spec §4.4). */
    private const CACHE_INVALIDATION_HOOKS = [
        'actionProductSave',
        'actionProductDelete',
        'actionUpdateQuantity',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
    ];

    public function hookActionProductSave(): void
    {
        ResponseCache::bump();
    }

    public function hookActionProductDelete(): void
    {
        ResponseCache::bump();
    }

    public function hookActionUpdateQuantity(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectSpecificPriceAddAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectSpecificPriceUpdateAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectSpecificPriceDeleteAfter(): void
    {
        ResponseCache::bump();
    }

    /** Table de télémétrie locale (spec §8bis). Seule écriture runtime du module. */
    protected function installDb(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'fondouk_access_log` (
            `id_access` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `date_add` DATETIME NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `endpoint` VARCHAR(32) NOT NULL,
            `capability` VARCHAR(64) NULL,
            `ip_hash` CHAR(64) NULL,
            `ucp_agent_profile` VARCHAR(255) NULL,
            `user_agent` VARCHAR(255) NULL,
            `id_lang` INT UNSIGNED NULL,
            `id_currency` INT UNSIGNED NULL,
            `http_status` SMALLINT UNSIGNED NULL,
            `response_ms` INT UNSIGNED NULL,
            `result_count` INT UNSIGNED NULL,
            PRIMARY KEY (`id_access`),
            KEY `idx_date` (`date_add`),
            KEY `idx_endpoint` (`endpoint`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        $rateLimit = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'fondouk_rate_limit` (
            `bucket` CHAR(64) NOT NULL,
            `tokens` DOUBLE NOT NULL DEFAULT 0,
            `updated_at` DOUBLE NOT NULL DEFAULT 0,
            PRIMARY KEY (`bucket`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return (bool) Db::getInstance()->execute($sql)
            && (bool) Db::getInstance()->execute($rateLimit);
    }

    protected function uninstallDb(): bool
    {
        return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'fondouk_access_log`;')
            && (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'fondouk_rate_limit`;');
    }

    /** Sel secret pour le hachage d'IP de la télémétrie (jamais d'IP en clair — §8bis). */
    protected function initConfig(): bool
    {
        if (!Configuration::get('FONDOUK_IP_SALT')) {
            Configuration::updateValue('FONDOUK_IP_SALT', Tools::passwdGen(32));
        }
        if (!Configuration::get('FONDOUK_CATALOG_VERSION')) {
            Configuration::updateValue('FONDOUK_CATALOG_VERSION', 1);
        }

        return true;
    }

    /**
     * Routes front (spec §4.2). Le manifest est le SEUL chemin sous /.well-known/ ;
     * les endpoints REST/MCP vivent sous la base module (décision Q7).
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,array<string,mixed>>
     */
    /** Technical name of the paid module that supersedes this one when present. */
    public const PRO_MODULE = 'fondoukpro';

    public function hookModuleRoutes($params)
    {
        // Polite deference (v1.2, coexistence): if Fondouk Pro is installed AND
        // active, it fully serves the agent surface (manifest, catalog, MCP, llms.txt).
        // This module then stands by — it registers NO routes, so there is never a
        // duplicate /.well-known/ucp registration. The reverse never happens: the Pro
        // never defers to the free module. Uninstalling the Pro makes these routes
        // come back automatically (this hook is re-evaluated on route-cache rebuild).
        if (Module::isInstalled(self::PRO_MODULE) && Module::isEnabled(self::PRO_MODULE)) {
            return [];
        }

        $mod = ['fc' => 'module', 'module' => 'fondouk'];

        return [
            'module-fondouk-ucp' => [
                'rule' => '.well-known/ucp',
                'keywords' => [],
                'controller' => 'ucp',
                'params' => $mod,
            ],
            'module-fondouk-llms' => [
                'rule' => 'llms.txt',
                'keywords' => [],
                'controller' => 'llms',
                'params' => $mod,
            ],
            'module-fondouk-catalog-search' => [
                'rule' => 'fondouk/ucp/catalog/search',
                'keywords' => [],
                'controller' => 'catalog',
                'params' => $mod + ['action' => 'search'],
            ],
            'module-fondouk-catalog-lookup' => [
                'rule' => 'fondouk/ucp/catalog/lookup',
                'keywords' => [],
                'controller' => 'catalog',
                'params' => $mod + ['action' => 'lookup'],
            ],
            'module-fondouk-catalog-product' => [
                'rule' => 'fondouk/ucp/catalog/product',
                'keywords' => [],
                'controller' => 'catalog',
                'params' => $mod + ['action' => 'product'],
            ],
            'module-fondouk-mcp' => [
                'rule' => 'fondouk/ucp/mcp',
                'keywords' => [],
                'controller' => 'mcp',
                'params' => $mod,
            ],
        ];
    }

    /** Écran de configuration : diagnostic des prérequis (spec §4.2). Aucun réglage obligatoire. */
    public function getContent(): string
    {
        $base = rtrim($this->context->shop->getBaseURL(true, true), '/');
        $wellKnownDir = rtrim(_PS_ROOT_DIR_, '/') . '/.well-known';

        $this->context->smarty->assign([
            'fondouk_https' => parse_url($base, PHP_URL_SCHEME) === 'https',
            'fondouk_rewriting' => (bool) Configuration::get('PS_REWRITING_SETTINGS'),
            'fondouk_conflict' => is_dir($wellKnownDir) || file_exists($wellKnownDir . '/ucp'),
            'fondouk_autoload' => is_file(__DIR__ . '/vendor/autoload.php'),
            'fondouk_manifest_url' => $base . '/.well-known/ucp',
            'fondouk_llms_url' => $base . '/llms.txt',
            'fondouk_search_url' => $base . '/fondouk/ucp/catalog/search',
            'fondouk_mcp_url' => $base . '/fondouk/ucp/mcp',
            // Standby: when Fondouk Pro is active, this module defers the whole agent surface.
            'fondouk_pro_active' => Module::isInstalled(self::PRO_MODULE) && Module::isEnabled(self::PRO_MODULE),
        ]);

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/diagnostic.tpl');
    }
}
