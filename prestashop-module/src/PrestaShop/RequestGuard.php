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
 * Garde d'entrée : borne la taille du corps de requête (anti-DoS mémoire, Gate 2 F2a).
 * Lit au plus MAX_BODY octets ; au-delà → « trop volumineux ».
 */
final class RequestGuard
{
    public const MAX_BODY = 262144; // 256 KiB — large pour une requête catalogue, borne le risque.

    /**
     * @return array{0:bool,1:?array} [tooLarge, données décodées | null si JSON invalide]
     */
    public static function jsonBody(int $max = self::MAX_BODY): array
    {
        $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        $contentLength = (int) $request->server->get('CONTENT_LENGTH', 0);
        if ($contentLength > $max) {
            return [true, null];
        }

        // Lit au plus $max+1 octets via le flux Symfony : si on dépasse, c'est trop volumineux
        // (Content-Length absent/menti). Lecture bornée = anti-DoS mémoire (Gate 2 F2a).
        $stream = $request->getContent(true);
        $raw = is_resource($stream) ? (string) stream_get_contents($stream, $max + 1) : '';
        if (strlen($raw) > $max) {
            return [true, null];
        }

        $data = json_decode($raw, true);

        return [false, is_array($data) ? $data : null];
    }
}
