<?php

declare(strict_types=1);

namespace Atom\Cache;

use Atom\Cache\SysvIpcException;
use Atom\Cache\SysvQueueManager;
use Atom\Cache\SysvSemaphoreManager;
use Atom\Cache\SysvSharedMemoryManager;

/**
 * System V IPC (Inter-Process Communication) support utilities
 *
 * This class provides utility functions for working with System V IPC facilities
 * in PHP. It includes functions for:
 * - Validating required PHP extensions and functions
 * - Computing unique keys for IPC resources
 * - Managing message expiration
 * - Handling time-based operations for IPC
 *
 * These utilities are used internally by other classes like SysvQueueManager
 * to provide a consistent interface for System V IPC operations.
 *
 * @internal This class is part of the internal SysvIpc implementation
 */
final class SysvIpcSupport
{
    /**
     * Validates that a required PHP extension is loaded and a specific function exists
     *
     * This method checks if a required PHP extension is loaded and if a specific
     * function from that extension is available. It's used to ensure that the
     * System V IPC functions are properly available before attempting to use them.
     *
     * @param string $function The name of the function to check for
     * @param string $extension The name of the required extension
     * @return void
     * @throws SysvIpcException If the extension is not loaded or function doesn't exist
     */
    public static function requireFunction(string $function, string $extension): void
    {
        if (!extension_loaded($extension)) {
            throw new SysvIpcException("No PHP extension required: {$extension}");
        }

        if (!function_exists($function)) {
            throw new SysvIpcException("Missing required PHP function: {$function} (check {$extension})");
        }
    }

    /**
     * Derives a unique key for System V IPC resources
     *
     * This method generates a consistent integer key based on:
     * - A base key that identifies the application or component
     * - A namespace string to prevent key collisions
     * - A suffix to distinguish different types of resources
     *
     * The resulting key is guaranteed to be positive and suitable for use with
     * System V IPC functions.
     *
     * @param int $baseKey Base identifier for the resource
     * @param string $namespace Namespace to prevent collisions
     * @param string $suffix Suffix to distinguish different types of resources
     * @return int Unique key for the IPC resource
     */
    public static function deriveKey(int $baseKey, string $namespace, string $suffix): int
    {
        $hash = crc32($namespace . '|' . $baseKey . '|' . $suffix);
        return $hash & 0x7fffffff;
    }

    /**
     * Determines if a message has expired based on its expiration timestamp
     *
     * This method checks if a message is considered expired by comparing the
     * provided expiration time with the current time. It handles several edge cases:
     * - Null, empty, or zero values are not considered expired
     * - Messages with future expiration times are treated as valid
     * - Past expiration times are considered expired
     *
     * @param mixed $expiresAt The timestamp when the message should expire
     * @return bool True if the message has expired, false otherwise
     */
    public static function isExpired(mixed $expiresAt): bool
    {
        if ($expiresAt === null || $expiresAt === '' || $expiresAt === 0) {
            return false;
        }

        return (int)$expiresAt <= time();
    }

    /**
     * Computes an expiration timestamp based on a TTL value
     *
     * This method calculates when a message should expire based on:
     * - A provided TTL (time-to-live) value
     * - A default TTL if none is specified
     * - The current time
     *
     * If the effective TTL is zero or negative, it returns null indicating no expiration.
     * Otherwise, it returns a timestamp representing when the message should expire.
     *
     * @param int|null $ttl Time-to-live in seconds, or null to use default
     * @param int $defaultTtl Default TTL to use if $ttl is null
     * @return int|null Expiration timestamp or null if no expiration
     */
    public static function computeExpiresAt(?int $ttl, int $defaultTtl): ?int
    {
        $effective = $ttl ?? $defaultTtl;

        if ($effective <= 0) {
            return null;
        }

        return time() + $effective;
    }
}
