<?php

declare(strict_types=1);

namespace Atom\Config;

use Atom\Exception\IO\Generative\FileNotFoundGenerativeException;
use RuntimeException;

/**
 * Centralized configuration manager with `.env` loading, driver choice resolution,
 * and `var_export`-safe configuration caching in `runtime/cache/config/`.
 */
final class Config extends EnvironmentVariables
{
    protected const string PATH_CACHED_CONFIG = \DIRECTORY_SEPARATOR . 'runtime' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'config' . \DIRECTORY_SEPARATOR;

    /**
     * @var array<string, array<string, mixed>>
     */
    protected static array $from = [];

    /**
     * @var list<string>
     */
    protected static array $configFileName = [
        'app',
        'database',
        'cache',
        'session',
        'mail',
        'auth',
        'filesystem',
        'modul',
        'logger',
        'cluster',
        'oauth',
        'exchange',
        'mcp',
        'profiler',
        'inspector',
        'async',
    ];

    public function __construct(
        string $appPath,
        bool $autoLoadConfig = true,
        bool $autoLoadConfigFromCache = true,
        ?string $environmentPath = null,
    ) {
        if ($autoLoadConfig) {
            self::autoLoad($appPath, $autoLoadConfigFromCache, $environmentPath);
        }
    }

    public static function autoLoad(
        string $appPath,
        bool $autoLoadConfigFromCacheIfAvailable = true,
        ?string $environmentPath = null,
    ): bool {
        $normalizedAppPath = \rtrim($appPath, '/\\');

        if ($autoLoadConfigFromCacheIfAvailable && self::checkConfigCacheIsAvailable($normalizedAppPath)) {
            foreach (self::getConfigFileNames() as $fileName) {
                $cacheFile = $normalizedAppPath . self::PATH_CACHED_CONFIG . $fileName . '.php';
                if (!\is_file($cacheFile)) {
                    throw new FileNotFoundGenerativeException('Invalid config data in cache for file: %s', $fileName);
                }

                $configData = require $cacheFile;
                if (\is_array($configData)) {
                    static::$from[$fileName] = $configData;
                    continue;
                }

                throw new FileNotFoundGenerativeException('Invalid config data in cache for file: %s', $fileName);
            }

            return true;
        }

        $environmentPathAbsolute = parent::setEnvironmentPath($environmentPath, $normalizedAppPath);
        $envFilePath = \str_ends_with($environmentPathAbsolute, '.env')
            ? $environmentPathAbsolute
            : $environmentPathAbsolute . '.env';

        if (!parent::checkEnvironmentFileExists($envFilePath)) {
            throw new FileNotFoundGenerativeException('Environment file not found at path: %s', $envFilePath);
        }

        parent::load($environmentPathAbsolute);
        parent::ensureRequiredEnvironmentVariables();

        foreach (self::getConfigFileNames() as $fileName) {
            $configFile = $normalizedAppPath . \DIRECTORY_SEPARATOR . 'config' . \DIRECTORY_SEPARATOR . $fileName . '.php';
            if (!\is_file($configFile)) {
                throw new FileNotFoundGenerativeException('Config file not found: %s', $configFile);
            }

            $configData = require $configFile;
            if (\is_array($configData)) {
                static::$from[$fileName] = $configData;
                continue;
            }

            throw new FileNotFoundGenerativeException('Invalid config data for file: %s', $fileName);
        }

        $isDebug = (bool) (static::$from['app']['debug'] ?? true);
        if (!$isDebug && $autoLoadConfigFromCacheIfAvailable && self::isExportable(static::$from)) {
            self::writeConfigCache($normalizedAppPath);
        }

        return true;
    }

