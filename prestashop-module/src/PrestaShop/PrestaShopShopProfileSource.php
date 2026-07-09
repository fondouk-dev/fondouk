<?php
/**
 * Fondouk — AI Agent Ready for PrestaShop (UCP, MCP).
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace PrestaShop\Module\Fondouk\PrestaShop;

use Fondouk\CapabilityCore\Contract\ShopProfileSource;
use Fondouk\CapabilityCore\Graph\Shop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Implémentation PrestaShop du port ShopProfileSource : construit l'identité et
 * les axes de présentation de la boutique par introspection (langues, devises,
 * pays par défaut, TTC). Aucune donnée non publique.
 */
final class PrestaShopShopProfileSource implements ShopProfileSource
{
    public function __construct(private readonly \Context $context)
    {
    }

    public function shop(): Shop
    {
        $shop = $this->context->shop;
        $base = rtrim((string) $shop->getBaseURL(true, true), '/');
        $host = (string) parse_url($base, PHP_URL_HOST);

        $languages = [];
        foreach (\Language::getLanguages(true, (int) $shop->id) as $lang) {
            $languages[] = strtolower((string) $lang['iso_code']);
        }

        $currencies = [];
        foreach (\Currency::getCurrencies(false, true) as $currency) {
            $currencies[] = strtoupper((string) $currency['iso_code']);
        }

        $defaultLang = strtolower((string) \Language::getIsoById((int) \Configuration::get('PS_LANG_DEFAULT')));
        $defaultCurrency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        $defaultCountryIso = \Country::getIsoById((int) \Configuration::get('PS_COUNTRY_DEFAULT'));

        return new Shop(
            id: (string) $shop->id,
            baseUrl: $base,
            defaultLanguage: $defaultLang !== '' ? $defaultLang : 'en',
            defaultCurrency: strtoupper((string) $defaultCurrency->iso_code) ?: 'EUR',
            languages: array_values(array_unique($languages)),
            currencies: array_values(array_unique($currencies)),
            gidBase: $host !== '' ? $host : null,
            name: $shop->name ?: null,
            defaultCountry: $defaultCountryIso ? strtoupper((string) $defaultCountryIso) : null,
            pricesIncludeTax: (bool) \Configuration::get('PS_TAX'),
        );
    }

    /**
     * Capabilities OPÉRATIONNELLES uniquement — principe « a manifest is a promise » :
     * le manifest décrit ce qui EST, pas ce qui est prévu. Tant qu'un endpoint est un
     * stub, sa capability n'est PAS déclarée. Cette liste sera remplie quand
     * PrestaShopCatalogSource + les endpoints catalogue seront réellement opérationnels.
     *
     * @var list<string>
     */
    private const OPERATIONAL_CAPABILITIES = [
        'dev.ucp.shopping.catalog.search',
        'dev.ucp.shopping.catalog.lookup',
    ];

    /** @return list<string> */
    public function enabledTransports(): array
    {
        // Un transport n'est annoncé que s'il porte au moins une capability opérationnelle
        // ET qu'il répond réellement (véracité) : REST + MCP sont tous deux implémentés et prouvés.
        // Dérivé des capabilities réelles (pas d'une constante en dur) → reste honnête si la liste se vide.
        return $this->enabledCapabilities() === [] ? [] : ['rest', 'mcp'];
    }

    /** @return list<string> */
    public function enabledCapabilities(): array
    {
        return self::OPERATIONAL_CAPABILITIES;
    }
}
