<?php

declare(strict_types=1);

namespace Atom\Component\Cache;

use RuntimeException;

/**
 * Compiles and compacts all PHP configuration files from config/ into a single
 * OPcache-friendly PHP file inside runtime/cache/config/.
 */
final readonly class CacheMinfy
{
    /**
     * Compiles all PHP array files in $configDir into a single minified PHP cache file.
     *
     * @return string Path to the generated compiled configuration file
     */
    public static function compileConfigDirectory(string $configDir, ?string $targetFile = null): string
    {
        $normalizedDir = \rtrim($configDir, '/\\');
        if (!\is_dir($normalizedDir)) {
            throw new RuntimeException("Configuration directory does not exist: {$normalizedDir}");
        }

        $target = $targetFile ?? (
            \dirname($normalizedDir)
            . \DIRECTORY_SEPARATOR . 'runtime'
            . \DIRECTORY_SEPARATOR . 'cache'
            . \DIRECTORY_SEPARATOR . 'config'
            . \DIRECTORY_SEPARATOR . 'compiled.php'
        );

        $targetDir = \dirname($target);
        if (!\is_dir($targetDir) && !\mkdir($targetDir, 0750, true) && !\is_dir($targetDir)) {
            throw new RuntimeException("Failed to create config cache directory: {$targetDir}");
        }

        $compiled = [];
        $files = \glob($normalizedDir . \DIRECTORY_SEPARATOR . '*.php') ?: [];
        \sort($files);

        foreach ($files as $file) {
            if (!\is_file($file)) {
                continue;
            }
            $key = \pathinfo($file, \PATHINFO_FILENAME);
            $data = (static fn(string $path): mixed => require $path)($file);
            if (\is_array($data) && self::isExportable($data)) {
                $compiled[$key] = $data;
            }
        }

        $exported = \var_export($compiled, true);
        $compact = \preg_replace('/\s+/', ' ', $exported) ?? $exported;
        $payload = "<?php\ndeclare(strict_types=1);\nreturn {$compact};\n";

        $tmpFile = $target . '.' . \bin2hex(\random_bytes(6)) . '.tmp';
        if (\file_put_contents($tmpFile, $payload, \LOCK_EX) === false) {
            throw new RuntimeException("Failed to write compiled config cache: {$tmpFile}");
        }

        if (!\rename($tmpFile, $target)) {
            if (\is_file($tmpFile)) {
                \unlink($tmpFile);
            }
            throw new RuntimeException("Failed to move compiled config cache into place: {$target}");
        }

        if (\function_exists('opcache_invalidate')) {
            \opcache_invalidate($target, true);
        }

        return $target;
    }

    private static function isExportable(mixed $value): bool
    {
        if ($value === null || \is_scalar($value)) {
            return true;
        }

        if ($value instanceof \UnitEnum) {
            return true;
        }

        if (\is_array($value)) {
            return \array_all($value, self::isExportable(...));
        }

        return false;
    }
}
