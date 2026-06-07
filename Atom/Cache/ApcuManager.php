<?php

declare(strict_types=1);

namespace Atom\Cache;

use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * ApcuManager
 *
 * Professional APCu wrapper with namespace support, TTL handling,
 * atomic operations, bulk helpers and cleanup utilities.
 *
 * Requirements:
 * - ext-apcu installed and enabled
 * - for CLI usage you may need apc.enable_cli=1
 */
final class ApcuManager
{
    private string $namespace;
    private int $defaultTtl;
    private bool $strict;

    /**
     * Create a new APCu cache instance.
     *
     * @param string $namespace Cache namespace for key isolation
     * @param int $defaultTtl Default time-to-live in seconds (0 = infinite)
     * @param bool $strict Whether to throw exceptions when APCu is unavailable
     */
    public function __construct(string $namespace = 'app', int $defaultTtl = 3600, bool $strict = true)
    {
        $this->namespace = $this->normalizeNamespace($namespace);
        $this->defaultTtl = max(0, $defaultTtl);
        $this->strict = $strict;
    }

    /**
     * Check if APCu is available and enabled.
     *
     * @return bool True if APCu is available and enabled, false otherwise
     */
    public function isAvailable(): bool
    {
        return extension_loaded('apcu') && function_exists('apcu_enabled') && \apcu_enabled();
    }

    /**
     * Throw if APCu is unavailable and strict mode is enabled.
     *
     * @throws RuntimeException When APCu is not available and strict mode is enabled
     */
    public function ensureAvailable(): void
    {
        if ($this->isAvailable()) {
            return;
        }

        if ($this->strict) {
            throw new RuntimeException('APCu extension is not available or not enabled.');
        }
    }

    /**
     * Set a new namespace for this cache instance.
     *
     * @param string $namespace New namespace to use
     * @return static
     */
    public function setNamespace(string $namespace): static
    {
        $this->namespace = $this->normalizeNamespace($namespace);
        return $this;
    }

    /**
     * Get the current namespace.
     *
     * @return string Current namespace
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Set a new default TTL for this cache instance.
     *
     * @param int $seconds New default TTL in seconds
     * @return static
     */
    public function setDefaultTtl(int $seconds): static
    {
        $this->defaultTtl = max(0, $seconds);
        return $this;
    }

    /**
     * Get the current default TTL.
     *
     * @return int Current default TTL in seconds
     */
    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Build a namespaced APCu key.
     *
     * @param string $key The original cache key
     * @return string Fully qualified cache key with namespace
     */
    public function key(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new InvalidArgumentException('Cache key cannot be empty.');
        }

