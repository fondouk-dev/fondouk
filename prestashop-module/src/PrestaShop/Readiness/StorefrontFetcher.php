<?php
/**
 * Fondouk — AI Agent Ready for PrestaShop (UCP, MCP).
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace PrestaShop\Module\Fondouk\PrestaShop\Readiness;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Accès HTTP du diagnostic à SA PROPRE boutique (vitrine, endpoints agents).
 *
 * Règles de sécurité (spécification du Lot 2 §8) :
 *  - hôte cible = hôte de la boutique UNIQUEMENT (jamais une URL saisie) ; aucune redirection
 *    suivie hors de cet hôte ;
 *  - 5 s par requête, 2 Mio au plus par réponse, 60 requêtes au plus par passage ;
 *  - redirections : même hôte, même port, même schéma (seule la montée http -> https sur 443 est
 *    admise) ; jamais d'identifiants ni d'antislash dans l'URL ; protocoles HTTP et HTTPS seulement ;
 *  - durée de chaque requête bornée aussi par le temps restant du budget de la passe ;
 *  - aucun cookie, aucun en-tête d'authentification ; un jeton propre à la passe (SelfCheckToken)
 *    permet au journal d'accès d'exclure ces requêtes de la télémétrie ;
 *  - si l'URL publique est injoignable depuis le serveur (NAT, pare-feu), repli sur 127.0.0.1
 *    en conservant l'hôte et le SNI (CURLOPT_CONNECT_TO).
 */
final class StorefrontFetcher
{
    public const USER_AGENT = 'FondoukReadiness/2.0 (+self-check)';
    public const TIMEOUT_S = 5;
    public const MAX_BYTES = 2097152;
    public const MAX_REQUESTS = 60;
    private const MAX_REDIRECTS = 3;

    private string $scheme;
    private string $host;
    private int $port;
    private string $basePath;
    private int $requests = 0;
    /** @var ''|'public'|'loopback'|'unreachable' */
    private string $path = '';
    /** @var callable */
    private $transport;
    private ?float $deadline = null;

    /**
     * @param callable|null $transport fn(string $method, string $url, ?string $body, array $headers, ?string $connectTo, float $timeoutS): array{status:int,headers:array<string,string>,body:string,error:?string}
     * @param array<string,string> $extraHeaders en-têtes ajoutés à chaque requête (jeton de la passe)
     */
    public function __construct(string $baseUrl, ?callable $transport = null, private readonly array $extraHeaders = [])
    {
        $p = parse_url($baseUrl);
        $this->scheme = strtolower((string) ($p['scheme'] ?? 'http'));
        $this->host = strtolower((string) ($p['host'] ?? ''));
        $this->port = (int) ($p['port'] ?? ($this->scheme === 'https' ? 443 : 80));
        $this->basePath = rtrim((string) ($p['path'] ?? ''), '/');
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /** Échéance de la passe (microtime) : aucune requête ne la dépasse. */
    public function setDeadline(float $deadline): void
    {
        $this->deadline = $deadline;
    }

    /** Chemin effectivement utilisé pour joindre la vitrine (inscrit dans le contexte du rapport). */
    public function path(): string
    {
        return $this->path === '' ? 'not_used' : $this->path;
    }

    public function url(string $path): string
    {
        $default = $this->scheme === 'https' ? 443 : 80;

        return $this->scheme . '://' . $this->host . ($this->port !== $default ? ':' . $this->port : '') . $this->basePath . '/' . ltrim($path, '/');
    }

    /** @param array<string,string> $headers */
    public function get(string $path, array $headers = []): FetchResult
    {
        return $this->request('GET', $this->url($path), null, $headers);
    }

    /**
     * @param array<string,mixed> $json
     * @param array<string,string> $headers
     */
    public function postJson(string $path, array $json, array $headers = []): FetchResult
    {
        return $this->request('POST', $this->url($path), (string) json_encode($json), ['Content-Type' => 'application/json'] + $headers);
    }

    /** @param array<string,string> $headers */
    private function request(string $method, string $url, ?string $body, array $headers): FetchResult
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            if (!$this->sameHost($url)) {
                return FetchResult::error('off_host');
            }
            if ($this->requests >= self::MAX_REQUESTS) {
                return FetchResult::error('request_cap');
            }
            if ($this->timeoutS() <= 0) {
                return FetchResult::error('budget');
            }
            $res = $this->send($method, $url, $body, $headers);
            if ($res->error !== null || !in_array($res->status, [301, 302, 303, 307, 308], true)) {
                return $res;
            }
            $location = $res->header('location');
            if ($location === '') {
                return $res;
            }
            $url = str_starts_with($location, '/') ? $this->scheme . '://' . $this->hostPort() . $location : $location;
            if ($res->status === 303) {
                $method = 'GET';
                $body = null;
            }
        }

