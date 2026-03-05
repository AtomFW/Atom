<?php

use Atom\Config\EnvironmentVariables;

/**
 * Pobiera wartość z $_ENV dla podanego klucza lub zwraca wartość domyślną.
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
