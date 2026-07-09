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
 * Cache de réponses catalogue (filesystem, sous _PS_CACHE_DIR_).
 * La clé inclut un COMPTEUR DE VERSION (FONDOUK_CATALOG_VERSION) bumpé par les hooks
 * produit : tout changement (prix, stock, specific_price…) change la clé → invalidation
 * paresseuse immédiate, sans purge coûteuse. TTL en repli (défaut 300 s).
 */
final class ResponseCache
{
    public function __construct(private readonly int $ttl = 300)
    {
    }

    public static function version(): int
    {
        return (int) \Configuration::get('FONDOUK_CATALOG_VERSION');
    }

    /** Incrémente la version → invalide tout le cache catalogue. Appelé par les hooks. */
    public static function bump(): void
    {
        \Configuration::updateValue('FONDOUK_CATALOG_VERSION', self::version() + 1);
    }

    /** @param array<string,mixed> $context */
    public function key(string $endpoint, array $context, string $bodyHash): string
    {
        return 'fondouk_' . md5($endpoint . '|' . json_encode($context) . '|' . $bodyHash . '|v' . self::version());
    }

    public function get(string $key): ?string
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        if (time() - (int) filemtime($file) > $this->ttl) {
            @unlink($file);

            return null;
        }

        $content = file_get_contents($file);

        return $content === false ? null : $content;
    }

    public function set(string $key, string $json): void
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($this->path($key), $json, LOCK_EX);
    }

    private function dir(): string
    {
        return rtrim(_PS_CACHE_DIR_, '/') . '/fondouk';
    }

    private function path(string $key): string
    {
        return $this->dir() . '/' . $key . '.json';
    }
}
