<?php
declare(strict_types=1);

namespace Atom\Cache;

use Atom\Cache\SysvIpcException;
use Atom\Cache\SysvQueueManager;
use Atom\Cache\SysvSemaphoreManager;
use Atom\Cache\SysvSharedMemoryManager;

final class SysvIpcSupport
{
    public static function requireFunction(string $function, string $extension): void
    {
        if (!extension_loaded($extension)) {
            throw new SysvIpcException("Brak wymaganego rozszerzenia PHP: {$extension}");
        }

        if (!function_exists($function)) {
            throw new SysvIpcException("Brak wymaganej funkcji PHP: {$function} (sprawdź {$extension})");
        }
    }

    public static function deriveKey(int $baseKey, string $namespace, string $suffix): int
    {
        $hash = crc32($namespace . '|' . $baseKey . '|' . $suffix);
        return $hash & 0x7fffffff;
    }

    public static function isExpired(mixed $expiresAt): bool
    {
        if ($expiresAt === null || $expiresAt === '' || $expiresAt === 0) {
            return false;
        }

        return (int)$expiresAt <= time();
    }

    public static function computeExpiresAt(?int $ttl, int $defaultTtl): ?int
    {
        $effective = $ttl ?? $defaultTtl;

        if ($effective <= 0) {
            return null;
        }

        return time() + $effective;
    }
}