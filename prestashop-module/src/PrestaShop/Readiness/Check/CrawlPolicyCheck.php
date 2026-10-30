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
 * B4 — llms.txt servi, et robots.txt ne ferme pas les routes agents.
 * Interdire les routes agents à tous (« * ») = FAIL ; bloquer certaines familles d'agents
 * connues = WARN, LISTÉ : c'est souvent un choix volontaire du marchand, jamais un FAIL.
 */
final class CrawlPolicyCheck implements ReadinessCheck
{
    private const AGENT_ROUTES = ['/.well-known/ucp', '/fondouk/ucp/catalog/search', '/fondouk/ucp/mcp'];
    private const AI_FAMILIES = ['gptbot', 'oai-searchbot', 'chatgpt-user', 'claudebot', 'claude-user', 'claude-searchbot', 'perplexitybot',
        'perplexity-user', 'google-extended', 'meta-externalagent', 'amazonbot', 'applebot-extended', 'ccbot', 'bytespider', 'mistralai-user'];

    public function meta(): array
    {
        return ['id' => 'B4', 'version' => '1', 'category' => 'protocol', 'tier' => 'free', 'severity' => 'medium'];
    }

    public function run(RunContext $ctx): CheckResult
    {
        $llms = $ctx->fetcher->get('/llms.txt');
        if (($reason = $llms->unavailableReason()) !== null) {
            return CheckResult::notTested($reason, $ctx->t('storefront_unreachable', $reason), $ctx->t('storefront_unreachable_action'));
        }
        if ($llms->status !== 200 || !str_contains($llms->body, '/.well-known/ucp')) {
            return CheckResult::fail($ctx->t('b4_llms'), $ctx->t('b2_action'), [['status' => $llms->status]]);
        }

        $robots = $ctx->fetcher->get('/robots.txt');
        if (($nt = CheckResult::unreachable($robots, $ctx, [['llms_txt' => true]])) !== null) {
            return $nt;
        }
        $groups = $robots->status === 200 ? self::parse($robots->body) : [];
        $closedForAll = array_values(array_filter(self::AGENT_ROUTES, static fn ($r) => self::disallowed($groups['*'] ?? [], $r)));
        if ($closedForAll !== []) {
            return CheckResult::fail($ctx->t('b4_robots_all'), $ctx->t('b4_robots_all_action'), [['disallowed_for_all' => $closedForAll]]);
        }
        $blocked = [];
        foreach (self::AI_FAMILIES as $family) {
            if (isset($groups[$family]) && (self::disallowed($groups[$family], '/') || self::disallowed($groups[$family], '/fondouk/ucp/mcp'))) {
                $blocked[] = $family;
            }
        }
        if ($blocked !== []) {
            return CheckResult::warn($ctx->t('b4_robots_families', implode(', ', $blocked)), $ctx->t('b4_robots_families_action'), [['blocked_families' => $blocked]]);
        }

        return CheckResult::pass([['llms_txt' => true, 'robots_txt' => $robots->status === 200]]);
    }

    /**
     * robots.txt → [agent en minuscules => liste de [allow|disallow, chemin]] (groupes RFC 9309).
     *
     * @return array<string,array<int,array{0:string,1:string}>>
     */
    public static function parse(string $txt): array
    {
        $groups = [];
        $current = [];
        $lastWasAgent = false;
        foreach (preg_split('/\r\n|\r|\n/', $txt) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if (!str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            if ($k === 'user-agent') {
                if (!$lastWasAgent) {
                    $current = [];
                }
                $current[] = strtolower($v);
                $groups[strtolower($v)] = $groups[strtolower($v)] ?? [];
                $lastWasAgent = true;
            } elseif ($k === 'allow' || $k === 'disallow') {
                foreach ($current as $agent) {
                    $groups[$agent][] = [$k, $v];
                }
                $lastWasAgent = false;
            } else {
                $lastWasAgent = false;
            }
        }

        return $groups;
    }

    /**
     * Le chemin est-il interdit ? Règle la plus longue applicable ; Allow gagne à égalité.
     *
     * @param array<int,array{0:string,1:string}> $rules
     */
    public static function disallowed(array $rules, string $path): bool
    {
        $best = -1;
        $allowed = true;
        foreach ($rules as [$type, $rule]) {
            if ($rule === '') {
                continue;
            }
            $regex = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($rule, '#')) . '#';
            if (preg_match($regex, $path) && (strlen($rule) > $best || (strlen($rule) === $best && $type === 'allow'))) {
                $best = strlen($rule);
                $allowed = $type === 'allow';
            }
        }

        return !$allowed;
    }
}
