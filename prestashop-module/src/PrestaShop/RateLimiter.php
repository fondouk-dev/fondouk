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
 * Rate limiting par IP — TOKEN BUCKET conforme à la spec §4.4 : 60 req/min (refill
 * 1 jeton/s) avec burst 120. Autorise les rafales d'agents tout en bornant le débit
 * soutenu. Atomique par transaction + verrou de ligne. Clé = IP hashée+salée.
 *
 * Fail-open : une erreur du limiteur ne doit jamais casser le service (lecture seule).
 * La clé est l'adresse donnée par ClientIp (en-têtes de proxy seulement derrière un proxy déclaré).
 * Purge bornée (R3-03) : un compteur inactif depuis IDLE_PURGE_S est plein (le seau se remplit
 * en burst / débit secondes) ; le supprimer ne change aucune décision. Une requête sur
 * PURGE_ONE_IN supprime au plus PURGE_BATCH compteurs inactifs : la table reste bornée.
 */
final class RateLimiter
{
    private const IDLE_PURGE_S = 600;
    private const PURGE_ONE_IN = 100;
    private const PURGE_BATCH = 500;

    /** F-02 : une consultation par identifiants coûte 1 jeton par tranche de 10 identifiants. */
    public const IDS_PER_TOKEN = 10;

    private readonly float $ratePerSecond;

    public function __construct(int $perMinute = 60, private readonly int $burst = 120)
    {
        $this->ratePerSecond = $perMinute / 60.0;
    }

    /** Coût en jetons d'une consultation portant sur `$ids` identifiants (au moins 1). */
    public static function costForIds(int $ids): int
    {
        return max(1, (int) ceil($ids / self::IDS_PER_TOKEN));
    }

    /**
     * Coût effectivement facturé : au moins 1 jeton, au plus la rafale. Un coût supérieur à la
     * rafale exige alors un seau plein, jamais l'impossible (agent bridé à faible débit).
     */
    public function cappedCost(int $cost): int
    {
        return max(1, min($cost, $this->burst));
    }

    /**
     * Consomme `$cost` jetons (F-02 : coût proportionnel au travail demandé), ramené par
     * {@see cappedCost()}.
     *
     * @return array{allowed:bool,retryAfter:int,remaining:int}
     */
    public function hit(string $ip, int $cost = 1): array
    {
        $cost = (float) $this->cappedCost($cost);
        $now = microtime(true);
        $salt = (string) \Configuration::get('FONDOUK_IP_SALT');
        $bucket = hash('sha256', $ip . '|' . $salt);
        $t = _DB_PREFIX_ . 'fondouk_rate_limit';
        $db = \Db::getInstance();
        $nowSql = sprintf('%.6f', $now);

        try {
            $db->execute('START TRANSACTION');
            // executeS verbatim (getRow ajouterait LIMIT 1 APRÈS FOR UPDATE → SQL invalide).
            $rows = $db->executeS('SELECT tokens, updated_at FROM `' . $t . '` WHERE bucket = "' . pSQL($bucket) . '" FOR UPDATE', true, false);
            $row = $rows[0] ?? null;

            if ($row) {
                $elapsed = $now - (float) $row['updated_at'];
                $tokens = min((float) $this->burst, (float) $row['tokens'] + $elapsed * $this->ratePerSecond);
            } else {
                $tokens = (float) $this->burst;
            }

            $allowed = $tokens >= $cost;
            if ($allowed) {
                $tokens -= $cost;
            }

            $tokensSql = sprintf('%.6f', $tokens);
            $db->execute(
                'INSERT INTO `' . $t . '` (bucket, tokens, updated_at) VALUES ("' . pSQL($bucket) . '", ' . $tokensSql . ', ' . $nowSql . ')
                 ON DUPLICATE KEY UPDATE tokens = ' . $tokensSql . ', updated_at = ' . $nowSql
            );
            $db->execute('COMMIT');
            if (random_int(1, self::PURGE_ONE_IN) === 1) {
                $db->execute('DELETE FROM `' . $t . '` WHERE updated_at < ' . sprintf('%.6f', $now - self::IDLE_PURGE_S) . ' LIMIT ' . self::PURGE_BATCH);
            }
        } catch (\Throwable $e) {
            try {
                $db->execute('ROLLBACK');
            } catch (\Throwable $e2) {
            }

            // Fail-open documenté ET loggé : une garde de débit dégradée qui laisse passer
            // en silence est exactement ce qu'un audit relèverait. Trace en logs BO (sévérité 3).
            if (class_exists('\PrestaShopLogger')) {
                \PrestaShopLogger::addLog(
                    '[Fondouk] RateLimiter fail-open : rate limiting indisponible, requête laissée passer. ' . $e->getMessage(),
                    3
                );
            }

            return ['allowed' => true, 'retryAfter' => 0, 'remaining' => $this->burst];
        }

        $retryAfter = $allowed ? 0 : max(1, (int) ceil(($cost - $tokens) / $this->ratePerSecond));

        return [
            'allowed' => $allowed,
            'retryAfter' => $retryAfter,
            'remaining' => (int) floor(max(0.0, $tokens)),
        ];
    }
}
