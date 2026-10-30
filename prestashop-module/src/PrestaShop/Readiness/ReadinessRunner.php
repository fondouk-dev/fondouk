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

/**
 * Exécute une liste de contrôles dans un budget de temps et produit le rapport
 * (readiness-report.v1.json). Budget épuisé : contrôles restants NOT_TESTED (budget).
 * Une exception d'un contrôle n'interrompt pas le passage : NOT_TESTED (no_data), sans
 * divulguer le message technique.
 */
final class ReadinessRunner
{
    public const UCP_VERSION = '2026-04-08';
    public const MCP_VERSION = '2025-06-18';
    private const LOCK_KEY = 'FONDOUK_READINESS_LOCK';
    private const LOCK_TTL_S = 120;

    /** @param array<int,ReadinessCheck> $checks */
    public function __construct(private readonly array $checks)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function run(RunContext $ctx, float $budgetS, int $sampleSize, string $psVersion): array
    {
        $started = gmdate('c');
        $results = [];
        $totals = [CheckResult::PASS => 0, CheckResult::WARN => 0, CheckResult::FAIL => 0, CheckResult::NOT_TESTED => 0];

        foreach ($this->checks as $check) {
            $meta = $check->meta();
            $t0 = microtime(true);
            if ($ctx->expired()) {
                $res = CheckResult::notTested('budget', $ctx->t('budget_exceeded'));
            } else {
                try {
                    $res = $check->run($ctx);
                } catch (\Throwable $e) {
                    $res = CheckResult::notTested('no_data', $ctx->t('check_error'));
                }
            }
            $res->durationMs = (int) round((microtime(true) - $t0) * 1000);
            ++$totals[$res->status];
            $results[] = $res->toArray($meta);
        }

        return [
            'report_version' => '1',
            'run' => [
                'run_id' => 'r-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)),
                'started_at' => $started,
                'finished_at' => gmdate('c'),
                'module' => ['name' => $ctx->moduleName, 'version' => $ctx->moduleVersion],
                'prestashop_version' => $psVersion,
                'protocols' => ['ucp' => self::UCP_VERSION, 'mcp' => self::MCP_VERSION],
                'budget_s' => $budgetS,
                'sample_size' => $sampleSize,
                'totals' => $totals,
            ],
            'context' => $ctx->reportContext(),
            'results' => $results,
        ];
    }

    /** Verrou par boutique : un seul passage à la fois. Faux si un passage est déjà en cours. */
    public static function acquireLock(int $idShop): bool
    {
        $locks = json_decode((string) \Configuration::getGlobalValue(self::LOCK_KEY), true);
        $locks = is_array($locks) ? $locks : [];
        $now = time();
        if (isset($locks[$idShop]) && $now - (int) $locks[$idShop] < self::LOCK_TTL_S) {
            return false;
        }
        $locks[$idShop] = $now;

        return (bool) \Configuration::updateGlobalValue(self::LOCK_KEY, (string) json_encode($locks));
    }

    public static function releaseLock(int $idShop): void
    {
        SelfCheckToken::revoke($idShop);
        $locks = json_decode((string) \Configuration::getGlobalValue(self::LOCK_KEY), true);
        if (is_array($locks) && isset($locks[$idShop])) {
            unset($locks[$idShop]);
            \Configuration::updateGlobalValue(self::LOCK_KEY, (string) json_encode($locks));
        }
    }
}
