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

    public function __construct(string $namespace = 'app', int $defaultTtl = 3600, bool $strict = true)
    {
        $this->namespace = $this->normalizeNamespace($namespace);
        $this->defaultTtl = max(0, $defaultTtl);
        $this->strict = $strict;
    }

    /**
     * Check if APCu is available and enabled.
     */
    public function isAvailable(): bool
    {
        return extension_loaded('apcu') && function_exists('apcu_enabled') && \apcu_enabled();
    }

    /**
     * Throw if APCu is unavailable and strict mode is enabled.
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

    public function setNamespace(string $namespace): static
    {
        $this->namespace = $this->normalizeNamespace($namespace);
        return $this;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function setDefaultTtl(int $seconds): static
    {
        $this->defaultTtl = max(0, $seconds);
        return $this;
    }

    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Build a namespaced APCu key.
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
     * Store a value.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->ensureAvailable();

        $ttl = $this->normalizeTtl($ttl);

        return apcu_store($this->key($key), $value, $ttl);
    }

    /**
     * Alias for set().
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->set($key, $value, $ttl);
    }

    /**
     * Store a value only if the key does not already exist.
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->ensureAvailable();

        $ttl = $this->normalizeTtl($ttl);

        return apcu_add($this->key($key), $value, $ttl);
    }

    /**
     * Replace existing value only.
     */
    public function replace(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->ensureAvailable();

        $ttl = $this->normalizeTtl($ttl);

        return apcu_store($this->key($key), $value, $ttl);
    }

    /**
     * Fetch a value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureAvailable();

        $success = false;
        $value = apcu_fetch($this->key($key), $success);

        return $success ? $value : $default;
    }

    /**
     * Check if a key exists.
     */
    public function has(string $key): bool
    {
        $this->ensureAvailable();

        return apcu_exists($this->key($key));
    }

    /**
     * Alias for has().
     */
    public function exists(string $key): bool
    {
        return $this->has($key);
    }

    /**
     * Delete a single key.
     */
    public function delete(string $key): bool
    {
        $this->ensureAvailable();

        return apcu_delete($this->key($key));
    }

    /**
     * Delete multiple keys.
     *
     * @param string[] $keys
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
     * Store multiple values.
     *
     * @param array<string,mixed> $values
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
     * Fetch multiple values.
     *
     * @param string[] $keys
     * @return array<string,mixed>
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
     * Remember pattern.
     * Computes the value only if the key is missing.
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
     * Remember until a specific DateTime.
     */
    public function rememberUntil(string $key, callable $callback, DateTimeInterface $expiresAt): mixed
    {
        return $this->remember($key, $callback, $this->ttlUntil($expiresAt));
    }

    /**
     * Set a value with an absolute expiration date.
     */
    public function setUntil(string $key, mixed $value, DateTimeInterface $expiresAt): bool
    {
        return $this->set($key, $value, $this->ttlUntil($expiresAt));
    }

    /**
     * Refresh TTL of an existing key, preserving its current value.
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
     */
    public function compareAndSwap(string $key, int $old, int $new): bool
    {
        $this->ensureAvailable();

        return apcu_cas($this->key($key), $old, $new);
    }

    /**
     * Return all keys in this namespace.
     *
     * @return string[]
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

            $keys[] = $stripNamespace ? substr($fullKey, strlen($this->namespace) + 1) : $fullKey;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Return all key-value pairs in this namespace.
     *
     * @return array<string,mixed>
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
     * Example: purgeByPrefix('user:')
     *
     * @return int number of deleted keys
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
     * @return int number of deleted keys
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
     */
    public function clearNamespace(): int
    {
        return $this->purgeByPrefix('');
    }

    /**
     * Flush all APCu cache entries globally.
     * Use carefully.
     */
    public function flush(): bool
    {
        $this->ensureAvailable();

        return apcu_clear_cache();
    }

    /**
     * APCu cache info.
     */
    public function stats(): array
    {
        $this->ensureAvailable();

        return apcu_cache_info(false) ?: [];
    }

    /**
     * APCu shared memory info.
     */
    public function memoryInfo(): array
    {
        $this->ensureAvailable();

        return apcu_sma_info(true) ?: [];
    }

    /**
     * Convert DateTime expiration to TTL in seconds.
     */
    public function ttlUntil(DateTimeInterface $expiresAt): int
    {
        $ttl = $expiresAt->getTimestamp() - time();
        return max(0, $ttl);
    }

    /**
     * Return a raw namespaced key list element if possible.
     */
    private function extractEntryKey(array $entry): ?string
    {
        if (isset($entry['info']) && is_string($entry['info'])) {
            return $entry['info'];
        }

        if (isset($entry['key']) && is_string($entry['key'])) {
            return $entry['key'];
        }

        return null;
    }

    /**
     * Normalize TTL.
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
