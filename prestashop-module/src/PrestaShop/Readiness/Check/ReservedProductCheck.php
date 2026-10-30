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
 * C6-F — aucun produit RÉSERVÉ (inactif, invisible, catégorie interdite au visiteur, absent
 * de la boutique) n'est exposé aux agents, et la réponse est INDISCERNABLE de celle d'un
 * identifiant inexistant (aucune fuite d'existence).
 */
final class ReservedProductCheck implements ReadinessCheck
{
    private const LIMIT = 200;
    private const HTTP_PROBES = 5;

    public function meta(): array
    {
        return ['id' => 'C6-F', 'version' => '1', 'category' => 'security', 'tier' => 'free', 'severity' => 'critical'];
    }

    public function run(RunContext $ctx): CheckResult
    {
        $facts = new CatalogFacts((int) $ctx->context['id_shop'], (int) $ctx->context['visitor_group']);
        $ids = $facts->nonPublicProductIds(self::LIMIT);
        if ($ids === []) {
            return CheckResult::pass([], $ctx->t('c6_none'));
        }

        // 1. S1 interne (sortie du mappeur, sans cache) : aucun produit réservé servi.
        $response = $ctx->mapper()->lookupResponse($ctx->catalogSource()->lookup(new LookupQuery(array_map('strval', $ids))));
        if ($response['products'] !== []) {
            $exposed = array_map(static fn ($p) => (string) $p['id'], $response['products']);

            return CheckResult::fail($ctx->t('c6_exposed', (string) count($exposed)), $ctx->t('c6_action'), [['exposed' => array_slice($exposed, 0, 20)]]);
        }

        // 2. S1-HTTP (REST et MCP, cache compris) : même réponse qu'un identifiant inexistant.
        $internal = [['internal_check' => 'PASS']];
        $ref = $ctx->fetcher->postJson('/fondouk/ucp/catalog/product', ['id' => RestCheck::MISSING_ID]);
        if (($nt = CheckResult::unreachable($ref, $ctx, $internal)) !== null) {
            return $nt;
        }
        $reference = $this->signature($ref->json());
        if ($reference === null) {
            return CheckResult::notTested('network', $ctx->t('storefront_unreachable', 'network'), $ctx->t('storefront_unreachable_action'), $internal);
        }
        foreach (array_slice($ids, 0, self::HTTP_PROBES) as $id) {
            $probe = $ctx->fetcher->postJson('/fondouk/ucp/catalog/product', ['id' => (string) $id]);
            if (($nt = CheckResult::unreachable($probe, $ctx, $internal)) !== null) {
                return $nt;
            }
            $sig = $this->signature($probe->json());
            if ($sig !== $reference) {
                return CheckResult::fail($ctx->t('c6_distinguishable'), $ctx->t('c6_action'), [['product' => $id, 'response' => $sig, 'unknown_id_response' => $reference]]);
            }
        }
        $mcpr = $ctx->fetcher->postJson('/fondouk/ucp/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_product', 'arguments' => ['id' => (string) $ids[0]]]]);
        if (($nt = CheckResult::unreachable($mcpr, $ctx, $internal)) !== null) {
            return $nt;
        }
        $mcp = $mcpr->json();
        if (($mcp['result']['isError'] ?? false) !== true) {
            return CheckResult::fail($ctx->t('c6_exposed', '1'), $ctx->t('c6_action'), [['product' => $ids[0], 'channel' => 'mcp']]);
        }

        return CheckResult::pass([['reserved_products_checked' => count($ids), 'http_probes' => min(count($ids), self::HTTP_PROBES)]]);
    }

    /** Signature d'une réponse « produit introuvable » : statut UCP + codes des messages. */
    private function signature(?array $r): ?string
    {
        if ($r === null) {
            return null;
        }
        $codes = array_map(static fn ($m) => (string) ($m['code'] ?? ''), (array) ($r['messages'] ?? []));

        return (string) ($r['ucp']['status'] ?? '') . '|' . implode(',', $codes) . '|' . (isset($r['product']) ? 'product' : '-');
    }
}
