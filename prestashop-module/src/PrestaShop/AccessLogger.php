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
    /** Rétention par défaut (jours) si `FONDOUK_RETENTION_DAYS` n'est pas défini. */
    private const DEFAULT_RETENTION_DAYS = 90;

    /** Budget BORNÉ par passage : au plus ce nombre de lots, puis on rend la main. */
    private const MAX_BATCHES_PER_PASS = 3;

    /** Taille d'un lot de suppression (verrous InnoDB courts). */
    private const BATCH_SIZE = 10000;

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

            // Rétention AUTOMATIQUE (au plus 1×/jour) : sans back-office, la table
            // `fondouk_access_log` n'avait AUCUNE purge — elle croissait sans borne.
            // On la borne ici, sur le chemin d'écriture (le seul moment où ce module sert :
            // s'il défère au Pro, ce code ne s'exécute pas, et le Pro purge de son côté).
            self::maybePurgeOldLogs((int) $context->shop->id);
        } catch (\Throwable $e) {
            // La télémétrie ne doit jamais casser une réponse.
        }
    }

    /**
     * Rétention AUTOMATIQUE, sans cron : la purge tourne sur le chemin d'écriture, mais
     * garantit deux propriétés que le validateur (et le RGPD) attendent :
     *
     *  - NON BLOQUANTE : budget BORNÉ par passage (au plus {@see MAX_BATCHES_PER_PASS} lots
     *    de {@see BATCH_SIZE}). Si un backlog dépasse ce budget, on rend la main : le reliquat
     *    est traité au PROCHAIN accès (la requête agent ne paie jamais 100 k suppressions).
     *  - SANS COURSE : deux accès simultanés au premier hit du jour n'exécutent PAS deux
     *    purges concurrentes — un verrou applicatif MySQL (`GET_LOCK`, non bloquant, timeout 0)
     *    laisse un seul purgeur travailler ; l'autre passe son chemin.
     *
     * Rétention = `FONDOUK_RETENTION_DAYS` (surchargeable par un admin averti, sans écran)
     * ou {@see DEFAULT_RETENTION_DAYS} par défaut. Silencieuse : jamais d'exception propagée.
     */
    private static function maybePurgeOldLogs(int $idShop): void
    {
        $db = \Db::getInstance();
        $lock = 'fondouk_purge_' . $idShop;
        try {
            $today = date('Y-m-d');
            // Chemin rapide : rien à faire si le backlog a déjà été vidé aujourd'hui.
            if ((string) \Configuration::get('FONDOUK_LAST_PURGE') === $today) {
                return;
            }
            // Verrou léger NON bloquant : un seul purgeur à la fois (anti-course). timeout 0
            // => renvoie immédiatement 0 si un autre accès purge déjà -> on ne fait rien.
            if ((int) $db->getValue("SELECT GET_LOCK('" . pSQL($lock) . "', 0)") !== 1) {
                return;
            }
            try {
                // Re-vérifie SOUS verrou (l'accès qui détenait le verrou a pu finir le jour).
                if ((string) \Configuration::get('FONDOUK_LAST_PURGE') === $today) {
                    return;
                }
                $days = (int) \Configuration::get('FONDOUK_RETENTION_DAYS');
                if ($days <= 0) {
                    $days = self::DEFAULT_RETENTION_DAYS;
                }

                $where = 'id_shop = ' . $idShop
                       . ' AND date_add < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)';
                $moreRemains = false;
                for ($i = 0; $i < self::MAX_BATCHES_PER_PASS; ++$i) {
                    if (!$db->execute('DELETE FROM `' . _DB_PREFIX_ . 'fondouk_access_log` WHERE ' . $where . ' LIMIT ' . self::BATCH_SIZE)) {
                        break; // erreur DB : on s'arrête (réessai au prochain déclenchement)
                    }
                    if ((int) $db->Affected_Rows() < self::BATCH_SIZE) {
                        break; // backlog vidé
                    }
                    if ($i === self::MAX_BATCHES_PER_PASS - 1) {
                        $moreRemains = true; // dernier lot encore plein -> il reste du travail
                    }
                }
                // On ne marque « fini pour aujourd'hui » que si le backlog est VIDÉ. Sinon on
                // laisse le prochain accès poursuivre (budget borné à chaque fois).
                if (!$moreRemains) {
                    \Configuration::updateValue('FONDOUK_LAST_PURGE', $today);
                }
            } finally {
                $db->execute("SELECT RELEASE_LOCK('" . pSQL($lock) . "')");
            }
        } catch (\Throwable $e) {
            // Une purge ratée ne doit jamais casser une réponse ni bloquer la requête.
        }
    }
}
