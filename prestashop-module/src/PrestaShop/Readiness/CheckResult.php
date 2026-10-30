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
 * Résultat d'un contrôle du Commerce Diagnostic (schéma readiness-report.v1.json).
 * Preuves BORNÉES : 5 éléments au plus, 4 Kio au total, jamais de donnée client.
 * NOT_TESTED porte toujours une cause et n'est jamais compté comme PASS.
 */
final class CheckResult
{
    public const PASS = 'PASS';
    public const WARN = 'WARN';
    public const FAIL = 'FAIL';
    public const NOT_TESTED = 'NOT_TESTED';

    public const MAX_EVIDENCE = 5;
    public const MAX_EVIDENCE_BYTES = 4096;

    /** Ordre de gravité, pour retenir le pire statut d'un contrôle multi-produits. */
    private const RANK = [self::PASS => 0, self::NOT_TESTED => 1, self::WARN => 2, self::FAIL => 3];

    /** @var array<int,array<string,mixed>> */
    public array $evidence = [];
    public int $durationMs = 0;

    /**
     * @param array<int,array<string,mixed>> $evidence
     */
    private function __construct(
        public readonly string $status,
        array $evidence,
        public readonly string $cause,
        public readonly string $action,
        public readonly ?string $notTestedReason,
    ) {
        $this->evidence = self::bound($evidence);
    }

    /** @param array<int,array<string,mixed>> $evidence */
    public static function pass(array $evidence = [], string $cause = ''): self
    {
        return new self(self::PASS, $evidence, $cause, '', null);
    }

    /** @param array<int,array<string,mixed>> $evidence */
    public static function warn(string $cause, string $action, array $evidence = []): self
    {
        return new self(self::WARN, $evidence, $cause, $action, null);
    }

    /** @param array<int,array<string,mixed>> $evidence */
    public static function fail(string $cause, string $action, array $evidence = []): self
    {
        return new self(self::FAIL, $evidence, $cause, $action, null);
    }

    /** @param array<int,array<string,mixed>> $evidence */
    public static function notTested(string $reason, string $cause, string $action = '', array $evidence = []): self
    {
        return new self(self::NOT_TESTED, $evidence, $cause, $action, $reason);
    }

    /**
     * NOT_TESTED si la boutique n'a pas pu être jointe ou interrogée pour CETTE requête (réseau,
     * délai, budget de la passe épuisé, authentification, maintenance, limite de débit), sinon null.
     * À appliquer à chaque requête d'un contrôle : une panne d'accès n'est jamais un FAIL ni un PASS.
     *
     * @param array<int,array<string,mixed>> $evidence
     */
    public static function unreachable(FetchResult $r, RunContext $ctx, array $evidence = []): ?self
    {
        $reason = $r->unavailableReason();

        return $reason === null ? null : self::notTested($reason, $ctx->t('storefront_unreachable', $reason), $ctx->t('storefront_unreachable_action'), $evidence);
    }

    public static function worse(string $a, string $b): string
    {
        return self::RANK[$b] > self::RANK[$a] ? $b : $a;
    }

    /**
     * @param array<int,array<string,mixed>> $evidence
     *
     * @return array<int,array<string,mixed>>
     */
    private static function bound(array $evidence): array
    {
        $out = [];
        foreach (array_slice(array_values($evidence), 0, self::MAX_EVIDENCE) as $item) {
            $candidate = array_merge($out, [$item]);
            if (strlen((string) json_encode($candidate)) > self::MAX_EVIDENCE_BYTES) {
                break;
            }
            $out = $candidate;
        }

        return $out;
    }

    /**
     * @param array{id:string,version:string,category:string,tier:string,severity:string} $meta
     *
     * @return array<string,mixed>
     */
    public function toArray(array $meta): array
    {
        $out = [
            'check_id' => $meta['id'],
            'check_version' => $meta['version'],
            'category' => $meta['category'],
            'tier' => $meta['tier'],
            'status' => $this->status,
            'severity' => $meta['severity'],
            'evidence' => $this->evidence,
            'cause' => $this->cause,
            'action' => $this->action,
            'duration_ms' => $this->durationMs,
        ];
        if ($this->notTestedReason !== null) {
            $out['not_tested_reason'] = $this->notTestedReason;
        }

        return $out;
    }
}