    public static function get(string $key, bool $useDefaultChoice = true): mixed
    {
        $var = static::$from[$key] ?? null;
        if ($var === null) {
            throw new RuntimeException("Config key '{$key}' not found or not set config data.");
        }

        if (!$useDefaultChoice) {
            return $var;
        }

        if (isset($var['default'], $var['choices']) && \is_array($var['choices'])) {
            $defaultChoice = $var['default'];
            $choices = $var['choices'];

            if (\is_array($defaultChoice)) {
                foreach ($defaultChoice as $keys => $value) {
                    if (!\is_scalar($value) || !isset($choices[(string) $value])) {
                        throw new RuntimeException("Default choice '" . (string) $value . "' not found in choices for config key '{$key}'.");
                    }
                    $var['driver'][$keys] = $choices[(string) $value];
                }
            } else {
                $choiceKey = (string) $defaultChoice;
                if (!isset($choices[$choiceKey])) {
                    throw new RuntimeException("Default choice '{$choiceKey}' not found in choices for config key '{$key}'.");
                }
                $var['driver'] = $choices[$choiceKey];
            }
        } elseif (isset($var['default']) && \is_array($var['default'])) {
            foreach ($var['default'] as $keys => $value) {
                if (\is_scalar($value) && isset($var[$keys][(string) $value])) {
                    $var['driver'][$keys] = $var[$keys][(string) $value];
                } else {
                    throw new RuntimeException("Default choice '{$keys}' not found in configuration file for config value '" . (string) $value . "' or key '{$key}'.");
                }
            }
        }

        return $var;
    }

    /**
     * Resolves a dot-notation configuration path (e.g. `'atom.core.version'`,
     * `'connectionInformation.extension'`, `'database.choices.mysql.host'`).
     */
    public static function dot(string $path, mixed $default = null, bool $useDefaultChoice = true): mixed
    {
        return DotConfig::get($path, $default, $useDefaultChoice);
    }

    /**
     * Checks whether a dot-notation configuration path exists.
     */
    public static function hasDot(string $path, bool $useDefaultChoice = true): bool
    {
        return DotConfig::has($path, $useDefaultChoice);
    }

    /**
     * Checks whether a top-level configuration file namespace is registered.
     */
    public static function hasNamespace(string $namespace): bool
    {
        return \in_array($namespace, static::$configFileName, true) || isset(static::$from[$namespace]);
    }

    /**
     * Checks whether a configuration namespace is currently loaded in memory.
     */
    public static function isLoaded(string $namespace): bool
    {
        return isset(static::$from[$namespace]);
    }

    /**
     * Atomically applies an in-memory configuration override (used by `ClusterSupervisor` for encrypted updates).
     *
     * @param array<string, mixed> $values
     */
    public static function applyRuntimeUpdate(string $namespace, array $values, ?string $persistCacheAppPath = null): void
    {
        $existing = static::$from[$namespace] ?? [];
        /** @var array<string, mixed> $merged */
        $merged = \array_replace_recursive($existing, $values);
        static::$from[$namespace] = $merged;

        if ($persistCacheAppPath !== null && self::isExportable(static::$from)) {
            self::writeConfigCache($persistCacheAppPath);
        }
    }

    /**
     * Writes resolved configuration arrays to `runtime/cache/config/` atomically.
     */
    public static function writeConfigCache(string $appPath): void
    {
        $cacheDir = \rtrim($appPath, '/\\') . self::PATH_CACHED_CONFIG;
        if (!\is_dir($cacheDir) && !\mkdir($cacheDir, 0750, true) && !\is_dir($cacheDir)) {
            return;
        }

        foreach (self::getConfigFileNames() as $fileName) {
            if (!isset(static::$from[$fileName])) {
                continue;
            }

            $exported = \var_export(static::$from[$fileName], true);
            $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$exported};\n";
            $target = $cacheDir . $fileName . '.php';
            $tmp = $target . '.tmp.' . \bin2hex(\random_bytes(4));

            if (\file_put_contents($tmp, $content, \LOCK_EX) !== false) {
                \rename($tmp, $target);
            }
        }
    }

    private static function isExportable(mixed $value): bool
    {
        if (\is_scalar($value) || $value === null) {
            return true;
        }
        if (\is_array($value)) {
            return \array_all($value, self::isExportable(...));
        }

        return false;
    }

    private static function checkConfigCacheIsAvailable(string $appPath): bool
    {
        $cacheFile = \rtrim($appPath, '/\\') . self::PATH_CACHED_CONFIG . 'database.php';
        return self::checkEnvironmentFileExists($cacheFile);
    }

    /**
     * @return list<string>
     */
    private static function getConfigFileNames(): array
    {
        return static::$configFileName;
    }
}
