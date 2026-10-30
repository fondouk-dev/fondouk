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

use PrestaShop\Module\Fondouk\PrestaShop\Readiness\Check\CrawlPolicyCheck;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\Check\DataQualitySummaryCheck;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\Check\EnvironmentCheck;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\Check\HiddenPriceCheck;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\Check\ManifestCheck;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\Check\McpCheck;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\Check\ReservedPriceCheck;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\Check\ReservedProductCheck;
use PrestaShop\Module\Fondouk\PrestaShop\Readiness\Check\RestCheck;

/**
 * Contrôles du diagnostic ESSENTIEL (gratuit, décision D4 et répartition du 20/10) : tout
 * ce qui dit si un agent peut lire la boutique, plus la sécurité des prix et des produits
 * réservés. Le Pro exécute exactement la même liste (parité), puis ses contrôles propres.
 */
final class FreeChecks
{
    public const BUDGET_S = 20.0;

    /** @return array<int,ReadinessCheck> */
    public static function all(): array
    {
        return [
            new EnvironmentCheck('A1'),
            new EnvironmentCheck('A2'),
            new EnvironmentCheck('A3'),
            new EnvironmentCheck('A4'),
            new EnvironmentCheck('A5'),
            new ManifestCheck(),
            new RestCheck(),
            new McpCheck(),
            new CrawlPolicyCheck(),
            new ReservedPriceCheck(),
            new ReservedProductCheck(),
            new HiddenPriceCheck(),
            new DataQualitySummaryCheck(),
        ];
    }
}
