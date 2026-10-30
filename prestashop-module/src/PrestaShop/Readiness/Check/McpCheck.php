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

use PrestaShop\Module\Fondouk\PrestaShop\Readiness\CheckResult;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\ReadinessCheck;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\RunContext;

/**
 * B3 — le serveur MCP répond réellement : tools/list (3 outils), puis search_catalog sous
 * la forme du binding MCP d'UCP (meta + catalog), dont le résultat doit être celui du REST.
 */
final class McpCheck implements ReadinessCheck
{
    private const TOOLS = ['get_product', 'lookup_catalog', 'search_catalog'];

    public function meta(): array
    {
        return ['id' => 'B3', 'version' => '1', 'category' => 'protocol', 'tier' => 'free', 'severity' => 'high'];
    }

    public function run(RunContext $ctx): CheckResult
    {
        $tl = $ctx->fetcher->postJson('/fondouk/ucp/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        if (($reason = $tl->unavailableReason()) !== null) {
            return CheckResult::notTested($reason, $ctx->t('storefront_unreachable', $reason), $ctx->t('storefront_unreachable_action'));
        }
        $names = array_map(static fn ($t) => (string) ($t['name'] ?? ''), (array) ($tl->json()['result']['tools'] ?? []));
        sort($names);
        if ($names !== self::TOOLS) {
            return CheckResult::fail($ctx->t('b3_tools'), $ctx->t('b2_action'), [['tools' => $names]]);
        }

        $args = ['meta' => ['ucp-agent' => ['profile' => 'https://fondouk.dev/readiness']], 'catalog' => ['query' => '', 'pagination' => ['limit' => 3]]];
        $callr = $ctx->fetcher->postJson('/fondouk/ucp/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'search_catalog', 'arguments' => $args]]);
        if (($nt = CheckResult::unreachable($callr, $ctx)) !== null) {
            return $nt;
        }
        $call = $callr->json();
        $result = $call['result'] ?? [];
        if (($result['isError'] ?? true) !== false || !is_array($result['structuredContent']['products'] ?? null)) {
            return CheckResult::fail($ctx->t('b3_call'), $ctx->t('b2_action'));
        }
        $restr = $ctx->fetcher->postJson('/fondouk/ucp/catalog/search', ['query' => '', 'pagination' => ['limit' => 3]]);
        if (($nt = CheckResult::unreachable($restr, $ctx)) !== null) {
            return $nt;
        }
        $rest = $restr->json();
        $ids = static fn ($list) => array_map(static fn ($p) => (string) ($p['id'] ?? ''), (array) $list);
        $mcpIds = $ids($result['structuredContent']['products']);
        $restIds = $ids($rest['products'] ?? []);
        if ($mcpIds !== $restIds) {
            return CheckResult::fail($ctx->t('b3_mismatch'), $ctx->t('b2_action'), [['mcp' => $mcpIds, 'rest' => $restIds]]);
        }

        return CheckResult::pass([['tools' => $names, 'same_result_as_rest' => true]]);
    }
}
