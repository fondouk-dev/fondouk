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
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\CatalogFacts;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\CheckResult;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\ReadinessCheck;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\RunContext;

/**
 * C3-F — aucun prix RÉSERVÉ (groupe client, B2B, client précis) n'est exposé aux agents.
 *
 * Pour chaque produit portant un prix réservé actif, on calcule depuis S4, par
 * Product::priceCalculation(), le prix anonyme attendu P et l'ensemble R des prix réservés
 * (par groupe concerné et par client). Fuite = le prix exposé (S1, sortie du mappeur) est
 * dans R mais différent de P. Une coïncidence R = P n'est pas une fuite.
 */
final class ReservedPriceCheck implements ReadinessCheck
{
    private const LIMIT = 200;

    public function meta(): array
    {
        return ['id' => 'C3-F', 'version' => '1', 'category' => 'security', 'tier' => 'free', 'severity' => 'critical'];
    }

    public function run(RunContext $ctx): CheckResult
    {
        $c = $ctx->context;
        if (!$c['prices_visible']) {
            return CheckResult::pass([], $ctx->t('c3_prices_hidden'));
        }
        $facts = new CatalogFacts((int) $c['id_shop'], (int) $c['visitor_group']);
        $products = $facts->restrictedPriceProducts(self::LIMIT);
        if ($products === []) {
            return CheckResult::pass([], $ctx->t('c3_none'));
        }

        $idShop = (int) $c['id_shop'];
        $idCountry = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        $idCurrency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $precision = (int) (new \Currency($idCurrency))->precision;
        $exposed = $this->exposedPrices($ctx, array_map(static fn ($p) => $p['id_product'], $products));

        $leaks = [];
        $checked = 0;
        foreach ($products as $p) {
            if ($ctx->expired()) {
                return CheckResult::notTested('budget', $ctx->t('budget_exceeded'), '', $leaks);
            }
            $id = $p['id_product'];
            if (!isset($exposed[$id])) {
                continue; // non servi (aucune variante exposée) : rien à fuir
            }
            $ipa = (int) \Product::getDefaultAttribute($id);
            $anon = $this->price($idShop, $id, $ipa, $idCountry, $idCurrency, (int) $c['visitor_group'], 0, $precision);
            ++$checked;
            foreach ($p['groups'] as $g) {
                $reserved = $this->price($idShop, $id, $ipa, $idCountry, $idCurrency, $g, 0, $precision);
                if ($reserved !== $anon && $exposed[$id] === $reserved) {
                    $leaks[] = ['product' => $id, 'group' => $g, 'exposed' => $exposed[$id], 'anonymous_expected' => $anon];
                }
            }
            foreach ($p['customers'] as $customer) {
                $group = (int) \Customer::getDefaultGroupId($customer);
                $reserved = $this->price($idShop, $id, $ipa, $idCountry, $idCurrency, $group, $customer, $precision);
                if ($reserved !== $anon && $exposed[$id] === $reserved) {
                    $leaks[] = ['product' => $id, 'customer_specific' => true, 'exposed' => $exposed[$id], 'anonymous_expected' => $anon];
                }
            }
        }
        if ($leaks !== []) {
            return CheckResult::fail($ctx->t('c3_leak', (string) count($leaks)), $ctx->t('c3_leak_action'), $leaks);
        }

        return CheckResult::pass([['products_with_reserved_prices' => count($products), 'checked' => $checked]]);
    }

    /**
     * Prix exposé (unités mineures) de la variante par défaut de chaque produit, lu dans la
     * SORTIE DU MAPPEUR UCP (exactement ce que reçoivent REST et MCP).
     *
     * @param array<int,int> $ids
     *
     * @return array<int,int> id_product => montant
     */
    private function exposedPrices(RunContext $ctx, array $ids): array
    {
        $response = $ctx->mapper()->lookupResponse($ctx->catalogSource()->lookup(new LookupQuery(array_map('strval', $ids))));
        $out = [];
        foreach ($response['products'] as $product) {
            if (!preg_match('#/product/(\d+)$#', (string) $product['id'], $m)) {
                continue;
            }
            $id = (int) $m[1];
            $default = (int) \Product::getDefaultAttribute($id);
            foreach ($product['variants'] as $v) {
                if (preg_match('#/variant/\d+-(\d+)$#', (string) $v['id'], $vm) && (int) $vm[1] === $default) {
                    $out[$id] = (int) $v['price']['amount'];
                }
            }
            if (!isset($out[$id]) && isset($product['variants'][0]['price']['amount'])) {
                $out[$id] = (int) $product['variants'][0]['price']['amount'];
            }
        }

        return $out;
    }

    private function price(int $idShop, int $id, int $ipa, int $idCountry, int $idCurrency, int $idGroup, int $idCustomer, int $precision): int
    {
        $sp = null;
        $value = \Product::priceCalculation(
            $idShop, $id, $ipa ?: null, $idCountry, 0, '', $idCurrency, $idGroup, 1, true, $precision,
            false, true, true, $sp, true, $idCustomer, $idCustomer > 0, 0, 1
        );

        return (int) round(((float) $value) * (10 ** $precision));
    }
}
