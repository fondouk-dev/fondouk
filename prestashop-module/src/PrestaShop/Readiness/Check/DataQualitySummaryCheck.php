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
 * D1-F — synthèse chiffrée de la qualité des données utiles aux agents, sur TOUS les
 * produits servis (agrégats SQL). WARN s'il existe un produit servi à prix nul ou sans
 * image ; les autres taux sont informatifs. Le détail par produit est un apport Pro (D1-P).
 */
final class DataQualitySummaryCheck implements ReadinessCheck
{
    public function meta(): array
    {
        return ['id' => 'D1-F', 'version' => '1', 'category' => 'data_quality', 'tier' => 'free', 'severity' => 'low'];
    }

    public function run(RunContext $ctx): CheckResult
    {
        $facts = new CatalogFacts((int) $ctx->context['id_shop'], (int) $ctx->context['visitor_group']);
        $q = $facts->qualityCounts((int) \Configuration::get('PS_LANG_DEFAULT'));
        if ($q['served'] === 0) {
            return CheckResult::notTested('no_data', $ctx->t('d1_no_product'));
        }
        if ($q['zero_price'] > 0 || $q['no_image'] > 0) {
            return CheckResult::warn($ctx->t('d1_warn', (string) $q['zero_price'], (string) $q['no_image']), $ctx->t('d1_action'), [$q]);
        }

        return CheckResult::pass([$q]);
    }
}
