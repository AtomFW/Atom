<?php

declare(strict_types=1);

namespace Atom\Cache;

use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use Time\Duration;

/**
 * APCu wrapper with namespace isolation, \Time\Duration TTL support,
 * atomic counters, CAS, and safe pattern purging without @ suppression.
 */
final class ApcuManager
{
    private string $namespace;
    private int $defaultTtl;
    private bool $strict;

    public function __construct(
        string $namespace = 'app',
        int|Duration $defaultTtl = 3600,
        bool $strict = true,
    ) {
        $this->namespace = $this->normalizeNamespace($namespace);
        $this->defaultTtl = $this->durationToSeconds($defaultTtl);
        $this->strict = $strict;
    }

    public function isAvailable(): bool
    {
        return \extension_loaded('apcu') && \function_exists('apcu_enabled') && \apcu_enabled();
    }

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

    public function setDefaultTtl(int|Duration $seconds): static
    {
        $this->defaultTtl = $this->durationToSeconds($seconds);
        return $this;
    }

    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    public function key(string $key): string
    {
        $key = \trim($key);
        if ($key === '') {
            throw new InvalidArgumentException('Cache key cannot be empty.');
        }

        return $this->namespace . ':' . $key;
    }

    public function set(string $key, mixed $value, int|Duration|null $ttl = null): bool
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return false;
        }

        return \apcu_store($this->key($key), $value, $this->normalizeTtl($ttl));
    }

    public function put(string $key, mixed $value, int|Duration|null $ttl = null): bool
    {
        return $this->set($key, $value, $ttl);
    }

    public function add(string $key, mixed $value, int|Duration|null $ttl = null): bool
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return false;
        }

        return \apcu_add($this->key($key), $value, $this->normalizeTtl($ttl));
    }

    public function replace(string $key, mixed $value, int|Duration|null $ttl = null): bool
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return false;
        }

        $apcuKey = $this->key($key);
        if (!\apcu_exists($apcuKey)) {
            return false;
        }

        return \apcu_store($apcuKey, $value, $this->normalizeTtl($ttl));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return $default;
        }

        $success = false;
        $value = \apcu_fetch($this->key($key), $success);

        return $success ? $value : $default;
    }

    public function has(string $key): bool
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return false;
        }

        return \apcu_exists($this->key($key));
    }

    public function exists(string $key): bool
    {
        return $this->has($key);
    }

    public function delete(string $key): bool
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return false;
        }

        return (bool) \apcu_delete($this->key($key));
    }

    /**
     * @param array<int|string, string|int> $keys
     */
    public function deleteMultiple(array $keys): bool
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return false;
        }

        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete((string) $key) && $ok;
        }

        return $ok;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setMultiple(array $values, int|Duration|null $ttl = null): bool
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return false;
        }

        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param array<int|string, string|int> $keys
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys, mixed $default = null): array
    {
        $this->ensureAvailable();

        $out = [];
        foreach ($keys as $key) {
            $k = (string) $key;
            $out[$k] = $this->get($k, $default);
        }

        return $out;
    }

    public function remember(string $key, callable $callback, int|Duration|null $ttl = null): mixed
    {
        $this->ensureAvailable();

        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function rememberUntil(string $key, callable $callback, DateTimeInterface $expiresAt): mixed
    {
        return $this->remember($key, $callback, $this->ttlUntil($expiresAt));
    }

    public function setUntil(string $key, mixed $value, DateTimeInterface $expiresAt): bool
    {
        return $this->set($key, $value, $this->ttlUntil($expiresAt));
    }

    public function touch(string $key, int|Duration|null $ttl = null): bool
    {
        $this->ensureAvailable();
        if (!$this->has($key)) {
            return false;
        }

        return $this->set($key, $this->get($key), $ttl);
    }

    public function increment(string $key, int $step = 1, int|Duration|null $ttl = null, int $initial = 0): int|false
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return false;
        }

        $apcuKey = $this->key($key);
        $normalizedTtl = $this->normalizeTtl($ttl);

        if (!\apcu_exists($apcuKey)) {
            $next = $initial + $step;
            $this->set($key, $next, $normalizedTtl);
            return $next;
        }

        $success = false;
        $result = \apcu_inc($apcuKey, $step, $success, $normalizedTtl);

        return $success ? $result : false;
    }

    public function decrement(string $key, int $step = 1, int|Duration|null $ttl = null, int $initial = 0): int|false
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return false;
        }

        $apcuKey = $this->key($key);
        $normalizedTtl = $this->normalizeTtl($ttl);

        if (!\apcu_exists($apcuKey)) {
            $next = $initial - $step;
            $this->set($key, $next, $normalizedTtl);
            return $next;
        }

        $success = false;
        $result = \apcu_dec($apcuKey, $step, $success, $normalizedTtl);

        return $success ? $result : false;
    }

    public function compareAndSwap(string $key, int $old, int $new): bool
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return false;
        }

        return \apcu_cas($this->key($key), $old, $new);
    }

    /**
     * @return list<string>
     */
    public function keys(bool $stripNamespace = true): array
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return [];
        }

        $info = \apcu_cache_info(false);
        $list = \is_array($info) ? ($info['cache_list'] ?? []) : [];
        $prefix = $this->namespace . ':';
        $prefixLen = \strlen($prefix);

        $keys = [];
        foreach ($list as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $fullKey = $this->extractEntryKey($entry);
            if ($fullKey === null || !\str_starts_with($fullKey, $prefix)) {
                continue;
            }

            $keys[] = $stripNamespace ? \substr($fullKey, $prefixLen) : $fullKey;
        }

        return \array_values(\array_unique($keys));
    }

    /**
     * @return array<string, mixed>
     */
    public function all(bool $stripNamespace = true): array
    {
        $this->ensureAvailable();

        $out = [];
        $prefixLen = \strlen($this->namespace) + 1;
        foreach ($this->keys(false) as $fullKey) {
            $localKey = \substr($fullKey, $prefixLen);
            $out[$stripNamespace ? $localKey : $fullKey] = $this->get($localKey);
        }

        return $out;
    }

    public function purgeByPrefix(string $prefix = ''): int
    {
        $this->ensureAvailable();

        $deleted = 0;
        $prefixLen = \strlen($this->namespace) + 1;
        foreach ($this->keys(false) as $fullKey) {
            $localKey = \substr($fullKey, $prefixLen);
            if (\str_starts_with($localKey, $prefix) && $this->delete($localKey)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function purgeByPattern(string $pattern): int
    {
        $this->ensureAvailable();

        if (!$this->isValidRegex($pattern)) {
            throw new InvalidArgumentException('Invalid regex pattern: ' . $pattern);
        }

        $deleted = 0;
        $prefixLen = \strlen($this->namespace) + 1;
        foreach ($this->keys(false) as $fullKey) {
            $localKey = \substr($fullKey, $prefixLen);
            if (\preg_match($pattern, $localKey) === 1 && $this->delete($localKey)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function clearNamespace(): int
    {
        return $this->purgeByPrefix('');
    }

    public function flush(): bool
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return false;
        }

        return \apcu_clear_cache();
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return [];
        }

        $info = \apcu_cache_info(false);
        return \is_array($info) ? $info : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function memoryInfo(): array
    {
        $this->ensureAvailable();
        if (!$this->isAvailable()) {
            return [];
        }

        $sma = \apcu_sma_info(true);
        return \is_array($sma) ? $sma : [];
    }

    public function ttlUntil(DateTimeInterface $expiresAt): int
    {
        return \clamp($expiresAt->getTimestamp() - \time(), 0, \PHP_INT_MAX);
    }

    /**
     * @param array<string, mixed> $entry
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

    private function normalizeTtl(int|Duration|null $ttl): int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }

        return $this->durationToSeconds($ttl);
    }

    private function durationToSeconds(int|Duration $ttl): int
    {
        if ($ttl instanceof Duration) {
            return $ttl->negative ? 0 : $ttl->seconds;
        }

        return \clamp($ttl, 0, \PHP_INT_MAX);
    }

    private function isValidRegex(string $pattern): bool
    {
        \set_error_handler(static fn(): bool => true);
        try {
            return \preg_match($pattern, '') !== false;
        } finally {
            \restore_error_handler();
        }
    }

    private function normalizeNamespace(string $namespace): string
    {
        $namespace = \trim($namespace);
        if ($namespace === '') {
            throw new InvalidArgumentException('Namespace cannot be empty.');
        }

        $normalized = \preg_replace('/[^A-Za-z0-9_\-:.]/', '_', $namespace);
        if ($normalized === null) {
            throw new InvalidArgumentException('Invalid namespace.');
        }

        return $normalized;
    }
}
