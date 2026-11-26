<?php

declare(strict_types=1);

namespace Atom\Config;

use Dotenv\Dotenv;

/**
 * Loads environment variables from `.env` files safely (preserving Windows UNC paths).
 */
class LoadEnvironmentVariables
{
    protected static function checkEnvironmentFileExists(string $path): bool
    {
        if (!\is_file($path)) {
            return false;
        }

        if (\is_readable($path)) {
            return true;
        }

        \set_error_handler(static fn(): bool => true);
        try {
            $handle = \fopen($path, 'rb');
            if (\is_resource($handle)) {
                \fclose($handle);
                return true;
            }
            return false;
        } finally {
            \restore_error_handler();
        }
    }

    protected static function load(string $path): void
    {
        if (\str_ends_with($path, '.env') && \is_file($path)) {
            $dotenv = Dotenv::createImmutable(\dirname($path), \basename($path));
        } else {
            $dotenv = Dotenv::createImmutable($path, '.env');
        }

        $dotenv->safeLoad();
    }

    protected static function setEnvironmentPath(?string $path, string $appPath): string
    {
        if ($path === null || $path === '') {
            $resolved = \realpath($appPath);
            $base = $resolved !== false ? $resolved : \rtrim($appPath, '/\\');
            return $base . \DIRECTORY_SEPARATOR;
        }

        $trimmed = \rtrim($path, '/\\');
        if (!\str_ends_with($trimmed, '.env')) {
            $trimmed .= '.env';
        }

        return $trimmed;
    }
}
