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
 * Le catalogue servi aux agents est celui d'un visiteur anonyme, même si la requête porte le
 * cookie d'un client connecté. PrestaShop déduit le groupe tarifaire du client du contexte global
 * (Group::getCurrent) et le pays fiscal de l'adresse de son panier : les deux sont neutralisés
 * avant tout calcul. Boutique, langue, devise et pays (paramètres publics) sont conservés.
 */
final class AnonymousContext
{
    /** Marqueur de clé de cache : les réponses calculées avant cette neutralisation ne sont plus servies. */
    public const CACHE_MARKER = 'anon1';

    public static function apply(\Context $context): void
    {
        $global = \Context::getContext();
        foreach ($context === $global ? [$context] : [$context, $global] as $ctx) {
            // Toujours des objets neufs, même pour un visiteur : un client non enregistré peut porter
            // des attributs posés par un module tiers (géolocalisation : geoloc_id_country, postcode)
            // que PrestaShop utilise pour l'adresse fiscale.
            $ctx->customer = new \Customer();
            // Panier vierge, jamais enregistré : aucune adresse, aucun client, aucune quantité.
            $ctx->cart = new \Cart();
        }
    }
}
