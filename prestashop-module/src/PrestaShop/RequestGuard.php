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

    /** En-têtes qu'un agent exécuté dans un navigateur peut envoyer (pré-vol CORS). */
    public const CORS_ALLOW_HEADERS = 'Content-Type, Accept, UCP-Agent, Request-Id, MCP-Protocol-Version, Mcp-Session-Id, Mcp-Method, Mcp-Name';

    /**
     * Module gratuit en veille (Fondouk Pro installé et actif) : le Pro sert la surface agents et le
     * gratuit n'enregistre aucune route. Ses contrôleurs restent pourtant joignables par la
     * répartition directe de PrestaShop (index.php?fc=module&module=fondouk&controller=…), ce qui
     * contournerait le Pro (contrôle des agents, limites, télémétrie). Ils répondent alors 404.
     * Sans effet dans la copie embarquée par le Pro (son module s'appelle « fondoukpro »).
     */
    public static function closeIfStandby(\Module $module): void
    {
        if ($module->name === 'fondouk' && \Module::isInstalled('fondoukpro') && \Module::isEnabled('fondoukpro')) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo '{"code":"not_found"}';
            exit;
        }
    }

    /** Méthode HTTP de la requête courante. */
    public static function method(): string
    {
        return \Symfony\Component\HttpFoundation\Request::createFromGlobals()->getMethod();
    }

    /**
     * Répond au pré-vol CORS (OPTIONS) sans exécuter la requête : 204, méthodes et en-têtes
     * autorisés. Par défaut (catalogue REST, ressource publique en lecture seule), toute origine
     * est acceptée ; le MCP passe l'origine validée par {@see OriginPolicy}, ou null (pré-vol
     * sans Origin : aucun en-tête d'autorisation d'origine). Termine.
     */
    public static function sendPreflight(string $methods, ?string $allowOrigin = '*'): void
    {
        http_response_code(204);
        if ($allowOrigin !== null) {
            header('Access-Control-Allow-Origin: ' . $allowOrigin);
        }
        header('Access-Control-Allow-Methods: ' . $methods);
        header('Access-Control-Allow-Headers: ' . self::CORS_ALLOW_HEADERS);
        header('Access-Control-Max-Age: 600');
        exit;
    }

    /**
     * @return array{0:bool,1:?array,2:bool} [tooLarge, données décodées | null, corps présent mais JSON invalide]
     */
    public static function jsonBody(int $max = self::MAX_BODY): array
    {
        $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        $contentLength = (int) $request->server->get('CONTENT_LENGTH', 0);
        if ($contentLength > $max) {
            return [true, null, false];
        }

        // Lit au plus $max+1 octets via le flux Symfony : si on dépasse, c'est trop volumineux
        // (Content-Length absent/menti). Lecture bornée = anti-DoS mémoire (Gate 2 F2a).
        $stream = $request->getContent(true);
        $raw = is_resource($stream) ? (string) stream_get_contents($stream, $max + 1) : '';
        if (strlen($raw) > $max) {
            return [true, null, false];
        }

        $data = json_decode($raw, true);
        $decoded = is_array($data) ? $data : null;

        return [false, $decoded, $decoded === null && trim($raw) !== ''];
    }
}
