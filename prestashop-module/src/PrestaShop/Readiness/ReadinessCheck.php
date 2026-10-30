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
 * Un contrôle du Commerce Diagnostic. Lecture seule ; jamais exécuté sur une requête de
 * visiteur ou d'agent (lancement manuel depuis le back-office uniquement).
 */
interface ReadinessCheck
{
    /**
     * Métadonnées stables : id (A1, B2, C3-F…), version, category, tier (free|pro), severity.
     *
     * @return array{id:string,version:string,category:string,tier:string,severity:string}
     */
    public function meta(): array;

    public function run(RunContext $ctx): CheckResult;
}
