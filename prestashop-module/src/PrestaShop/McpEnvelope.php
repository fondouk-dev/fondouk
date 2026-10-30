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
 * Enveloppe d'une requête MCP sur Streamable HTTP : choix de l'ère et validation (M1).
 * Le serveur est « dual-era » (MCP 2026-07-28, Versioning and Compatibility) :
 *
 *  - MODERNE (2026-07-28) : sans poignée de main ; chaque requête porte sa version et les
 *    capacités du client dans `params._meta`, et les en-têtes `MCP-Protocol-Version`,
 *    `Mcp-Method` et `Mcp-Name` (`tools/call`) recopient le corps. Une seule requête par POST.
 *  - ANCIENNE (2025-06-18, 2025-03-26) : `initialize` puis requêtes sans `_meta` ; l'en-tête de
 *    version est facultatif (absent = 2025-03-26).
 *
 * Erreurs normalisées (HTTP 400) : version non prise en charge `-32022` (avec `supported` et
 * `requested`), en-tête manquant, malformé ou différent du corps `-32020`, champ `_meta`
 * obligatoire absent `-32602`. Classe sans dépendance à PrestaShop.
 */
final class McpEnvelope
{
    public const MODERN = '2026-07-28';

    /**
     * Révisions anciennes réellement servies, la plus récente d'abord : `initialize` répond la
     * version demandée si elle en fait partie, sinon la plus récente (négociation 2025-03-26 et
     * 2025-06-18). Une requête sans en-tête de version est traitée en 2025-03-26 (Streamable HTTP,
     * « MAY »). 2024-11-05 n'est pas servie : sa révision utilise le transport HTTP+SSE (GET), que
     * ce serveur n'a jamais fourni ; son en-tête reçoit -32022.
     */
    public const LEGACY = ['2025-06-18', '2025-03-26'];

    /**
     * Codes applicatifs des refus de transport en 2026-07-28, hors des plages réservées par
     * JSON-RPC (-32768 à -32000) : limite de débit (HTTP 429) et refus du marchand (HTTP 403,
     * Pro). Les clients anciens gardent -32000 et -32001, inchangés depuis la 1.x.
     */
    public const RATE_LIMITED = -31429;

    public const FORBIDDEN_BY_MERCHANT = -31403;

    public const HEADER_MISMATCH = -32020;

    public const UNSUPPORTED_PROTOCOL_VERSION = -32022;

    public const META_VERSION = 'io.modelcontextprotocol/protocolVersion';

    public const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';

    /** @return list<string> */
    public static function supported(): array
    {
        return array_merge([self::MODERN], self::LEGACY);
    }

    /** La requête se présente-t-elle comme moderne (version dans `_meta`, ou en-tête 2026-07-28) ? */
    public static function claimsModern(mixed $req, string $headerVersion): bool
    {
        return $headerVersion === self::MODERN
            || (is_array($req) && is_array($req['params'] ?? null) && is_array($req['params']['_meta'] ?? null)
                && array_key_exists(self::META_VERSION, $req['params']['_meta']));
    }

    /**
     * @param array<mixed> $req
     *
     * @return array{era:string,status:int,error:?array{code:int,message:string,data?:array<string,mixed>}}
     */
    public static function analyze(array $req, string $headerVersion, ?string $headerMethod, ?string $headerName): array
    {
        if (!self::claimsModern($req, $headerVersion)) {
            if ($headerVersion !== '' && !in_array($headerVersion, self::LEGACY, true)) {
                return self::fail(400, self::unsupported(self::validHeaderValue($headerVersion) ? $headerVersion : '(invalid header value)'));
            }

            return ['era' => 'legacy', 'status' => 200, 'error' => null];
        }

        $meta = is_array($req['params']['_meta'] ?? null) ? $req['params']['_meta'] : [];
        $version = $meta[self::META_VERSION] ?? null;
        if (!is_string($version) || $version === '') {
            return self::fail(400, ['code' => -32602, 'message' => 'Invalid params: _meta["' . self::META_VERSION . '"] is required (protocol version ' . self::MODERN . ').']);
        }
        if ($headerVersion === '' || !self::validHeaderValue($headerVersion)) {
            return self::fail(400, ['code' => self::HEADER_MISMATCH, 'message' => 'Header mismatch: the MCP-Protocol-Version header is required and must match _meta["' . self::META_VERSION . '"].']);
        }
        if ($headerVersion !== $version) {
            return self::fail(400, ['code' => self::HEADER_MISMATCH, 'message' => 'Header mismatch: MCP-Protocol-Version header value \'' . $headerVersion . '\' does not match body value \'' . $version . '\'.']);
        }
        if (!in_array($version, self::supported(), true)) {
            return self::fail(400, self::unsupported($version));
        }
        if ($version !== self::MODERN) {
            // Version ancienne déclarée des deux côtés, de façon cohérente : sémantique ancienne.
            return ['era' => 'legacy', 'status' => 200, 'error' => null];
        }
        if (!is_array($meta[self::META_CAPABILITIES] ?? null)) {
            return self::fail(400, ['code' => -32602, 'message' => 'Invalid params: _meta["' . self::META_CAPABILITIES . '"] is required (use {} when the client declares no capability).']);
        }
        // Notifications : la révision ne définit pas d'en-têtes pour leurs POST.
        if (!array_key_exists('id', $req)) {
            return ['era' => 'modern', 'status' => 202, 'error' => null];
        }
        $method = $req['method'] ?? null;
        if ($headerMethod === null || !self::validHeaderValue($headerMethod) || !is_string($method) || $headerMethod !== $method) {
            return self::fail(400, ['code' => self::HEADER_MISMATCH, 'message' => 'Header mismatch: the Mcp-Method header is required and must equal the request method.']);
        }
        if ($method === 'tools/call') {
            $name = $req['params']['name'] ?? null;
            $decoded = $headerName === null ? null : self::decodeHeaderValue($headerName);
            if ($decoded === null || !is_string($name) || $decoded !== $name) {
                return self::fail(400, ['code' => self::HEADER_MISMATCH, 'message' => 'Header mismatch: the Mcp-Name header is required for tools/call and must equal params.name.']);
            }
        }

        return ['era' => 'modern', 'status' => 200, 'error' => null];
    }

    /** @return array{code:int,message:string,data:array{supported:list<string>,requested:string}} */
    public static function unsupported(string $requested): array
    {
        return [
            'code' => self::UNSUPPORTED_PROTOCOL_VERSION,
            'message' => 'Unsupported protocol version',
            'data' => ['supported' => self::supported(), 'requested' => $requested],
        ];
    }

    /** Valeur d'en-tête visible ASCII, espace et tabulation (RFC 9110). */
    private static function validHeaderValue(string $value): bool
    {
        return (bool) preg_match('/^[\x20-\x7E\t]*$/D', $value);
    }

    /** Valeur en clair, ou décodée du format sentinelle `=?base64?…?=` ; null si invalide. */
    private static function decodeHeaderValue(string $value): ?string
    {
        if (!self::validHeaderValue($value)) {
            return null;
        }
        if (preg_match('/^=\?base64\?([A-Za-z0-9+\/]*={0,2})\?=$/D', $value, $m)) {
            $decoded = base64_decode($m[1], true);

            return $decoded === false ? null : $decoded;
        }

        return $value;
    }

    /**
     * @param array{code:int,message:string,data?:array<string,mixed>} $error
     *
     * @return array{era:string,status:int,error:array{code:int,message:string,data?:array<string,mixed>}}
     */
    private static function fail(int $status, array $error): array
    {
        return ['era' => 'modern', 'status' => $status, 'error' => $error];
    }
}
