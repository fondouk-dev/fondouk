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
 * Jeton d'une passe du Commerce Diagnostic : aléatoire, propre à la passe et à la boutique,
 * envoyé par StorefrontFetcher dans l'en-tête HEADER. Le journal d'accès n'exclut une requête
 * de la télémétrie que si elle présente ce jeton : l'adresse IP et l'agent utilisateur, que
 * tout client peut imiter (X-Forwarded-For derrière un proxy), ne suffisent plus.
 * Seule l'empreinte SHA-256 est stockée ; le jeton expire avec la passe.
 */
final class SelfCheckToken
{
    public const HEADER = 'X-Fondouk-Self-Check';
    private const KEY = 'FONDOUK_READINESS_SELFCHECK'; // supprimée à la désinstallation (fondouk.php)
    private const MARGIN_S = 30;

    public static function issue(int $idShop, float $budgetS): string
    {
        $token = bin2hex(random_bytes(16));
        $all = self::all();
        $all[$idShop] = ['h' => hash('sha256', $token), 'e' => time() + (int) ceil($budgetS) + self::MARGIN_S];
        \Configuration::updateGlobalValue(self::KEY, (string) json_encode($all));

        return $token;
    }

    public static function revoke(int $idShop): void
    {
        $all = self::all();
        if (isset($all[$idShop])) {
            unset($all[$idShop]);
            \Configuration::updateGlobalValue(self::KEY, (string) json_encode($all));
        }
    }

    public static function isValid(int $idShop, string $presented): bool
    {
        if ($presented === '') {
            return false;
        }
        $entry = self::all()[$idShop] ?? null;

        return is_array($entry) && isset($entry['e'], $entry['h'])
            && time() <= (int) $entry['e']
            && hash_equals((string) $entry['h'], hash('sha256', $presented));
    }

    /**
     * Contenu décodé de la configuration : non garanti (valeur stockée), d'où les contrôles
     * de forme dans isValid().
     *
     * @return array<int|string,mixed>
     */
    private static function all(): array
    {
        $all = json_decode((string) \Configuration::getGlobalValue(self::KEY), true);

        return is_array($all) ? $all : [];
    }
}
