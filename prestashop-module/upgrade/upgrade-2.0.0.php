<?php
/**
 * Fondouk — AI Agent Ready for PrestaShop (UCP, MCP).
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade → 2.0.0 : enregistre les hooks d'invalidation du cache catalogue ajoutés en 2.0.0
 * (catégories, groupes, taxes, devises, règles de prix, déclinaisons), puis invalide le cache.
 *
 * Idempotent (un hook déjà enregistré n'est pas dupliqué) et non bloquant : un échec est
 * journalisé, l'upgrade du module se poursuit. Aucune donnée n'est supprimée.
 */
function upgrade_module_2_0_0(Module $module): bool
{
    try {
        foreach (Fondouk::CACHE_INVALIDATION_HOOKS as $hook) {
            if (!$module->isRegisteredInHook($hook) && !$module->registerHook($hook)) {
                PrestaShopLogger::addLog('[Fondouk] upgrade 2.0.0 : hook ' . $hook . ' non enregistré', 2);
            }
        }
        Configuration::updateValue('FONDOUK_CATALOG_VERSION', (int) Configuration::get('FONDOUK_CATALOG_VERSION') + 1);
        // F-01 : seaux du budget de télémétrie (table partagée avec le Pro, création idempotente).
        if (!Db::getInstance()->execute(PrestaShop\Module\Fondouk\PrestaShop\TelemetryBudget::createTableSql())) {
            PrestaShopLogger::addLog('[Fondouk] upgrade 2.0.0 : table fondouk_access_daily non créée', 2);
        }
    } catch (Throwable $e) {
        PrestaShopLogger::addLog('[Fondouk] upgrade 2.0.0 : ' . get_class($e), 2);
    }

    return true;
}
