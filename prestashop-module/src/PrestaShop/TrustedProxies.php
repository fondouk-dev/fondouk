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
 * Proxys de confiance (réglage facultatif, vide par défaut = aucun) : seules les connexions venant
 * de ces adresses ou plages voient leur en-tête X-Forwarded-For pris en compte par la limite de
 * débit (ClientIp). Validation stricte : adresse IPv4/IPv6 ou plage CIDR, plages trop larges
 * refusées (une plage plus large que /8 en IPv4 ou /16 en IPv6 reviendrait à faire confiance à
 * n'importe qui). Aucune confiance automatique, même envers les adresses privées.
 *
 * Indice « proxy probable » : une requête agent arrive d'une adresse privée ou locale AVEC un
 * en-tête X-Forwarded-For alors qu'aucun proxy n'est déclaré. Relevé au plus une fois par jour ;
 * un client extérieur ne peut pas le provoquer (il faut une connexion depuis une adresse privée).
 * Ce n'est qu'un indice : tous les proxys ne sont pas détectables.
 */
final class TrustedProxies
{
    public const CONFIG_KEY = 'FONDOUK_TRUSTED_PROXIES';
    public const HINT_AT_KEY = 'FONDOUK_PROXY_HINT_AT';
    public const HINT_FROM_KEY = 'FONDOUK_PROXY_HINT_FROM';
    public const MAX_ENTRIES = 32;
    private const MIN_PREFIX_V4 = 8;
    private const MIN_PREFIX_V6 = 16;
    private const HINT_EVERY_S = 86400;

    /** @return array<int,string> entrées enregistrées (normalisées) */
    public static function list(): array
    {
        $raw = (string) \Configuration::getGlobalValue(self::CONFIG_KEY);

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($e) => $e !== ''));
    }

    /**
     * Analyse une saisie (séparateurs : virgule, espace, retour à la ligne).
     *
     * @return array{0:array<int,string>,1:array<int,string>} [entrées valides normalisées, entrées refusées]
     */
    public static function parse(string $input): array
    {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $normalized = self::normalize($token);
            if ($normalized === null || count($valid) >= self::MAX_ENTRIES) {
                $invalid[] = $token;
            } elseif (!in_array($normalized, $valid, true)) {
                $valid[] = $normalized;
            }
        }

        return [$valid, $invalid];
    }

    /** @param array<int,string> $entries entrées déjà validées par parse() */
    public static function save(array $entries): bool
    {
        return (bool) \Configuration::updateGlobalValue(self::CONFIG_KEY, implode(',', $entries));
    }

    /** Relève l'indice « proxy probable » (voir la classe). Sans effet si un proxy est déclaré. */
    public static function noteHint(string $remote, string $forwardedFor): void
    {
        if ($forwardedFor === '' || !filter_var($remote, FILTER_VALIDATE_IP)
            || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return;
        }
        if (time() - (int) \Configuration::getGlobalValue(self::HINT_AT_KEY) < self::HINT_EVERY_S) {
            return;
        }
        \Configuration::updateGlobalValue(self::HINT_AT_KEY, (string) time());
        \Configuration::updateGlobalValue(self::HINT_FROM_KEY, $remote);
    }

    /** @return array{at:int,from:string}|null indice relevé depuis moins de $maxAgeS secondes */
    public static function hint(int $maxAgeS = 30 * 86400): ?array
    {
        $at = (int) \Configuration::getGlobalValue(self::HINT_AT_KEY);
        if ($at <= 0 || time() - $at > $maxAgeS) {
            return null;
        }

        return ['at' => $at, 'from' => (string) \Configuration::getGlobalValue(self::HINT_FROM_KEY)];
    }

    private static function normalize(string $token): ?string
    {
        if (preg_match('/[^0-9a-fA-F:.\/]/', $token)) {
            return null;
        }
        $parts = explode('/', $token);
        if (count($parts) > 2 || !filter_var($parts[0], FILTER_VALIDATE_IP)) {
            return null;
        }
        $bin = inet_pton($parts[0]);
        if ($bin === false) {
            return null;
        }
        $address = (string) inet_ntop($bin);
        if (!isset($parts[1])) {
            return $address;
        }
        if (!ctype_digit($parts[1])) {
            return null;
        }
        $bits = (int) $parts[1];
        $isV6 = strlen($bin) === 16;
        $min = $isV6 ? self::MIN_PREFIX_V6 : self::MIN_PREFIX_V4;
        $max = $isV6 ? 128 : 32;

        return $bits >= $min && $bits <= $max ? $address . '/' . $bits : null;
    }
}
