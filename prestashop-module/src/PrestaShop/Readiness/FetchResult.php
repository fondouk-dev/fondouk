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

/** Réponse d'une requête du diagnostic vers sa propre boutique. */
final class FetchResult
{
    /**
     * @param array<string,string> $headers en-têtes en minuscules
     */
    private function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?string $error,
    ) {
    }

    /** @param array{status:int,headers:array<string,string>,body:string,error:?string} $raw */
    public static function fromRaw(array $raw): self
    {
        return new self((int) $raw['status'], $raw['headers'], (string) $raw['body'], $raw['error']);
    }

    public static function error(string $error): self
    {
        return new self(0, [], '', $error);
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /** @return array<mixed>|null */
    public function json(): ?array
    {
        $d = json_decode($this->body, true);

        return is_array($d) ? $d : null;
    }

    /**
     * Raison NOT_TESTED si la boutique n'a pas pu être jointe ou interrogée : une panne
     * d'accès n'est JAMAIS une non-conformité (FAIL), ni un succès (PASS).
     */
    public function unavailableReason(): ?string
    {
        if ($this->error !== null) {
            return match ($this->error) {
                'timeout' => 'timeout',
                'request_cap', 'budget' => 'budget',
                default => 'network',
            };
        }

        return match ($this->status) {
            401, 407 => 'http_auth',
            503 => 'maintenance',
            429 => 'rate_limited',
            default => null,
        };
    }
}
