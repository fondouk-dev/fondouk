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
 * Adresse du client pour la limite de débit (R3-03). Un en-tête de proxy (X-Forwarded-For) est
 * fourni par le client et donc imitable : il n'est lu que si la connexion vient d'un proxy
 * DÉCLARÉ de confiance (TrustedProxies, réglable dans l'écran du module ; vide par défaut =
 * aucun proxy, on retient REMOTE_ADDR).
 * Derrière un proxy de confiance, on retient l'entrée la plus à DROITE qui n'est pas elle-même
 * un proxy de confiance : c'est la dernière adresse ajoutée par un proxy qu'on contrôle ; les
 * entrées de gauche, écrites par le client, sont ignorées.
 * IPv6 : regroupement par /64 (une seule machine dispose couramment d'un /64 entier).
 */
final class ClientIp
{
    public const CONFIG_KEY = TrustedProxies::CONFIG_KEY;
    private const MAX_HOPS = 10;

    public static function forRateLimit(): string
    {
        $server = \Symfony\Component\HttpFoundation\Request::createFromGlobals()->server;
        $remote = (string) $server->get('REMOTE_ADDR', '');
        $forwardedFor = (string) $server->get('HTTP_X_FORWARDED_FOR', '');
        $trusted = self::trustedProxies();
        if ($trusted === []) {
            TrustedProxies::noteHint($remote, $forwardedFor);
        }

        return self::bucketOf(self::resolve($remote, $forwardedFor, $trusted));
    }

    /** @param array<int,string> $trusted adresses ou plages CIDR */
    public static function resolve(string $remote, string $forwardedFor, array $trusted): string
    {
        if ($trusted === [] || !self::inAny($remote, $trusted)) {
            return $remote;
        }
        $hops = array_slice(array_map('trim', explode(',', $forwardedFor)), -self::MAX_HOPS);
        for ($i = count($hops) - 1; $i >= 0; --$i) {
            $ip = $hops[$i];
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                break; // entrée illisible : on ne remonte pas plus loin dans une chaîne non fiable
            }
            if (!self::inAny($ip, $trusted)) {
                return $ip;
            }
        }

        return $remote;
    }

    /** Clé de limitation : l'adresse IPv4, ou le préfixe /64 d'une adresse IPv6. */
    public static function bucketOf(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }

        return bin2hex(substr($bin, 0, 8)) . '::/64';
    }

    /** @return array<int,string> */
    public static function trustedProxies(): array
    {
        return TrustedProxies::list();
    }

    /** @param array<int,string> $ranges */
    public static function inAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $ip, string $range): bool
    {
        [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        $bits = $bits === null ? $max : (int) $bits;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
