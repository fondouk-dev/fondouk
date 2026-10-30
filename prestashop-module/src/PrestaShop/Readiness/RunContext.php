<?php
/**
 * Fondouk — AI Agent Ready for PrestaShop (UCP, MCP).
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace PrestaShop\Module\Fondouk\PrestaShop\Readiness;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Fondouk\CapabilityCore\Adapter\Ucp\UcpCatalogMapper;
use Fondouk\CapabilityCore\Contract\CatalogSource;
use PrestaShop\Module\Fondouk\PrestaShop\PrestaShopCatalogSource;
use PrestaShop\Module\Fondouk\PrestaShop\SearchLocale;

/**
 * Contexte FIGÉ d'un passage : boutique, URL, langue, devise, pays, groupe visiteur,
 * affichage des taxes, mode catalogue, visibilité des prix, thème. Fournit l'accès à la
 * vitrine (StorefrontFetcher), à la source exposée S1 (sortie du mappeur UCP) et au budget.
 */
final class RunContext
{
    private float $deadline;
    /** @var callable|null fabrique de source catalogue (tests : injection d'écarts) */
    private $sourceFactory;

    /**
     * @param array<string,mixed> $context valeurs du bloc « context » du rapport (sans storefront_path)
     */
    public function __construct(
        public readonly array $context,
        public readonly StorefrontFetcher $fetcher,
        public readonly Messages $messages,
        public readonly string $moduleName,
        public readonly string $moduleVersion,
        public readonly string $moduleDir,
        float $budgetS,
        private readonly ?\Context $psContext = null,
    ) {
        $this->deadline = microtime(true) + $budgetS;
        $this->fetcher->setDeadline($this->deadline);
    }

    /**
     * Contexte de la boutique courante du back-office. Null si le back-office n'est pas
     * dans le contexte d'UNE boutique (multiboutique « toutes » ou « groupe ») : un
     * diagnostic n'a de sens que pour une boutique précise.
     */
    public static function fromPrestaShop(\Context $ctx, \Module $module, float $budgetS): ?self
    {
        if (\Shop::isFeatureActive() && \Shop::getContext() !== \Shop::CONTEXT_SHOP) {
            return null;
        }
        $shop = $ctx->shop;
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $visitor = (int) \Configuration::get('PS_UNIDENTIFIED_GROUP');
        $group = new \Group($visitor);
        $theme = $shop->theme ? (string) $shop->theme->getName() : '';
        $parent = $shop->theme && method_exists($shop->theme, 'get') ? (string) $shop->theme->get('parent') : '';

        $context = [
            'id_shop' => (int) $shop->id,
            'base_url' => rtrim((string) $shop->getBaseURL(true, true), '/'),
            'language' => strtolower((string) \Language::getIsoById($idLang)),
            'currency' => strtoupper((string) (new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code),
            'country' => strtoupper((string) \Country::getIsoById((int) \Configuration::get('PS_COUNTRY_DEFAULT'))),
            'visitor_group' => $visitor,
            'tax_display' => (int) $group->price_display_method === 1 ? 'tax_excl' : 'tax_incl',
            'catalog_mode' => (bool) \Configuration::get('PS_CATALOG_MODE'),
            'prices_visible' => PrestaShopCatalogSource::pricesVisibleToVisitors(),
            'theme' => $parent !== '' ? $theme . ' (' . $parent . ')' : $theme,
        ];

        return new self(
            $context,
            new StorefrontFetcher($context['base_url'], null, [SelfCheckToken::HEADER => SelfCheckToken::issue((int) $ctx->shop->id, $budgetS)]),
            new Messages($module),
            (string) $module->name,
            (string) $module->version,
            rtrim((string) $module->getLocalPath(), '/'),
            $budgetS,
            // COPIE du contexte : la source catalogue y fixe langue, devise et pays par défaut,
            // ce qui ne doit pas modifier la page du back-office de l'employé.
            clone $ctx,
        );
    }

    public function remainingS(): float
    {
        return $this->deadline - microtime(true);
    }

    public function expired(): bool
    {
        return $this->remainingS() <= 0;
    }

    public function t(string $id, string ...$args): string
    {
        return $this->messages->get($id, ...$args);
    }

    /**
     * Point d'injection RÉSERVÉ AUX TESTS (build/readiness-run.php) : remplace la source
     * catalogue par une source volontairement défectueuse, pour prouver qu'un contrôle sait
     * échouer. Jamais appelé par le module.
     */
    public function setSourceFactory(?callable $factory): void
    {
        $this->sourceFactory = $factory;
    }

    /** S1 : sortie interne, sans cache, exactement ce que REST et MCP sérialisent. */
    public function catalogSource(): CatalogSource
    {
        if ($this->sourceFactory !== null) {
            return ($this->sourceFactory)($this->psContext);
        }
        if ($this->psContext === null) {
            throw new \LogicException('Catalog source requires a PrestaShop context.');
        }

        return new PrestaShopCatalogSource($this->psContext, SearchLocale::resolve('', '', ''));
    }

    public function mapper(): UcpCatalogMapper
    {
        return new UcpCatalogMapper((string) $this->context['language'], (string) $this->context['currency']);
    }

    /** @return array<string,mixed> */
    public function reportContext(): array
    {
        return $this->context + ['storefront_path' => $this->fetcher->path()];
    }
}
