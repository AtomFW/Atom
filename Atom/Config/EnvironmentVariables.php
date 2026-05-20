<?php

declare(strict_types=1);

namespace Atom\Config;

use Atom\Config\LoadEnvironmentVariables;

/**
 * Class EnvironmentVariables
 * @package Atom\Config
 *
 * This class is used to ensure that certain environment variables are set.
 *
 * It extends the LoadEnvironmentVariables class and adds additional functionality to ensure
 * that certain environment variables are set.
 */

class EnvironmentVariables extends LoadEnvironmentVariables
{

    /**
     * An array of environment keys that are ensured to be set.
     * The keys in this array are the names of the configs that are ensured to be set.
     * The values of this array are arrays of environment variables that are ensured to be set
     * for each config.
     *
     * Example:
     * [
     *     'app' => ['name', 'env', 'debug', 'url', 'timezone', 'locale', 'fallback_locale', 'key', 'cipher'],
     *     'database' => ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
     * ]
     *
     * @var array
     */
    protected static array $environmentEnsuredKeys = [
        // 'app' => ['name', 'env', 'debug', 'url', 'timezone', 'locale', 'fallback_locale', 'key', 'cipher'],
        'database' => ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
        // 'cache' => ['default', 'stores', 'prefix'],
        // 'session' => ['driver', 'lifetime', 'expire_on_close', 'encrypt', 'files', 'connection'],
        // 'mail' => ['driver', 'host', 'port', 'username', 'password', 'encryption', 'from', 'name'],
        // 'auth' => ['guards', 'providers'],
        // 'filesystem' => ['default', 'disks'],
        // 'modul' => ['default', 'modules'],
        // 'logger' => ['default', 'choices'],
    ];

    /**
     * Gets an environment variable.
     *
     * Gets an environment variable from the global $_ENV array.
     * If the environment variable is not set, the default value will be returned.
     *
     * @param string $key The key of the environment variable.
     * @param mixed $default The default value to return if the environment variable is not set.
     * @return mixed The value of the environment variable or the default value.
     */
    public static function getEnvironmentVariable(string $key, $default = null): mixed
    {
        // Get the value of the environment variable
        $value = $_ENV[$key] ?? null;

        // If the environment variable is not set, return the default value
        if ($value === null) {
            return $default;
        }

        // Normalize the environment variable value
        return static::normalizeEnvValue($value);
    }

    /**
     * Normalize environment variable values.
     *
     * Normalize environment variable values from their string representation to their actual values.
     * This includes values like "true", "(true)", "false", "(false)", "empty", "(empty)", "null" and "(null)".
     *
     * @param mixed $value The value to normalize.
     * @return mixed The normalized value.
     */
    protected static function normalizeEnvValue($value): mixed
    {
        // Normalize environment variable values from their string representation to their actual values
        switch (\strtolower($value)) {
            // Normalize "true" and "(true)" to boolean true
            case 'true':
            case '(true)':
                return true;
            // Normalize "false" and "(false)" to boolean false
            case 'false':
            case '(false)':
                return false;
            // Normalize "empty" and "(empty)" to an empty string
            case 'empty':
            case '(empty)':
                return '';
            // Normalize "null" and "(null)" to null
            case 'null':
            case '(null)':
                return null;
        }

        // Return the value as it is if it does not match any of the above cases
        return $value;
    }

    /**
     * Ensure required environment variables are set.
     *
     * Iterate over the list of required environment variables and throw an exception if any of them are not set.
     *
     * @throws \RuntimeException If any of the required environment variables are not set.
     */
    protected static function ensureRequiredEnvironmentVariables(): void
    {
        // Iterate over the list of required environment variables
        foreach (static::$environmentEnsuredKeys as $envKey => $envKeys) {
            // Iterate over the list of required environment variables for the current config
            foreach ($envKeys as $envKeyVariable) {
                $key = $_ENV[$envKeyVariable] ?? null;
            
                // Check if the required environment variable is not set
                if ($key === null) {
                    // Throw an exception with a meaningful error message
                    throw new \RuntimeException("Required environment variable '{$envKeyVariable}' is not set for config '{$envKey}'.");
                }
            }
        }
    }
}
