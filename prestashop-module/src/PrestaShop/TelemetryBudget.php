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
 * Budget de télémétrie (F-01, audit AtlasOS 2.0.0) : le volume de lignes détaillées est BORNÉ
 * par boutique et par jour, quel que soit le trafic reçu.
 *
 * Trois classes, chacune avec son plafond quotidien de lignes détaillées :
 *  - `accepted` : réponses catalogue/MCP servies (statut < 400) ;
 *  - `manifest` : appels au manifeste (200 et 304), non soumis à la limite de débit ;
 *  - `rejected` : requêtes refusées (429, 400, 405, 413, 403 du contrôle par agent…).
 *
 * Sous le plafond, chaque événement garde sa ligne détaillée (un compteur par classe et par jour
 * est incrémenté, 1 écriture). Au-delà, l'événement n'a plus de ligne : il est compté dans un
 * agrégat anonyme (`~overflow`, par source, point d'entrée et statut), lui-même à ÉCRITURES
 * BORNÉES — compte exact jusqu'à {@see EXACT_BELOW}, puis échantillonnage pondéré : un événement
 * sur w est écrit avec le poids w (w ≈ total / {@see SAMPLE_BASE}). Le nombre d'écritures croît
 * alors comme le LOGARITHME du trafic (≈ 64·ln(N/128) par seau et par jour), et l'espérance du
 * compteur reste égale au nombre réel d'événements.
 *
 * Les seaux partagent la table d'agrégats du Pro (`fondouk_access_daily`) : clés
 * (boutique, jour, famille, source, point d'entrée), familles techniques préfixées par « ~ »,
 * jamais lues comme familles d'agents. Le point d'entrée et le statut sont ramenés à des listes
 * fermées : un client ne peut pas créer de seaux à volonté. Aucune donnée personnelle.
 */
final class TelemetryBudget
{
    public const TABLE = 'fondouk_access_daily';

    /** Famille technique : compteur des lignes détaillées écrites (par classe). */
    public const DETAIL = '~detail';

    /** Famille technique : événements comptés sans ligne détaillée (au-delà du plafond). */
    public const OVERFLOW = '~overflow';

    /** Plafonds quotidiens de lignes détaillées, par boutique. */
    public const CAPS = ['accepted' => 20000, 'manifest' => 1000, 'rejected' => 1000];

    /** Compte exact de l'agrégat jusqu'à ce seuil, puis échantillonnage pondéré. */
    private const EXACT_BELOW = 128;

    private const SAMPLE_BASE = 64;

    private const ENDPOINTS = ['search', 'lookup', 'product', 'mcp', 'manifest', 'llms'];

    private const SOURCES = ['ucp', 'mcp', 'llms'];

    /** DDL identique à celle du Pro (v1.4.0) : la table peut être créée par l'un ou l'autre. */
    public static function createTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `day` DATE NOT NULL,
            `family` VARCHAR(64) NOT NULL,
            `source` ENUM(\'ucp\',\'mcp\',\'llms\',\'storefront\') NOT NULL DEFAULT \'storefront\',
            `endpoint` VARCHAR(32) NOT NULL,
            `hits` INT UNSIGNED NOT NULL DEFAULT 0,
            `distinct_products` INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY `uniq_bucket` (`id_shop`, `day`, `family`, `source`, `endpoint`),
            KEY `idx_day` (`day`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
    }

    public static function classOf(string $endpoint, int $httpStatus): string
    {
        if ($endpoint === 'manifest') {
            return 'manifest';
        }

        return $httpStatus >= 400 ? 'rejected' : 'accepted';
    }

    /**
     * L'événement peut-il avoir sa ligne détaillée ? Sinon il est compté dans l'agrégat.
     * Ne lève jamais d'exception. Si la table d'agrégats est indisponible, seules les réponses
     * servies gardent leur ligne (comportement d'avant 2.0.0) ; les refus et le manifeste,
     * qu'un client peut répéter sans limite, n'en ont pas.
     */
    public static function admit(int $idShop, string $source, string $endpoint, int $httpStatus): bool
    {
        $class = self::classOf($endpoint, $httpStatus);
        try {
            $db = \Db::getInstance();
            $t = _DB_PREFIX_ . self::TABLE;
            $day = date('Y-m-d');
            $src = in_array($source, self::SOURCES, true) ? $source : 'ucp';
            $bucket = (in_array($endpoint, self::ENDPOINTS, true) ? $endpoint : 'other')
                . ':' . (($httpStatus >= 100 && $httpStatus <= 599) ? $httpStatus : 0);
            $base = 'id_shop = ' . $idShop . ' AND `day` = "' . $day . '"';

            $rows = $db->executeS(
                'SELECT `family`, `hits` FROM `' . $t . '` WHERE ' . $base . ' AND (
                    (`family` = "' . self::DETAIL . '" AND `source` = "ucp" AND `endpoint` = "' . $class . '")
                    OR (`family` = "' . self::OVERFLOW . '" AND `source` = "' . $src . '" AND `endpoint` = "' . $bucket . '"))',
                true,
                false
            );
            if (!is_array($rows)) {
                return $class === 'accepted';
            }
            $detail = 0;
            $overflow = 0;
            foreach ($rows as $r) {
                if ($r['family'] === self::DETAIL) {
                    $detail = (int) $r['hits'];
                } else {
                    $overflow = (int) $r['hits'];
                }
            }

            if ($detail < self::CAPS[$class]) {
                // Nouveau total sans seconde lecture : LAST_INSERT_ID(expr) sur les deux chemins
                // (insertion neuve comme mise à jour), donc jamais de valeur laissée par une
                // insertion précédente sur la même connexion.
                $db->execute(
                    'INSERT INTO `' . $t . '` (`id_shop`, `day`, `family`, `source`, `endpoint`, `hits`)
                     VALUES (' . $idShop . ', "' . $day . '", "' . self::DETAIL . '", "ucp", "' . $class . '", LAST_INSERT_ID(1))
                     ON DUPLICATE KEY UPDATE `hits` = LAST_INSERT_ID(`hits` + 1)'
                );
                $n = (int) $db->Insert_ID();
                if ($n <= self::CAPS[$class]) {
                    return true;
                }
                // Course entre requêtes simultanées : le plafond vient d'être atteint.
            }

            $w = $overflow < self::EXACT_BELOW ? 1 : intdiv($overflow, self::SAMPLE_BASE);
            if ($w === 1 || random_int(1, $w) === 1) {
                $db->execute(
                    'INSERT INTO `' . $t . '` (`id_shop`, `day`, `family`, `source`, `endpoint`, `hits`)
                     VALUES (' . $idShop . ', "' . $day . '", "' . self::OVERFLOW . '", "' . $src . '", "' . $bucket . '", ' . $w . ')
                     ON DUPLICATE KEY UPDATE `hits` = `hits` + ' . $w
                );
            }

            return false;
        } catch (\Throwable $e) {
            return $class === 'accepted';
        }
    }

    /** Rétention : supprime les seaux plus anciens que `$days` jours (par lots bornés). */
    public static function purgeOlderThan(int $idShop, int $days, int $limit = 10000): void
    {
        try {
            \Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_shop = ' . $idShop
                . ' AND `family` IN ("' . self::DETAIL . '", "' . self::OVERFLOW . '")'
                . ' AND `day` < DATE_SUB(CURDATE(), INTERVAL ' . max(1, $days) . ' DAY) LIMIT ' . $limit
            );
        } catch (\Throwable $e) {
        }
    }
}
