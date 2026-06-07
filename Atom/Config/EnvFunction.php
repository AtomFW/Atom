<?php

declare(strict_types=1);

use Atom\Config\EnvironmentVariables;

/**
 * Gets the value from $_ENV for the given key or returns the default value.
 *
 * @param string $key
 * @param mixed $default
 * @return mixed
 */

if (!function_exists('env')) {
    function env(string $key, $default = null): mixed
    {
        return EnvironmentVariables::getEnvironmentVariable($key, $default);
    }
}
