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
 * B1 — le manifeste /.well-known/ucp répond réellement et est conforme (mêmes exigences
 * que scripts/protocol-checks.py : version datée, registres services et payment_handlers
 * en OBJETS, endpoints en https sur le même hôte, cache public).
 */
final class ManifestCheck implements ReadinessCheck
{
    public function meta(): array
    {
        return ['id' => 'B1', 'version' => '1', 'category' => 'protocol', 'tier' => 'free', 'severity' => 'critical'];
    }

    public function run(RunContext $ctx): CheckResult
    {
        $r = $ctx->fetcher->get('/.well-known/ucp');
        if (($reason = $r->unavailableReason()) !== null) {
            return CheckResult::notTested($reason, $ctx->t('storefront_unreachable', $reason), $ctx->t('storefront_unreachable_action'));
        }
        $doc = json_decode($r->body); // OBJETS : distinguer {} de []
        $ucp = is_object($doc) && isset($doc->ucp) && is_object($doc->ucp) ? $doc->ucp : null;
        if ($r->status !== 200 || $ucp === null) {
            return CheckResult::fail($ctx->t('b1_unreadable'), $ctx->t('b1_unreadable_action'), [['status' => $r->status]]);
        }
        $problems = [];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($ucp->version ?? ''))) {
            $problems[] = 'version';
        }
        if (!isset($ucp->services) || !is_object($ucp->services)) {
            $problems[] = 'services';
        }
        if (!isset($ucp->payment_handlers) || !is_object($ucp->payment_handlers)) {
            $problems[] = 'payment_handlers';
        }
        $endpoints = [];
        foreach ((array) (($ucp->services ?? null)->{'dev.ucp.shopping'} ?? []) as $svc) {
            $endpoints[(string) ($svc->transport ?? '')] = (string) ($svc->endpoint ?? '');
        }
        $host = (string) parse_url((string) $ctx->context['base_url'], PHP_URL_HOST);
        $https = parse_url((string) $ctx->context['base_url'], PHP_URL_SCHEME) === 'https';
        foreach ($endpoints as $endpoint) {
            if (parse_url($endpoint, PHP_URL_HOST) !== $host || ($https && !str_starts_with($endpoint, 'https://'))) {
                $problems[] = 'endpoints';
                break;
            }
        }
        $evidence = [['version' => (string) ($ucp->version ?? ''), 'endpoints' => $endpoints]];
        if ($problems !== []) {
            return CheckResult::fail($ctx->t('b1_invalid', implode(', ', array_unique($problems))), $ctx->t('b1_invalid_action'), $evidence);
        }
        $cc = $r->header('cache-control');
        $devMode = defined('_PS_MODE_DEV_') && _PS_MODE_DEV_;
        if (!$devMode && (!str_contains($cc, 'public') || !preg_match('/max-age=(\d+)/', $cc, $m) || (int) $m[1] < 60)) {
            return CheckResult::warn($ctx->t('b1_cache'), $ctx->t('b1_cache_action'), $evidence + [1 => ['cache_control' => $cc]]);
        }

        return CheckResult::pass($evidence);
    }
}
