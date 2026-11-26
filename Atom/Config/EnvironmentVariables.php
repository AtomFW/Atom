<?php

declare(strict_types=1);

namespace Atom\Config;

use RuntimeException;

/**
 * Environment variable accessor, type normalizer, and required-key validator.
 */
class EnvironmentVariables extends LoadEnvironmentVariables
{
    /**
     * @var array<string, list<string>>
     */
    protected static array $environmentEnsuredKeys = [
        'database' => ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
    ];

    public static function getEnvironmentVariable(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? null;
        if ($value === null) {
            return $default;
        }

        return static::normalizeEnvValue($value);
    }

    protected static function normalizeEnvValue(mixed $value): mixed
    {
        if (!\is_string($value)) {
            return $value;
        }

        return match (\strtolower(\trim($value))) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => $value,
        };
    }

    /**
     * @throws RuntimeException If any required environment variable is missing.
     */
    protected static function ensureRequiredEnvironmentVariables(): void
    {
        foreach (static::$environmentEnsuredKeys as $envKey => $envKeys) {
            foreach ($envKeys as $envKeyVariable) {
                if (!isset($_ENV[$envKeyVariable])) {
                    throw new RuntimeException(
                        "Required environment variable '{$envKeyVariable}' is not set for config '{$envKey}'."
                    );
                }
            }
        }
    }
}
