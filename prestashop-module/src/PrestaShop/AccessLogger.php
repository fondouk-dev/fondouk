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
 * Télémétrie locale (spec §8bis) — seule écriture runtime du module.
 * IP jamais en clair (SHA-256 + sel secret). UCP-Agent loggé s'il est présent (décision Q10).
 *
 * CONTRAT « échappement à l'affichage » (Gate 2 F3, NON NÉGOCIABLE) : `user_agent` et
 * `ucp_agent_profile` sont stockés BRUTS (via pSQL, sûr en base) mais proviennent d'en-têtes
 * contrôlés par le client → ce sont des vecteurs de XSS STOCKÉE. Tout consommateur (dashboard
 * Pro à venir, export, admin) DOIT échapper ces deux colonnes au rendu (htmlspecialchars /
 * échappement Twig). Ne jamais les afficher en HTML brut. Aucun code d'affichage n'existe encore :
 * la règle est gravée ici pendant qu'il est encore temps.
 */
final class AccessLogger
{
    public static function log(
        \Context $context,
        string $endpoint,
        ?string $capability,
        int $httpStatus,
        ?int $resultCount,
        int $responseMs,
        int $idLang = 0,
        int $idCurrency = 0,
    ): void {
        try {
            $salt = (string) \Configuration::get('FONDOUK_IP_SALT');
            $ip = (string) \Tools::getRemoteAddr();
            $ipHash = ($ip !== '' && $salt !== '') ? hash('sha256', $ip . $salt) : null;

            $headers = \Symfony\Component\HttpFoundation\Request::createFromGlobals()->headers;
            $ucpAgent = $headers->get('UCP-Agent');
            $userAgent = $headers->get('User-Agent');

            \Db::getInstance()->insert('fondouk_access_log', [
                'date_add' => date('Y-m-d H:i:s'),
                'id_shop' => (int) $context->shop->id,
                'endpoint' => pSQL($endpoint),
                'capability' => $capability !== null ? pSQL($capability) : null,
                'ip_hash' => $ipHash,
                'ucp_agent_profile' => $ucpAgent !== null ? pSQL(substr((string) $ucpAgent, 0, 255)) : null,
                'user_agent' => $userAgent !== null ? pSQL(substr((string) $userAgent, 0, 255)) : null,
                'id_lang' => $idLang ?: null,
                'id_currency' => $idCurrency ?: null,
                'http_status' => $httpStatus,
                'response_ms' => $responseMs,
                'result_count' => $resultCount,
            ], false, true, \Db::INSERT_IGNORE);
        } catch (\Throwable $e) {
            // La télémétrie ne doit jamais casser une réponse.
        }
    }
}
