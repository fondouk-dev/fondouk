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
 *
 * Nettoyage borné (R3-04) : les fichiers expirés (TTL dépassé, anciennes versions) sont supprimés
 * lors d'une écriture, au plus une passe toutes les GC_EVERY_S secondes (marqueur fichier, aucune
 * écriture en base), au plus GC_SCAN entrées examinées et GC_BUDGET_S secondes par passe. Si une
 * passe ne trouve que des entrées valides (répertoire saturé), aucune nouvelle entrée n'est écrite
 * pendant GC_EVERY_S : les réponses restent exactes, simplement non mises en cache.
 */
final class ResponseCache
{
    private const GC_EVERY_S = 300;
    private const GC_SCAN = 5000;
    private const GC_BUDGET_S = 0.05;

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
        $this->collect($dir);
        if (is_file($dir . '/.full') && time() - (int) filemtime($dir . '/.full') < self::GC_EVERY_S) {
            return;
        }
        @file_put_contents($this->path($key), $json, LOCK_EX);
    }

    /**
     * Passe de nettoyage bornée en fréquence, en nombre d'entrées et en durée.
     *
     * @return int fichiers supprimés (0 si aucune passe n'était due)
     */
    public function collect(string $dir, bool $force = false): int
    {
        $marker = $dir . '/.gc';
        if (!$force && is_file($marker) && time() - (int) filemtime($marker) < self::GC_EVERY_S) {
            return 0;
        }
        @touch($marker);
        $handle = @opendir($dir);
        if ($handle === false) {
            return 0;
        }
        $start = microtime(true);
        $now = time();
        $scanned = 0;
        $deleted = 0;
        while (($name = readdir($handle)) !== false && $scanned < self::GC_SCAN) {
            if (!str_starts_with($name, 'fondouk_') || !str_ends_with($name, '.json')) {
                continue;
            }
            ++$scanned;
            $file = $dir . '/' . $name;
            $mtime = @filemtime($file);
            if ($mtime !== false && $now - $mtime > $this->ttl && @unlink($file)) {
                ++$deleted;
            }
            if ($scanned % 100 === 0 && microtime(true) - $start > self::GC_BUDGET_S) {
                break;
            }
        }
        closedir($handle);
        if ($scanned >= self::GC_SCAN && $deleted === 0) {
            @touch($dir . '/.full');
        } elseif (is_file($dir . '/.full')) {
            @unlink($dir . '/.full');
        }

        return $deleted;
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
