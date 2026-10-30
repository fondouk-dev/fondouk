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
use PrestaShop\Module\Fondouk\PrestaShop\TrustedProxies;

/**
 * A1–A4 — prérequis d'environnement (repris du diagnostic historique du module gratuit,
 * mêmes conditions) : HTTPS, URL simplifiées, conflit physique .well-known, dépendances.
 * A5 — proxys de confiance : avertit si des requêtes d'agents sont arrivées par un proxy
 * (adresse privée + X-Forwarded-For) sans proxy déclaré ; ne prétend pas détecter tous les proxys.
 */
final class EnvironmentCheck implements ReadinessCheck
{
    private const META = [
        'A1' => ['category' => 'environment', 'severity' => 'high'],
        'A2' => ['category' => 'environment', 'severity' => 'high'],
        'A3' => ['category' => 'environment', 'severity' => 'medium'],
        'A4' => ['category' => 'environment', 'severity' => 'critical'],
        'A5' => ['category' => 'environment', 'severity' => 'medium'],
    ];

    public function __construct(private readonly string $id)
    {
        if (!isset(self::META[$id])) {
            throw new \InvalidArgumentException('Unknown environment check ' . $id);
        }
    }

    public function meta(): array
    {
        return ['id' => $this->id, 'version' => '1', 'tier' => 'free'] + self::META[$this->id];
    }

    public function run(RunContext $ctx): CheckResult
    {
        switch ($this->id) {
            case 'A1':
                $base = (string) $ctx->context['base_url'];

                return parse_url($base, PHP_URL_SCHEME) === 'https'
                    ? CheckResult::pass([['base_url' => $base]])
                    : CheckResult::fail($ctx->t('a1_cause'), $ctx->t('a1_action'), [['base_url' => $base]]);

            case 'A2':
                return (bool) \Configuration::get('PS_REWRITING_SETTINGS')
                    ? CheckResult::pass()
                    : CheckResult::fail($ctx->t('a2_cause'), $ctx->t('a2_action'));

            case 'A3':
                $dir = rtrim(_PS_ROOT_DIR_, '/') . '/.well-known';
                if (file_exists($dir . '/ucp')) {
                    return CheckResult::fail($ctx->t('a3_file_cause'), $ctx->t('a3_file_action'), [['path' => '/.well-known/ucp']]);
                }
                if (is_dir($dir)) {
                    // Dossier seul (souvent Let's Encrypt) : sans effet avec le .htaccess de PrestaShop ;
                    // B1 dit si la route répond réellement.
                    return CheckResult::warn($ctx->t('a3_dir_cause'), $ctx->t('a3_dir_action'), [['path' => '/.well-known/']]);
                }

                return CheckResult::pass();

            case 'A5':
                $declared = TrustedProxies::list();
                if ($declared !== []) {
                    return CheckResult::pass([['trusted_proxies' => count($declared)]]);
                }
                $hint = TrustedProxies::hint();
                if ($hint !== null) {
                    return CheckResult::warn($ctx->t('a5_cause', $hint['from']), $ctx->t('a5_action'), [['proxy_seen_from' => $hint['from'], 'seen_at' => date('c', $hint['at'])]]);
                }

                return CheckResult::pass([['trusted_proxies' => 0]], $ctx->t('a5_none'));

            default: // A4
                return is_file($ctx->moduleDir . '/vendor/autoload.php')
                    ? CheckResult::pass()
                    : CheckResult::fail($ctx->t('a4_cause'), $ctx->t('a4_action'));
        }
    }
}
