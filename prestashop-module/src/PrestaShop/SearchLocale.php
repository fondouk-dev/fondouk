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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Résout la langue / devise / pays de présentation depuis les hints de la requête,
 * avec repli sur les défauts boutique (décisions Q3/Q4/Q5) : seules les langues et
 * devises ACTIVES sont retenues ; sinon défaut.
 */
final class SearchLocale
{
    public function __construct(
        public readonly int $idLang,
        public readonly int $idCurrency,
        public readonly int $idCountry,
    ) {
    }

    public static function resolve(string $langIso = '', string $currencyIso = '', string $countryIso = ''): self
    {
        // Langue : active uniquement, sinon défaut boutique.
        $idLang = 0;
        if ($langIso !== '') {
            $iso = strtolower(substr($langIso, 0, 2));
            $candidate = (int) \Language::getIdByIso($iso);
            if ($candidate && (bool) \Language::getLanguage($candidate)['active']) {
                $idLang = $candidate;
            }
        }
        if (!$idLang) {
            $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        }

        // Devise : active uniquement, sinon défaut boutique (Q4).
        $idCurrency = 0;
        if ($currencyIso !== '') {
            $candidate = (int) \Currency::getIdByIsoCode(strtoupper($currencyIso), 0);
            if ($candidate) {
                $cur = new \Currency($candidate);
                if ((bool) $cur->active && !(bool) $cur->deleted) {
                    $idCurrency = $candidate;
                }
            }
        }
        if (!$idCurrency) {
            $idCurrency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        }

        // Pays : hint sinon pays par défaut boutique (Q3, indicatif).
        $idCountry = 0;
        if ($countryIso !== '') {
            $idCountry = (int) \Country::getByIso(strtoupper(substr($countryIso, 0, 2)));
        }
        if (!$idCountry) {
            $idCountry = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        }

        return new self($idLang, $idCurrency, $idCountry);
    }
}
