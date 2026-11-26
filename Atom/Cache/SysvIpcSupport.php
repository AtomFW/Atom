<?php

declare(strict_types=1);

namespace Atom\Cache;

use Time\Duration;

/**
 * System V IPC (Inter-Process Communication) support utilities.
 *
 * @internal
 */
final readonly class SysvIpcSupport
{
    public static function requireFunction(string $function, string $extension): void
    {
        if (!\extension_loaded($extension)) {
            throw new SysvIpcException("No PHP extension required: {$extension}");
        }

        if (!\function_exists($function)) {
            throw new SysvIpcException("Missing required PHP function: {$function} (check {$extension})");
        }
    }

    public static function deriveKey(int $baseKey, string $namespace, string $suffix): int
    {
        $hash = \crc32($namespace . '|' . $baseKey . '|' . $suffix);
        return $hash & 0x7fffffff;
    }

    public static function isExpired(mixed $expiresAt): bool
    {
        if ($expiresAt === null || $expiresAt === '' || $expiresAt === 0) {
            return false;
        }

        return (int) $expiresAt <= \time();
    }

    public static function computeExpiresAt(int|Duration|null $ttl, int|Duration $defaultTtl = 0): ?int
    {
        $effective = $ttl ?? $defaultTtl;
        $seconds = $effective instanceof Duration
            ? ($effective->negative ? 0 : $effective->seconds)
            : $effective;

        if ($seconds <= 0) {
            return null;
        }

        return \time() + $seconds;
    }

    /**
     * Executes a native SysV IPC function while capturing warnings cleanly without @.
     */
    public static function callQuietly(callable $operation): mixed
    {
        \set_error_handler(static fn(): bool => true);
        try {
            return $operation();
        } finally {
            \restore_error_handler();
        }
    }
}
