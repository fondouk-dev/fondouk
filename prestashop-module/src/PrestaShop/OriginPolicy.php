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
 * Validation de l'en-tête `Origin` du serveur MCP (M4 ; MCP Streamable HTTP : « Servers MUST
 * validate the Origin header [...] If the Origin header is present and invalid, servers MUST
 * respond with HTTP 403 »), contre le rebinding DNS et les pages tierces dans un navigateur.
 *
 *  - Origin ABSENT : accepté (clients hors navigateur : CLI, SDK, serveurs d'agents).
 *  - Origin présent : accepté seulement s'il figure dans la politique, sinon 403 ; une valeur
 *    malformée (chemin, identifiants, plusieurs origines, `null` opaque…) est refusée.
 *
 * Politique : les URL déclarées de la BOUTIQUE COURANTE dans PrestaShop (`shop_url` : domaine
 * en http, domaine SSL en https, URL principale et secondaires, boutique par boutique en
 * multiboutique), plus les origines listées par le marchand dans `FONDOUK_MCP_ALLOWED_ORIGINS`
 * (facultatif, sans écran ; séparées par des virgules ou des espaces ; une entrée invalide est
 * ignorée). Les en-têtes `Host` et `X-Forwarded-Host` et l'origine déclarée par le client ne
 * sont JAMAIS une source de confiance. Comparaison sur schéma, hôte et port normalisés.
 */
final class OriginPolicy
{
    public const CONFIG_KEY = 'FONDOUK_MCP_ALLOWED_ORIGINS';

    private const MAX_EXTRA = 32;

    /**
     * Forme canonique `schéma://hôte[:port]` (hôte en minuscules, port par défaut omis), ou null
     * si la valeur n'est pas une origine http(s) sérialisée.
     */
    public static function normalize(string $origin): ?string
    {
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (!preg_match('#^(https?)://(\[[0-9a-f:.]+\]|' . $label . '(?:\.' . $label . ')*)(?::([0-9]{1,5}))?$#iD', $origin, $m)) {
            return null;
        }
        $scheme = strtolower($m[1]);
        $host = strtolower($m[2]);
        $port = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }
        if ($host[0] === '[' && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }
        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    /**
     * Origines de confiance de la boutique.
     *
     * @return list<string>
     */
    public static function allowedFor(int $idShop): array
    {
        $out = [];
        try {
            foreach (\ShopUrl::getShopUrls($idShop) as $url) {
                if (!$url instanceof \ShopUrl || !(bool) $url->active) {
                    continue;
                }
                foreach (['http://' . $url->domain, 'https://' . $url->domain_ssl] as $candidate) {
                    $n = self::normalize((string) $candidate);
                    if ($n !== null) {
                        $out[$n] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Lecture impossible : aucune URL de la boutique n'est retenue. Les origines listées dans
            // FONDOUK_MCP_ALLOWED_ORIGINS restent acceptées ; toute autre origine reçoit un refus propre (403).
        }
        $extra = preg_split('/[\s,]+/', (string) \Configuration::get(self::CONFIG_KEY), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_slice($extra, 0, self::MAX_EXTRA) as $candidate) {
            $n = self::normalize($candidate);
            if ($n !== null) {
                $out[$n] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @return array{0:bool,1:?string} [requête acceptée, origine à renvoyer en CORS (null si absente)]
     */
    public static function evaluate(?string $origin, int $idShop): array
    {
        if ($origin === null) {
            return [true, null];
        }
        $n = self::normalize($origin);
        if ($n === null || !in_array($n, self::allowedFor($idShop), true)) {
            return [false, null];
        }

        return [true, $origin];
    }
}
