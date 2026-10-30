<?php
/**
 * Fondouk — AI Agent Ready for PrestaShop (UCP, MCP).
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace PrestaShop\Module\Fondouk\PrestaShop\Readiness\Check;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Fondouk\CapabilityCore\Query\LookupQuery;
use Fondouk\CapabilityCore\Query\Pagination;
use Fondouk\CapabilityCore\Query\SearchQuery;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\CatalogFacts;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\CheckResult;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\ReadinessCheck;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\RunContext;

/**
 * C9-F — aucun prix n'est exposé quand la boutique le masque (règle F-PRIX-1, même que la
 * vitrine) : boutique entière (groupe visiteur, mode catalogue sans prix) ou produit
 * (show_price = 0). WARN si la géolocalisation passe certains pays en mode catalogue : le
 * pays d'un agent n'est pas connu de façon fiable.
 */
final class HiddenPriceCheck implements ReadinessCheck
{
    public function meta(): array
    {
        return ['id' => 'C9-F', 'version' => '1', 'category' => 'security', 'tier' => 'free', 'severity' => 'high'];
    }

    public function run(RunContext $ctx): CheckResult
    {
        $c = $ctx->context;
        $source = $ctx->catalogSource();
        $mapper = $ctx->mapper();

        if (!$c['prices_visible']) {
            $served = $mapper->searchResponse($source->search(new SearchQuery(query: null, pagination: new Pagination(10, null))))['products'];
            if ($served !== []) {
                return CheckResult::fail($ctx->t('c9_exposed_shop'), $ctx->t('c9_action'), [['served' => count($served)]]);
            }

            return CheckResult::pass([['prices_visible' => false, 'products_served' => 0]], $ctx->t('c9_shop_hidden'));
        }

        $facts = new CatalogFacts((int) $c['id_shop'], (int) $c['visitor_group']);
        $hidden = $facts->hiddenPriceProductIds(50);
        if ($hidden !== []) {
            $served = $mapper->lookupResponse($source->lookup(new LookupQuery(array_map('strval', $hidden))))['products'];
            if ($served !== []) {
                return CheckResult::fail($ctx->t('c9_exposed_products', (string) count($served)), $ctx->t('c9_action'),
                    [['exposed' => array_map(static fn ($p) => (string) $p['id'], array_slice($served, 0, 10))]]);
            }
        }
        if ((bool) \Configuration::get('PS_GEOLOCATION_ENABLED')
            && (int) \Configuration::get('PS_GEOLOCATION_BEHAVIOR') === (int) _PS_GEOLOCATION_NO_CATALOG_) {
            return CheckResult::warn($ctx->t('c9_geolocation'), $ctx->t('c9_geolocation_action'), [['products_with_hidden_price' => count($hidden)]]);
        }

        return CheckResult::pass([['products_with_hidden_price' => count($hidden), 'served' => 0]]);
    }
}