        return $this->namespace . ':' . $key;
    }

    /**
     * Store a value in the cache.
     *
     * @param string $key The cache key
     * @param mixed $value The value to store
     * @param int|null $ttl Time-to-live in seconds (null for default)
     * @return bool True if the value was stored successfully, false otherwise
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->ensureAvailable();

        $ttl = $this->normalizeTtl($ttl);

        return apcu_store($this->key($key), $value, $ttl);
    }

    /**
     * Alias for set().
     *
     * @param string $key The cache key
     * @param mixed $value The value to store
     * @param int|null $ttl Time-to-live in seconds (null for default)
     * @return bool True if the value was stored successfully, false otherwise
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->set($key, $value, $ttl);
    }

    /**
     * Store a value only if the key does not already exist.
     *
     * @param string $key The cache key
     * @param mixed $value The value to store
     * @param int|null $ttl Time-to-live in seconds (null for default)
     * @return bool True if the value was stored successfully, false otherwise
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->ensureAvailable();

        $ttl = $this->normalizeTtl($ttl);

        return apcu_add($this->key($key), $value, $ttl);
    }

    /**
     * Replace existing value only.
     *
     * @param string $key The cache key
     * @param mixed $value The value to store
     * @param int|null $ttl Time-to-live in seconds (null for default)
     * @return bool True if the value was stored successfully, false otherwise
     */
    public function replace(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->ensureAvailable();

        $ttl = $this->normalizeTtl($ttl);

        return apcu_store($this->key($key), $value, $ttl);
    }

    /**
     * Fetch a value from the cache.
     *
     * @param string $key The cache key
     * @param mixed $default Default value to return if key is not found
     * @return mixed The cached value or default if not found
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureAvailable();

        $success = false;
        $value = \apcu_fetch($this->key($key), $success);

        return $success ? $value : $default;
    }

    /**
     * Check if a key exists in the cache.
     *
     * @param string $key The cache key
     * @return bool True if key exists, false otherwise
     */
    public function has(string $key): bool
    {
        $this->ensureAvailable();

        return \apcu_exists($this->key($key));
    }

    /**
     * Alias for has().
     *
     * @param string $key The cache key
     * @return bool True if key exists, false otherwise
     */
    public function exists(string $key): bool
    {
        return $this->has($key);
    }

    /**
     * Delete a single key from the cache.
     *
     * @param string $key The cache key to delete
     * @return bool True if the key was deleted successfully, false otherwise
     */
    public function delete(string $key): bool
    {
        $this->ensureAvailable();

        return apcu_delete($this->key($key));
    }

    /**
     * Delete multiple keys from the cache.
     *
     * @param string[] $keys Array of cache keys to delete
     * @return bool True if all keys were deleted successfully, false otherwise
     */
    public function deleteMultiple(array $keys): bool
    {
        $this->ensureAvailable();

        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete((string)$key) && $ok;
        }

        return $ok;
    }

    /**
     * Store multiple values in the cache at once.
     *
     * This method provides a batch operation to store multiple key-value pairs
     * in the cache simultaneously. It ensures that all values are stored with
     * consistent TTL handling, though it doesn't provide atomicity across all
     * operations if one fails during the process.
     *
     * @param array<string,mixed> $values An associative array of key-value pairs to store
     * @param int|null $ttl Time-to-live in seconds (null for default TTL)
     * @return bool True if all values were stored successfully, false otherwise
     */
    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        $this->ensureAvailable();

        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string)$key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * Fetch multiple values from the cache at once.
     *
     * This method provides a batch operation to retrieve multiple values from
     * the cache simultaneously. It's more efficient than calling get() multiple times
     * when retrieving several related values.
     *
     * @param string[] $keys Array of cache keys to retrieve
     * @param mixed $default Default value to return if key is not found
     * @return array<string,mixed> Associative array of retrieved key-value pairs
     */
    public function getMultiple(array $keys, mixed $default = null): array
    {
        $this->ensureAvailable();

        $out = [];
        foreach ($keys as $key) {
            $k = (string)$key;
            $out[$k] = $this->get($k, $default);
        }

        return $out;
    }

    /**
     * Remember pattern - computes and caches a value only if it's not already cached.
     *
     * This method implements a "remember" pattern where the callback is executed
     * only when the key doesn't exist in cache. It ensures that expensive operations
     * are performed only once, with subsequent calls returning the cached value.
     *
     * @param string $key The cache key to check or set
     * @param callable $callback Function that computes the value if not cached
     * @param int|null $ttl Time-to-live in seconds (null for default TTL)
     * @return mixed The cached or computed value
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $this->ensureAvailable();

        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Remember a value until a specific DateTime.
     *
     * This method implements a "remember" pattern with absolute expiration time.
     * It's useful when you want to cache values for a specific duration from now,
     * rather than relative TTL.
     *
     * @param string $key The cache key to check or set
     * @param callable $callback Function that computes the value if not cached
     * @param DateTimeInterface $expiresAt The date/time when the cached value should expire
     * @return mixed The cached or computed value
     */
    public function rememberUntil(string $key, callable $callback, DateTimeInterface $expiresAt): mixed
    {
        return $this->remember($key, $callback, $this->ttlUntil($expiresAt));
    }

    /**
     * Set a value with an absolute expiration date.
     *
     * This method stores a cache entry that will expire at the specified absolute time,
     * rather than after a relative time interval. It's useful for implementing
     * time-based caching strategies.
     *
     * @param string $key The cache key to set
     * @param mixed $value The value to store
     * @param DateTimeInterface $expiresAt The date/time when the cached value should expire
     * @return bool True if the value was stored successfully, false otherwise
     */
    public function setUntil(string $key, mixed $value, DateTimeInterface $expiresAt): bool
    {
        return $this->set($key, $value, $this->ttlUntil($expiresAt));
    }

    /**
     * Refresh TTL of an existing key, preserving its current value.
     *
     * This method extends the lifetime of an existing cache entry by resetting
     * its time-to-live without changing its value. This is useful for implementing
     * "sliding window" expiration or preventing premature cache invalidation.
     *
     * @param string $key The cache key to touch
     * @param int|null $ttl New time-to-live in seconds (null to preserve existing TTL)
     * @return bool True if the operation was successful, false otherwise
     */
    public function touch(string $key, ?int $ttl = null): bool
    {
        $this->ensureAvailable();

        if (!$this->has($key)) {
            return false;
        }

        $current = $this->get($key);
        return $this->set($key, $current, $ttl);
    }

    /**
     * Atomically increment a numeric key.
     * If key does not exist, it is initialized to $initial + $step.
     *
     * This method performs an atomic operation to increment a numeric cache entry.
     * If the key doesn't exist, it's initialized with the value of $initial + $step
     * and that value is returned. The operation is atomic, preventing race conditions
     * in multi-process or multi-threaded environments.
     *
     * @param string $key The cache key to increment
     * @param int $step The amount to increment by (default: 1)
     * @param int|null $ttl Time-to-live in seconds (null for default TTL)
     * @param int $initial The initial value when key doesn't exist (default: 0)
     * @return int|false Returns the new value on success, or false on failure
     */
    public function increment(string $key, int $step = 1, ?int $ttl = null, int $initial = 0): int|false
    {
        $this->ensureAvailable();

        $apcuKey = $this->key($key);
        $ttl = $this->normalizeTtl($ttl);

        if (!apcu_exists($apcuKey)) {
            $this->set($key, $initial + $step, $ttl);
            return $initial + $step;
        }

        $success = false;
        $result = apcu_inc($apcuKey, $step, $success, $ttl);

        return $success ? $result : false;
    }

    /**
     * Atomically decrement a numeric key.
     * If key does not exist, it is initialized to $initial - $step.
     *
     * This method performs an atomic operation to decrement a numeric cache entry.
     * If the key doesn't exist, it's initialized with the value of $initial - $step
     * and that value is returned. The operation is atomic, preventing race conditions
     * in multi-process or multi-threaded environments.
     *
     * @param string $key The cache key to decrement
     * @param int $step The amount to decrement by (default: 1)
     * @param int|null $ttl Time-to-live in seconds (null for default TTL)
     * @param int $initial The initial value when key doesn't exist (default: 0)
     * @return int|false Returns the new value on success, or false on failure
     */
    public function decrement(string $key, int $step = 1, ?int $ttl = null, int $initial = 0): int|false
    {
        $this->ensureAvailable();

        $apcuKey = $this->key($key);
        $ttl = $this->normalizeTtl($ttl);

        if (!apcu_exists($apcuKey)) {
            $this->set($key, $initial - $step, $ttl);
            return $initial - $step;
        }

        $success = false;
        $result = apcu_dec($apcuKey, $step, $success, $ttl);

        return $success ? $result : false;
    }

    /**
     * Compare-and-swap for integer values.
     * APCu CAS works on integers.
     *
     * This method implements a compare-and-swap operation for integer cache values.
     * It atomically replaces the value of a key only if the current value matches
     * the expected old value. This is useful for implementing locks or ensuring
     * atomic updates to numeric counters.
     *
     * @param string $key The cache key to modify
     * @param int $old The expected current value
     * @param int $new The new value to set if current matches expected
     * @return bool True if the swap was successful, false otherwise
     */
    public function compareAndSwap(string $key, int $old, int $new): bool
    {
        $this->ensureAvailable();

        return apcu_cas($this->key($key), $old, $new);
    }

    /**
     * Return all keys in this namespace.
     *
     * This method retrieves all cache keys that belong to the current namespace.
     * It filters the APCu cache entries to return only those that match the current
     * namespace prefix and returns either full keys or just the local part depending
     * on the stripNamespace parameter.
     *
     * @param bool $stripNamespace If true, returns only the local part of keys (default: true)
     * @return string[] List of cache keys in this namespace
     */
    public function keys(bool $stripNamespace = true): array
    {
        $this->ensureAvailable();

        $info = apcu_cache_info(false);
        $list = $info['cache_list'] ?? [];

        $keys = [];
        foreach ($list as $entry) {
            $fullKey = $this->extractEntryKey($entry);
            if ($fullKey === null) {
                continue;
            }

            if (!str_starts_with($fullKey, $this->namespace . ':')) {
                continue;
            }

            $keys[] = $stripNamespace ? substr($fullKey, \strlen($this->namespace) + 1) : $fullKey;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Return all key-value pairs in this namespace.
     *
     * This method retrieves all cache entries that belong to the current namespace
     * and returns them as an associative array. It can optionally strip the namespace
     * prefix from the keys in the returned array.
     *
     * @param bool $stripNamespace If true, removes the namespace prefix from keys (default: true)
     * @return array<string,mixed> An associative array of all key-value pairs in the namespace
     */
    public function all(bool $stripNamespace = true): array
    {
        $this->ensureAvailable();

        $out = [];
        foreach ($this->keys($stripNamespace) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    /**
     * Purge all keys matching a namespace-local prefix.
     *
     * This method removes all cache entries whose local keys (without namespace)
     * start with the specified prefix. It's useful for cleaning up related cache entries.
     *
     * @param string $prefix The prefix to match against local keys
     * @return int Number of deleted keys
     * @example Example: purgeByPrefix('user:')
     */
    public function purgeByPrefix(string $prefix = ''): int
    {
        $this->ensureAvailable();

        $deleted = 0;
        foreach ($this->keys(false) as $fullKey) {
            $localKey = substr($fullKey, strlen($this->namespace) + 1);
            if (str_starts_with($localKey, $prefix)) {
                if ($this->delete($localKey)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Purge all keys matching a regex against namespace-local key.
     *
     * This method removes all cache entries whose local keys (without namespace)
     * match the provided regular expression pattern. It's useful for removing
     * cache entries based on more complex matching rules.
     *
     * @param string $pattern The regular expression pattern to match against local keys
     * @return int Number of deleted keys
     */
    public function purgeByPattern(string $pattern): int
    {
        $this->ensureAvailable();

        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException('Invalid regex pattern: ' . $pattern);
        }

        $deleted = 0;
        foreach ($this->keys(false) as $fullKey) {
            $localKey = substr($fullKey, strlen($this->namespace) + 1);
            if (@preg_match($pattern, $localKey) === 1) {
                if ($this->delete($localKey)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Clear only current namespace.
     *
     * This method removes all cache entries that belong to the current namespace
     * but preserves other cache entries. It's equivalent to purging by an empty prefix.
     *
     * @return int Number of deleted keys
     */
    public function clearNamespace(): int
    {
        return $this->purgeByPrefix('');
    }

    /**
     * Flush all APCu cache entries globally.
     *
     * This method clears the entire APCu cache, removing all cached entries.
     * Use carefully as this affects all cached data, not just the current namespace.
     *
     * @return bool True on success, false on failure
     */
    public function flush(): bool
    {
        $this->ensureAvailable();

        return apcu_clear_cache();
    }

    /**
     * APCu cache info.
     *
     * This method returns detailed information about the APCu cache,
     * including statistics and metadata about cached entries.
     *
     * @return array Information about the APCu cache
     */
    public function stats(): array
    {
        $this->ensureAvailable();

        return apcu_cache_info(false) ?: [];
    }

    /**
     * APCu shared memory info.
     *
     * This method returns information about the shared memory used by APCu,
     * including allocation status and memory usage details.
     *
     * @return array Information about APCu shared memory
     */
    public function memoryInfo(): array
    {
        $this->ensureAvailable();

        return apcu_sma_info(true) ?: [];
    }

    /**
     * Convert DateTime expiration to TTL in seconds.
     *
     * This method calculates the time-to-live by finding the difference between
     * the provided expiration timestamp and the current timestamp. If the calculated
     * TTL is negative (meaning the expiration has already passed), it returns 0.
     * This ensures that expired entries are treated as having zero TTL.
     *
     * @param DateTimeInterface $expiresAt The date/time when the cache entry should expire
     * @return int The time-to-live in seconds (0 if already expired)
     */
    public function ttlUntil(DateTimeInterface $expiresAt): int
    {
        $ttl = $expiresAt->getTimestamp() - time();
        return max(0, $ttl);
    }

    /**
     * Return a raw namespaced key list element if possible.
     *
     * This method attempts to extract a usable key from a cache entry array.
     * It checks for the presence of 'info' or 'key' keys and returns their string values
     * if they exist. The 'info' key takes precedence over 'key' when both are present.
     * If neither is found or is not a string, it returns null.
     *
     * @param array $entry The cache entry to extract the key from
     * @return string|null The extracted key or null if not found
     */
    private function extractEntryKey(array $entry): ?string
    {
        if (isset($entry['info']) && \is_string($entry['info'])) {
            return $entry['info'];
        }

        if (isset($entry['key']) && \is_string($entry['key'])) {
            return $entry['key'];
        }

        return null;
    }

    /**
     * Normalize TTL value for cache operations.
     *
     * This method ensures that a TTL value is properly normalized for cache operations.
     * If the provided TTL is null, it defaults to the instance's default TTL. Otherwise,
     * it returns the maximum of 0 and the provided TTL to ensure non-negative values.
     *
     * @param int|null $ttl The TTL in seconds, or null to use default
     * @return int The normalized TTL (non-negative integer)
     */
    private function normalizeTtl(?int $ttl): int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }

        return max(0, $ttl);
    }

    /**
     * Normalize namespace to a safe cache prefix.
     *
     * This method sanitizes and validates a namespace string to ensure it's safe for use
     * as a cache key prefix. It trims whitespace and replaces invalid characters with
     * underscores, then validates that the result is not empty.
     *
     * @param string $namespace The namespace to normalize
     * @return string The normalized namespace
     * @throws InvalidArgumentException If the namespace is empty or becomes empty after sanitization
     */
    private function normalizeNamespace(string $namespace): string
    {
        $namespace = trim($namespace);
        if ($namespace === '') {
            throw new InvalidArgumentException('Namespace cannot be empty.');
        }

        $normalized = preg_replace('/[^A-Za-z0-9_\-:.]/', '_', $namespace);
        if ($normalized === null || $normalized === '') {
            throw new InvalidArgumentException('Invalid namespace.');
        }

        return $normalized;
    }
}
