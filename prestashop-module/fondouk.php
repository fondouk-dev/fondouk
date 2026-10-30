<?php
/**
 * Fondouk — AI Agent Ready for PrestaShop (UCP, MCP).
 * Read-only, zero configuration. Compatible with PS 8.x and 9.x.
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 */

use PrestaShop\Module\Fondouk\PrestaShop\Readiness\FreeChecks;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\Messages;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\ReadinessRunner;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\RunContext;
use PrestaShop\Module\Fondouk\PrestaShop\ResponseCache;
use PrestaShop\Module\Fondouk\PrestaShop\TelemetryBudget;
use PrestaShop\Module\Fondouk\PrestaShop\TrustedProxies;

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
        $this->version = '2.0.0';
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
        // Coexistence : si Fondouk Pro est installé, la table d'accès partagée, le salt IP
        // et le compteur de version lui servent ENCORE. On ne détruit donc rien tant que le
        // Pro est là — sinon désinstaller le gratuit effacerait la télémétrie et le salt du Pro
        // (qui, lui, ne purge jamais à sa propre désinstallation : migration-safe, purge
        // explicite via l'écran Conformité). La garde n'est donc nécessaire que côté gratuit.
        if (Module::isInstalled(self::PRO_MODULE)) {
            return parent::uninstall();
        }

        return $this->uninstallDb()
            && Configuration::deleteByName('FONDOUK_IP_SALT')
            && Configuration::deleteByName('FONDOUK_CATALOG_VERSION')
            && Configuration::deleteByName('FONDOUK_LAST_PURGE')
            && Configuration::deleteByName('FONDOUK_READINESS_LOCK')
            && Configuration::deleteByName('FONDOUK_READINESS_SELFCHECK')
            && Configuration::deleteByName('FONDOUK_TRUSTED_PROXIES')
            && Configuration::deleteByName('FONDOUK_PROXY_HINT_AT')
            && Configuration::deleteByName('FONDOUK_PROXY_HINT_FROM')
            && parent::uninstall();
    }

    /** Hooks dont le déclenchement invalide le cache catalogue (spec §4.4). */
    public const CACHE_INVALIDATION_HOOKS = [
        'actionProductSave',
        'actionProductDelete',
        'actionUpdateQuantity',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
        // R3-09 : visibilité (catégories et leurs groupes), réductions de groupe, taxes, devises,
        // règles de prix catalogue et déclinaisons changent aussi ce que voient les agents.
        'actionObjectCategoryAddAfter',
        'actionObjectCategoryUpdateAfter',
        'actionObjectCategoryDeleteAfter',
        'actionObjectGroupAddAfter',
        'actionObjectGroupUpdateAfter',
        'actionObjectGroupDeleteAfter',
        'actionObjectGroupReductionAddAfter',
        'actionObjectGroupReductionUpdateAfter',
        'actionObjectGroupReductionDeleteAfter',
        'actionObjectTaxAddAfter',
        'actionObjectTaxUpdateAfter',
        'actionObjectTaxDeleteAfter',
        'actionObjectTaxRuleAddAfter',
        'actionObjectTaxRuleUpdateAfter',
        'actionObjectTaxRuleDeleteAfter',
        'actionObjectTaxRulesGroupAddAfter',
        'actionObjectTaxRulesGroupUpdateAfter',
        'actionObjectTaxRulesGroupDeleteAfter',
        'actionObjectCurrencyUpdateAfter',
        'actionObjectSpecificPriceRuleAddAfter',
        'actionObjectSpecificPriceRuleUpdateAfter',
        'actionObjectSpecificPriceRuleDeleteAfter',
        'actionObjectCombinationAddAfter',
        'actionObjectCombinationUpdateAfter',
        'actionObjectCombinationDeleteAfter',
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

    public function hookActionObjectCategoryAddAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectCategoryUpdateAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectCategoryDeleteAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectGroupAddAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectGroupUpdateAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectGroupDeleteAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectGroupReductionAddAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectGroupReductionUpdateAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectGroupReductionDeleteAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectTaxAddAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectTaxUpdateAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectTaxDeleteAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectTaxRuleAddAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectTaxRuleUpdateAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectTaxRuleDeleteAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectTaxRulesGroupAddAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectTaxRulesGroupUpdateAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectTaxRulesGroupDeleteAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectCurrencyUpdateAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectSpecificPriceRuleAddAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectSpecificPriceRuleUpdateAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectSpecificPriceRuleDeleteAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectCombinationAddAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectCombinationUpdateAfter(): void
    {
        ResponseCache::bump();
    }

    public function hookActionObjectCombinationDeleteAfter(): void
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
            && (bool) Db::getInstance()->execute($rateLimit)
            && (bool) Db::getInstance()->execute(TelemetryBudget::createTableSql());
    }

    protected function uninstallDb(): bool
    {
        return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'fondouk_access_log`;')
            && (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'fondouk_rate_limit`;')
            // Table d'agrégats partagée avec le Pro (qui y garde ses totaux) : laissée au Pro.
            && (Module::isInstalled('fondoukpro')
                || (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . TelemetryBudget::TABLE . '`;'));
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
            // Bare REST base (v1.2.1): GET /fondouk/ucp?capability=…&q=… — the entry agentic
            // browsers probe. No action → the catalog controller derives it from ?capability.
            'module-fondouk-ucp-base' => [
                'rule' => 'fondouk/ucp',
                'keywords' => [],
                'controller' => 'catalog',
                'params' => $mod,
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
        $proActive = Module::isInstalled(self::PRO_MODULE) && Module::isEnabled(self::PRO_MODULE);
        [$report, $readinessError] = $proActive ? [null, ''] : $this->runReadinessIfRequested();
        [$proxiesStatus, $proxiesRejected] = $proActive ? ['', []] : $this->saveTrustedProxiesIfRequested();
        $proxyHint = TrustedProxies::hint();

        $this->context->smarty->assign([
            'fondouk_readiness_rows' => $report !== null ? $this->readinessRows($report) : [],
            'fondouk_readiness_report' => $report,
            'fondouk_readiness_error' => $readinessError,
            'fondouk_readiness_token' => Tools::getAdminTokenLite('AdminModules'),
            'fondouk_readiness_action' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]),
            'fondouk_https' => parse_url($base, PHP_URL_SCHEME) === 'https',
            'fondouk_rewriting' => (bool) Configuration::get('PS_REWRITING_SETTINGS'),
            'fondouk_conflict' => is_dir($wellKnownDir) || file_exists($wellKnownDir . '/ucp'),
            'fondouk_autoload' => is_file(__DIR__ . '/vendor/autoload.php'),
            'fondouk_manifest_url' => $base . '/.well-known/ucp',
            'fondouk_llms_url' => $base . '/llms.txt',
            'fondouk_search_url' => $base . '/fondouk/ucp/catalog/search',
            'fondouk_mcp_url' => $base . '/fondouk/ucp/mcp',
            // Standby: when Fondouk Pro is active, this module defers the whole agent surface.
            'fondouk_pro_active' => $proActive,
            'fondouk_proxies' => implode("\n", TrustedProxies::list()),
            'fondouk_proxies_status' => $proxiesStatus,
            'fondouk_proxies_rejected' => implode(', ', $proxiesRejected),
            'fondouk_proxy_hint' => $proxyHint !== null ? $proxyHint['from'] : '',
        ]);

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/diagnostic.tpl');
    }

    /**
     * Proxys de confiance (facultatif) : enregistrés UNIQUEMENT par un employé autorisé (droit
     * « configurer », jeton anti-CSRF) ; toute entrée invalide refuse l'enregistrement entier.
     *
     * @return array{0:string,1:array<int,string>} [statut ('', 'saved', 'invalid', 'forbidden', 'token'), entrées refusées]
     */
    private function saveTrustedProxiesIfRequested(): array
    {
        if (!Tools::isSubmit('fondouk_save_proxies')) {
            return ['', []];
        }
        if (!$this->getPermission('configure')) {
            return ['forbidden', []];
        }
        if ((string) Tools::getValue('fondouk_token') !== Tools::getAdminTokenLite('AdminModules')) {
            return ['token', []];
        }
        [$valid, $invalid] = TrustedProxies::parse((string) Tools::getValue('fondouk_trusted_proxies', ''));
        if ($invalid !== []) {
            return ['invalid', $invalid];
        }

        return [TrustedProxies::save($valid) ? 'saved' : 'invalid', []];
    }

    /**
     * Commerce Diagnostic essentiel : lancé UNIQUEMENT à la demande d'un employé autorisé
     * (jeton anti-CSRF, droit « configurer »), une passe à la fois par boutique, jamais en
     * contexte « toutes les boutiques ». Lecture seule ; aucun stockage du rapport.
     *
     * @return array{0:?array,1:string} [rapport | null, code d'erreur ('' si aucune)]
     */
    private function runReadinessIfRequested(): array
    {
        if (!Tools::isSubmit('fondouk_run_readiness')) {
            return [null, ''];
        }
        if (!$this->getPermission('configure')) {
            return [null, 'forbidden'];
        }
        if ((string) Tools::getValue('fondouk_token') !== Tools::getAdminTokenLite('AdminModules')) {
            return [null, 'token'];
        }
        $ctx = RunContext::fromPrestaShop($this->context, $this, FreeChecks::BUDGET_S);
        if ($ctx === null) {
            return [null, 'context'];
        }
        $idShop = (int) $this->context->shop->id;
        if (!ReadinessRunner::acquireLock($idShop)) {
            return [null, 'running'];
        }
        try {
            return [(new ReadinessRunner(FreeChecks::all()))->run($ctx, FreeChecks::BUDGET_S, 0, _PS_VERSION_), ''];
        } finally {
            ReadinessRunner::releaseLock($idShop);
        }
    }

    /**
     * Lignes d'affichage du rapport (textes déjà traduits, échappés par le gabarit).
     *
     * @param array<string,mixed> $report
     *
     * @return array<int,array<string,string>>
     */
    private function readinessRows(array $report): array
    {
        $messages = new Messages($this);
        $rows = [];
        foreach ($report['results'] as $r) {
            $rows[] = [
                'id' => (string) $r['check_id'],
                'title' => $messages->get('title_' . $r['check_id']),
                'status' => (string) $r['status'],
                'cause' => (string) $r['cause'],
                'action' => (string) $r['action'],
                'evidence' => $r['evidence'] !== [] ? (string) json_encode($r['evidence'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
            ];
        }

        return $rows;
    }
}
