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

use PrestaShop\Module\Fondouk\PrestaShop\Readiness\CatalogFacts;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\CheckResult;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\ReadinessCheck;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\RunContext;

/**
 * B2 — le catalogue REST répond réellement : search, lookup du produit obtenu, et
 * not_found pour un identifiant inexistant. Boutique aux prix masqués : 0 produit et
 * message prices_hidden attendus (comportement correct, pas un échec).
 */
final class RestCheck implements ReadinessCheck
{
    public const MISSING_ID = 'gid://fondouk-readiness/0/product/999999999';

    public function meta(): array
    {
        return ['id' => 'B2', 'version' => '1', 'category' => 'protocol', 'tier' => 'free', 'severity' => 'critical'];
    }

    public function run(RunContext $ctx): CheckResult
    {
        $s = $ctx->fetcher->postJson('/fondouk/ucp/catalog/search', ['query' => '', 'pagination' => ['limit' => 1]]);
        if (($reason = $s->unavailableReason()) !== null) {
            return CheckResult::notTested($reason, $ctx->t('storefront_unreachable', $reason), $ctx->t('storefront_unreachable_action'));
        }
        $search = $s->json();
        if ($s->status !== 200 || !isset($search['ucp']) || !is_array($search['products'] ?? null)) {
            return CheckResult::fail($ctx->t('b2_search'), $ctx->t('b2_action'), [['status' => $s->status]]);
        }
        $codes = array_map(static fn ($m) => (string) ($m['code'] ?? ''), (array) ($search['messages'] ?? []));

        if (!$ctx->context['prices_visible']) {
            return $search['products'] === [] && in_array('prices_hidden', $codes, true)
                ? CheckResult::pass([['prices_visible' => false, 'messages' => $codes]], $ctx->t('b2_prices_hidden'))
                : CheckResult::fail($ctx->t('b2_prices_hidden_fail'), $ctx->t('b2_action'), [['products' => count($search['products'])]]);
        }

        $served = (new CatalogFacts((int) $ctx->context['id_shop'], (int) $ctx->context['visitor_group']))->servedCount();
        if ($search['products'] === []) {
            return $served > 0
                ? CheckResult::fail($ctx->t('b2_empty', (string) $served), $ctx->t('b2_action'), [['served_in_shop' => $served]])
                : CheckResult::warn($ctx->t('b2_no_product'), $ctx->t('b2_no_product_action'));
        }
        $id = (string) $search['products'][0]['id'];
        $lkr = $ctx->fetcher->postJson('/fondouk/ucp/catalog/lookup', ['ids' => [$id]]);
        if (($nt = CheckResult::unreachable($lkr, $ctx)) !== null) {
            return $nt;
        }
        $lk = $lkr->json();
        if (($lk['products'][0]['id'] ?? null) !== $id) {
            return CheckResult::fail($ctx->t('b2_lookup'), $ctx->t('b2_action'), [['id' => $id]]);
        }
        $nfr = $ctx->fetcher->postJson('/fondouk/ucp/catalog/product', ['id' => self::MISSING_ID]);
        if (($nt = CheckResult::unreachable($nfr, $ctx)) !== null) {
            return $nt;
        }
        $nf = $nfr->json();
        $nfCodes = array_map(static fn ($m) => (string) ($m['code'] ?? ''), (array) ($nf['messages'] ?? []));
        if (!in_array('not_found', $nfCodes, true)) {
            return CheckResult::fail($ctx->t('b2_not_found'), $ctx->t('b2_action'), [['codes' => $nfCodes]]);
        }

        return CheckResult::pass([['first_product' => $id, 'served_in_shop' => $served]]);
    }
}