        return FetchResult::error('network');
    }

    /** @param array<string,string> $headers */
    private function send(string $method, string $url, ?string $body, array $headers): FetchResult
    {
        $headers = ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json, text/html;q=0.9, */*;q=0.5'] + $this->extraHeaders + $headers;
        $loopback = $this->host . ':' . $this->port . ':127.0.0.1:' . ($this->scheme === 'https' ? 443 : 80);

        if ($this->path !== 'loopback') {
            ++$this->requests;
            $raw = ($this->transport)($method, $url, $body, $headers, null, $this->timeoutS());
            if ($raw['error'] === null || $this->path === 'public') {
                if ($raw['error'] === null) {
                    $this->path = 'public';
                }

                return FetchResult::fromRaw($raw);
            }
            if ($raw['error'] !== 'network') {
                return FetchResult::fromRaw($raw);
            }
        }

        // URL publique injoignable depuis le serveur : même hôte, via l'interface locale.
        if ($this->requests >= self::MAX_REQUESTS) {
            return FetchResult::error('request_cap');
        }
        if ($this->timeoutS() <= 0) {
            return FetchResult::error('budget');
        }
        ++$this->requests;
        $raw = ($this->transport)($method, $url, $body, $headers, $loopback, $this->timeoutS());
        $this->path = $raw['error'] === null ? 'loopback' : 'unreachable';

        return FetchResult::fromRaw($raw);
    }

    private function hostPort(): string
    {
        $default = $this->scheme === 'https' ? 443 : 80;

        return $this->host . ($this->port !== $default ? ':' . $this->port : '');
    }

    /** Durée permise pour la prochaine requête : TIMEOUT_S, bornée par le temps restant de la passe. */
    private function timeoutS(): float
    {
        return $this->deadline === null ? (float) self::TIMEOUT_S : min((float) self::TIMEOUT_S, $this->deadline - microtime(true));
    }

    /**
     * Cible autorisée : l'hôte de la boutique, sur le même port et le même schéma (ou la montée
     * http -> https sur 443). Une redirection vers un autre port du même hôte (service interne)
     * ou vers http depuis https est refusée.
     */
    private function sameHost(string $url): bool
    {
        if (preg_match('/[\\\\\s]/', $url)) {
            return false; // antislash, espace : interprétés différemment par parse_url et cURL
        }
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || strtolower((string) $p['host']) !== $this->host) {
            return false;
        }
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));

        return ($scheme === $this->scheme && $port === $this->port)
            || ($this->scheme === 'http' && $scheme === 'https' && $port === 443);
    }

    /**
     * Transport réel (cURL). Aucune redirection automatique (gérées ci-dessus, même hôte
     * seulement), aucun cookie, taille et durée bornées.
     *
     * @param array<string,string> $headers
     *
     * @return array{status:int,headers:array<string,string>,body:string,error:?string}
     */
    public static function curlTransport(string $method, string $url, ?string $body, array $headers, ?string $connectTo, float $timeoutS = self::TIMEOUT_S): array
    {
        $timeoutMs = max(1, (int) ($timeoutS * 1000));
        $ch = curl_init($url);
        $buffer = '';
        $tooLarge = false;
        $respHeaders = [];
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, $timeoutMs),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_COOKIE => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$tooLarge): int {
                if (strlen($buffer) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;

                    return 0; // interrompt le transfert
                }
                $buffer .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        if ($connectTo !== null && defined('CURLOPT_CONNECT_TO')) {
            $opts[CURLOPT_CONNECT_TO] = [$connectTo];
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $connected = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
        curl_close($ch);

        $error = null;
        if ($tooLarge) {
            $error = 'too_large';
        } elseif ($errno === CURLE_OPERATION_TIMEDOUT && !$connected) {
            $error = 'network'; // aucune connexion établie (pare-feu, NAT sans boucle) : repli local possible
        } elseif ($errno === CURLE_OPERATION_TIMEDOUT) {
            $error = 'timeout';
        } elseif ($errno !== 0) {
            $error = 'network';
        }

        return ['status' => $status, 'headers' => $respHeaders, 'body' => $buffer, 'error' => $error];
    }
}
