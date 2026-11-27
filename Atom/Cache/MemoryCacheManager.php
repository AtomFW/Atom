<?php

declare(strict_types=1);

namespace Atom\Cache;

use Countable;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Time\Duration;

use function abs;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_pop;
use function array_shift;
use function array_unshift;
use function array_values;
use function clamp;
use function count;
use function crc32;
use function extension_loaded;
use function function_exists;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function microtime;
use function preg_match;
use function preg_replace;
use function restore_error_handler;
use function set_error_handler;
use function str_starts_with;
use function strlen;
use function substr;
use function time;
use function trim;

/**
 * Adaptive, cross-platform PHP RAM cache manager uniting `ApcuManager`, `SysvIpcManager`
 * (`SysvSharedMemoryManager`, `SysvSemaphoreManager`, `SysvQueueManager`), and a bounded
 * in-process memory store with automatic TTL self-purging and LRU eviction.
 *
 * Designed for PHP 8.6+, ZTS, Swoole/FrankenPHP workers, and OS-agnostic portability.
 */
final class MemoryCacheManager implements Countable
{
    private const string INTERNAL_META_PREFIX = '__atom_meta__:';
    private const string INTERNAL_TAG_PREFIX = '__atom_tag__:';
    private const string INTERNAL_QUEUE_PREFIX = '__atom_queue__:';
    private const string INTERNAL_LOCK_PREFIX = '__atom_lock__:';

    private RamCacheDriver $activeDriver;
    private string $namespace;
    private int $defaultTtlSeconds;
    private int $maxItems;
    private int $autoPruneInterval;
    private int $opsSincePrune = 0;
    private int $hits = 0;
    private int $misses = 0;
    private bool $inMemoryLockHeld = false;

    private ?ApcuManager $apcuManager = null;
    private ?SysvIpcManager $ipcManager = null;
    private ?SysvSharedMemoryManager $shmManager = null;
    private ?SysvSemaphoreManager $semaphoreManager = null;
    private ?SysvQueueManager $queueManager = null;

    /**
     * Bounded instance-scoped RAM storage used when running on the `memory` driver
     * (and for auxiliary sub-second expiration tracking).
     *
     * @var array<string, array{
     *     value: mixed,
     *     created_at: float,
     *     updated_at: float,
     *     expires_at: ?float
     * }>
     */
    private array $memoryStore = [];

    public function __construct(
        string $namespace = 'atom_ram',
        int|Duration $defaultTtl = 3600,
        RamCacheDriver|string $driver = RamCacheDriver::Auto,
        int $maxItems = 4096,
        int $shmSize = 1_048_576,
        int $ipcBaseKey = 0,
        int $ipcPermissions = 0666,
        int $autoPruneInterval = 64,
        bool $strict = false,
    ) {
        $this->namespace = $this->normalizeNamespace($namespace);
        $this->defaultTtlSeconds = $this->durationToSeconds($defaultTtl);
        $this->maxItems = clamp($maxItems, 16, 1_000_000);
        $this->autoPruneInterval = clamp($autoPruneInterval, 1, 10_000);

        $requestedDriver = $driver instanceof RamCacheDriver
            ? $driver
            : (RamCacheDriver::tryFrom( trim($driver) ) ?? throw new InvalidArgumentException("Unsupported RAM cache driver: '{$driver}'."));

        $resolvedBaseKey = $ipcBaseKey > 0
            ? $ipcBaseKey
            : (crc32('atom-ram|' . $this->namespace) & 0x7fffffff);

        $this->activeDriver = $this->initializeBackend(
            requested: $requestedDriver,
            baseKey: $resolvedBaseKey,
            permissions: $ipcPermissions,
            shmSize: clamp($shmSize, 65_536, 536_870_912),
            strict: $strict,
        );
    }

    /**
     * Creates a new `MemoryCacheManager` from an associative configuration array (e.g. `config/cache.php`).
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $driverConfig = isset($config['driver']) && is_array($config['driver'])
            ? $config['driver']
            : $config;

        return new self(
            namespace: (string) ($driverConfig['namespace'] ?? $config['prefix'] ?? 'atom_ram'),
            defaultTtl: isset($driverConfig['defaultTtl']) && ($driverConfig['defaultTtl'] instanceof Duration || is_int($driverConfig['defaultTtl']))
                ? $driverConfig['defaultTtl']
                : (int) ($config['defaultTtl'] ?? 3600),
            driver: (string) ($driverConfig['backend'] ?? 'auto'),
            maxItems: (int) ($driverConfig['maxItems'] ?? 4096),
            shmSize: (int) ($driverConfig['shmSize'] ?? 1_048_576),
            ipcBaseKey: (int) ($driverConfig['ipcBaseKey'] ?? 0),
            ipcPermissions: (int) ($driverConfig['ipcPermissions'] ?? 0666),
            autoPruneInterval: (int) ($driverConfig['autoPruneInterval'] ?? 64),
            strict: (bool) ($driverConfig['strict'] ?? false),
        );
    }

    /**
     * Lists all RAM cache drivers supported in the current PHP runtime.
     *
     * @return list<string>
     */
    public static function availableDrivers(): array
    {
        $drivers = [];

        if (self::isApcuAvailable()) {
            $drivers[] = RamCacheDriver::Apcu->value;
        }

        if (self::isSysvShmAvailable()) {
            $drivers[] = RamCacheDriver::Sysv->value;
        }

        $drivers[] = RamCacheDriver::Memory->value;

        return $drivers;
    }

    public static function isApcuAvailable(): bool
    {
        return extension_loaded('apcu') && function_exists('apcu_enabled') && \apcu_enabled();
    }

    public static function isSysvShmAvailable(): bool
    {
        return extension_loaded('sysvshm')
            && extension_loaded('sysvsem')
            && function_exists('shm_attach')
            && function_exists('sem_get');
    }

    public static function isSysvQueueAvailable(): bool
    {
        return extension_loaded('sysvmsg') && function_exists('msg_get_queue');
    }

    public function getDriver(): RamCacheDriver
    {
        return $this->activeDriver;
    }

    public function getDriverName(): string
    {
        return $this->activeDriver->value;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getDefaultTtl(): int
    {
        return $this->defaultTtlSeconds;
    }

    public function setDefaultTtl(int|Duration $ttl): static
    {
        $this->defaultTtlSeconds = $this->durationToSeconds($ttl);
        $this->apcuManager?->setDefaultTtl($this->defaultTtlSeconds);

        return $this;
    }

    /**
     * Returns an isolated manager scoped to a child namespace.
     */
    public function scoped(string $subNamespace): self
    {
        $cleanSub = $this->normalizeNamespace($subNamespace);

        return new self(
            namespace: $this->namespace . ':' . $cleanSub,
            defaultTtl: $this->defaultTtlSeconds,
            driver: $this->activeDriver,
            maxItems: $this->maxItems,
            autoPruneInterval: $this->autoPruneInterval,
        );
    }

    public function apcu(): ?ApcuManager
    {
        return $this->apcuManager;
    }

    public function ipc(): ?SysvIpcManager
    {
        return $this->ipcManager;
    }

    public function semaphore(): ?SysvSemaphoreManager
    {
        return $this->semaphoreManager;
    }

    public function queue(): ?SysvQueueManager
    {
        return $this->queueManager;
    }

    /**
     * Stores a value in RAM with optional TTL (`int` seconds, `\Time\Duration`, `DateTimeInterface`, or `0` for persistent).
     */
    public function set(string $key, mixed $value, int|Duration|DateTimeInterface|null $ttl = null): bool
    {
        $safeKey = $this->validateKey($key);
        $this->maybeAutoPrune();

        if ($this->isImmediatelyExpired($ttl)) {
            $this->delete($safeKey);
            return true;
        }

        return $this->writeRaw($safeKey, $value, $ttl);
    }

    public function put(string $key, mixed $value, int|Duration|DateTimeInterface|null $ttl = null): bool
    {
        return $this->set($key, $value, $ttl);
    }

    /**
     * Stores an item without TTL expiration (`0`).
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->set($key, $value, 0);
    }

    public function setUntil(string $key, mixed $value, DateTimeInterface $expiresAt): bool
    {
        return $this->set($key, $value, $expiresAt);
    }

    /**
     * Stores an item only if it does not currently exist (or has expired).
     */
    public function add(string $key, mixed $value, int|Duration|DateTimeInterface|null $ttl = null): bool
    {
        $safeKey = $this->validateKey($key);
        $this->maybeAutoPrune();

        if ($this->has($safeKey)) {
            return false;
        }

        if ($this->isImmediatelyExpired($ttl)) {
            return false;
        }

        if ($this->activeDriver === RamCacheDriver::Apcu && $this->apcuManager !== null) {
            $ttlSec = $this->resolveTtlSeconds($ttl);
            $stored = $this->apcuManager->add($safeKey, $value, $ttlSec);
            if ($stored) {
                $this->recordApcuExpirationMeta($safeKey, $ttlSec);
            }
            return $stored;
        }

        return $this->writeRaw($safeKey, $value, $ttl);
    }

    /**
     * Replaces an item only if it currently exists and has not expired.
     */
    public function replace(string $key, mixed $value, int|Duration|DateTimeInterface|null $ttl = null): bool
    {
        $safeKey = $this->validateKey($key);
        if (!$this->has($safeKey)) {
            return false;
        }

        return $this->set($safeKey, $value, $ttl);
    }

    /**
     * Retrieves an item from RAM, automatically removing it if its TTL has expired.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $safeKey = $this->validateKey($key);
        [$found, $value] = $this->readRaw($safeKey);

        if (!$found) {
            $this->misses++;
            return $default;
        }

        $this->hits++;
        return $value;
    }

    /**
     * Retrieves and atomically removes an item from RAM.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $safeKey = $this->validateKey($key);

        if ($this->activeDriver === RamCacheDriver::Sysv && $this->shmManager !== null) {
            if (!$this->shmManager->has($safeKey)) {
                $this->misses++;
                return $default;
            }
            $this->hits++;
            return $this->shmManager->pull($safeKey, $default);
        }

        [$found, $value] = $this->readRaw($safeKey);
        if (!$found) {
            $this->misses++;
            return $default;
        }

        $this->deleteRaw($safeKey);
        $this->hits++;
        return $value;
    }

    public function has(string $key): bool
    {
        $safeKey = $this->validateKey($key);
        [$found] = $this->readRaw($safeKey, updateLru: false);
        return $found;
    }

    public function exists(string $key): bool
    {
        return $this->has($key);
    }

    public function missing(string $key): bool
    {
        return !$this->has($key);
    }

    public function delete(string $key): bool
    {
        $safeKey = $this->validateKey($key);
        return $this->deleteRaw($safeKey);
    }

    public function forget(string $key): bool
    {
        return $this->delete($key);
    }

    /**
     * Refreshes the TTL of an existing item without altering its value.
     */
    public function touch(string $key, int|Duration|DateTimeInterface|null $ttl = null): bool
    {
        $safeKey = $this->validateKey($key);
        [$found, $value] = $this->readRaw($safeKey, updateLru: false);
        if (!$found) {
            return false;
        }

        if ($this->isImmediatelyExpired($ttl)) {
            $this->deleteRaw($safeKey);
            return true;
        }

        if ($this->activeDriver === RamCacheDriver::Sysv && $this->shmManager !== null) {
            $ttlSec = $this->resolveTtlSeconds($ttl);
            return $this->shmManager->touch($safeKey, $ttlSec);
        }

        return $this->writeRaw($safeKey, $value, $ttl);
    }

    /**
     * Updates the TTL of an existing key.
     */
    public function expire(string $key, int|Duration|DateTimeInterface $ttl): bool
    {
        return $this->touch($key, $ttl);
    }

    /**
     * Removes expiration from an existing key so it persists until explicitly deleted or evicted.
     */
    public function persist(string $key): bool
    {
        return $this->touch($key, 0);
    }

    /**
     * Returns the remaining TTL in seconds (`null` if missing or non-expiring).
     */
    public function ttl(string $key): ?int
    {
        $expiresAt = $this->expiresAt($key);
        if ($expiresAt === null) {
            return null;
        }

        $remaining = $expiresAt - time();
        if ($remaining <= 0) {
            $this->delete($key);
            return null;
        }

        return $remaining;
    }

    /**
     * Returns the Unix timestamp when the key expires, or `null` if missing or persistent.
     */
    public function expiresAt(string $key): ?int
    {
        $safeKey = $this->validateKey($key);

        if ($this->activeDriver === RamCacheDriver::Memory) {
            if (!isset($this->memoryStore[$safeKey])) {
                return null;
            }
            $exp = $this->memoryStore[$safeKey]['expires_at'];
            if ($exp === null) {
                return null;
            }
            if ($exp <= microtime(true)) {
                unset($this->memoryStore[$safeKey]);
                return null;
            }
            return (int) \ceil($exp);
        }

        if ($this->activeDriver === RamCacheDriver::Apcu && $this->apcuManager !== null) {
            if (!$this->apcuManager->has($safeKey)) {
                return null;
            }
            $meta = $this->apcuManager->get(self::INTERNAL_META_PREFIX . $safeKey);
            return is_int($meta) ? $meta : null;
        }

        if ($this->activeDriver === RamCacheDriver::Sysv && $this->shmManager !== null) {
            if (!$this->shmManager->has($safeKey)) {
                return null;
            }
            [$found, $metaExp] = $this->readRaw(self::INTERNAL_META_PREFIX . $safeKey, updateLru: false);
            return $found && is_int($metaExp) ? $metaExp : null;
        }

        return null;
    }

    /**
     * Computes and caches a value when missing or expired.
     */
    public function remember(string $key, callable $callback, int|Duration|DateTimeInterface|null $ttl = null): mixed
    {
        $safeKey = $this->validateKey($key);
        [$found, $existing] = $this->readRaw($safeKey);
        if ($found) {
            $this->hits++;
            return $existing;
        }

        $this->misses++;
        $computed = $callback($this);
        $this->set($safeKey, $computed, $ttl);

        return $computed;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, $callback, 0);
    }

    public function rememberUntil(string $key, callable $callback, DateTimeInterface $expiresAt): mixed
    {
        return $this->remember($key, $callback, $expiresAt);
    }

    /**
     * @param iterable<int|string, string|int> $keys
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): array
    {
        $results = [];
        foreach ($keys as $key) {
            $k = (string) $key;
            $results[$k] = $this->get($k, $default);
        }

        return $results;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, int|Duration|DateTimeInterface|null $ttl = null): bool
    {
        $allSucceeded = true;
        foreach ($values as $key => $value) {
            $allSucceeded = $this->set((string) $key, $value, $ttl) && $allSucceeded;
        }

        return $allSucceeded;
    }

    /**
     * @param iterable<int|string, string|int> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $allDeleted = true;
        foreach ($keys as $key) {
            $allDeleted = $this->delete((string) $key) && $allDeleted;
        }

        return $allDeleted;
    }

    /**
     * Atomically increments an integer counter in RAM.
     */
    public function increment(string $key, int $step = 1, int|Duration|null $ttl = null, int $initial = 0): int
    {
        $safeKey = $this->validateKey($key);

        if ($this->activeDriver === RamCacheDriver::Apcu && $this->apcuManager !== null) {
            $res = $this->apcuManager->increment($safeKey, $step, $ttl, $initial);
            if ($res === false) {
                throw new RuntimeException("Failed to increment non-numeric APCu cache key '{$safeKey}'.");
            }
            $this->recordApcuExpirationMeta($safeKey, $this->resolveTtlSeconds($ttl));
            return $res;
        }

        if ($this->activeDriver === RamCacheDriver::Sysv && $this->shmManager !== null) {
            if (!$this->shmManager->has($safeKey) && $initial !== 0) {
                $next = $initial + $step;
                $this->set($safeKey, $next, $ttl);
                return $next;
            }
            $next = $this->shmManager->increment($safeKey, $step, $ttl);
            $this->recordSysvExpirationMeta($safeKey, $this->resolveTtlSeconds($ttl));
            return $next;
        }

        [$found, $current] = $this->readRaw($safeKey);
        if (!$found) {
            $next = $initial + $step;
            $this->writeRaw($safeKey, $next, $ttl);
            return $next;
        }

        if (!is_int($current) && !is_float($current) && !is_numeric($current)) {
            throw new InvalidArgumentException("Cache key '{$safeKey}' does not hold a numeric value.");
        }

        $next = (int) $current + $step;
        $this->writeRaw($safeKey, $next, $ttl);
        return $next;
    }

    /**
     * Atomically decrements an integer counter in RAM.
     */
    public function decrement(string $key, int $step = 1, int|Duration|null $ttl = null, int $initial = 0): int
    {
        $safeStep = abs($step);

        if ($this->activeDriver === RamCacheDriver::Apcu && $this->apcuManager !== null) {
            $safeKey = $this->validateKey($key);
            $res = $this->apcuManager->decrement($safeKey, $safeStep, $ttl, $initial);
            if ($res === false) {
                throw new RuntimeException("Failed to decrement non-numeric APCu cache key '{$safeKey}'.");
            }
            $this->recordApcuExpirationMeta($safeKey, $this->resolveTtlSeconds($ttl));
            return $res;
        }

        return $this->increment($key, -$safeStep, $ttl, $initial);
    }

    /**
     * Compare-and-swap operation across any RAM backend.
     */
    public function compareAndSwap(string $key, mixed $expected, mixed $newValue, int|Duration|null $ttl = null): bool
    {
        $safeKey = $this->validateKey($key);

        if (
            $this->activeDriver === RamCacheDriver::Apcu
            && $this->apcuManager !== null
            && is_int($expected)
            && is_int($newValue)
            && $ttl === null
        ) {
            return $this->apcuManager->compareAndSwap($safeKey, $expected, $newValue);
        }

        return (bool) $this->withLock(function () use ($safeKey, $expected, $newValue, $ttl): bool {
            [$found, $current] = $this->readRaw($safeKey, updateLru: false);
            if (!$found || $current !== $expected) {
                return false;
            }

            return $this->writeRaw($safeKey, $newValue, $ttl);
        });
    }

    /**
     * Appends one or more values to the end of a cached list and returns the new length.
     */
    public function push(string $key, mixed ...$values): int
    {
        $safeKey = $this->validateKey($key);

        return (int) $this->withLock(function () use ($safeKey, $values): int {
            [$found, $list] = $this->readRaw($safeKey, updateLru: false);
            if (!$found) {
                $list = [];
            } elseif (!is_array($list)) {
                throw new InvalidArgumentException("Cache key '{$safeKey}' is not an array.");
            }

            foreach ($values as $v) {
                $list[] = $v;
            }

            $this->writeRaw($safeKey, $list, null);
            return count($list);
        });
    }

    /**
     * Removes and returns the last element of a cached list (`null` if empty or missing).
     */
    public function pop(string $key): mixed
    {
        $safeKey = $this->validateKey($key);

        if ($this->activeDriver === RamCacheDriver::Sysv && $this->shmManager !== null) {
            return $this->shmManager->pop($safeKey);
        }

        return $this->withLock(function () use ($safeKey): mixed {
            [$found, $list] = $this->readRaw($safeKey, updateLru: false);
            if (!$found || !is_array($list) || $list === []) {
                return null;
            }

            $item = array_pop($list);
            $this->writeRaw($safeKey, $list, null);
            return $item;
        });
    }

    /**
     * Prepends one or more values to the start of a cached list and returns the new length.
     */
    public function unshift(string $key, mixed ...$values): int
    {
        $safeKey = $this->validateKey($key);

        return (int) $this->withLock(function () use ($safeKey, $values): int {
            [$found, $list] = $this->readRaw($safeKey, updateLru: false);
            if (!$found) {
                $list = [];
            } elseif (!is_array($list)) {
                throw new InvalidArgumentException("Cache key '{$safeKey}' is not an array.");
            }

            array_unshift($list, ...$values);
            $this->writeRaw($safeKey, $list, null);
            return count($list);
        });
    }

    /**
     * Removes and returns the first element of a cached list (`null` if empty or missing).
     */
    public function shift(string $key): mixed
    {
        $safeKey = $this->validateKey($key);

        if ($this->activeDriver === RamCacheDriver::Sysv && $this->shmManager !== null) {
            return $this->shmManager->shift($safeKey);
        }

        return $this->withLock(function () use ($safeKey): mixed {
            [$found, $list] = $this->readRaw($safeKey, updateLru: false);
            if (!$found || !is_array($list) || $list === []) {
                return null;
            }

            $item = array_shift($list);
            $this->writeRaw($safeKey, $list, null);
            return $item;
        });
    }

    /**
     * Adds unique members to a cached set and returns the set's total cardinality.
     */
    public function addToSet(string $key, mixed ...$members): int
    {
        $safeKey = $this->validateKey($key);

        return (int) $this->withLock(function () use ($safeKey, $members): int {
            [$found, $set] = $this->readRaw($safeKey, updateLru: false);
            $list = $found && is_array($set) ? array_values($set) : [];

            foreach ($members as $member) {
                if (!in_array($member, $list, true)) {
                    $list[] = $member;
                }
            }

            $this->writeRaw($safeKey, $list, null);
            return count($list);
        });
    }

    /**
     * Removes members from a cached set and returns the number of removed items.
     */
    public function removeFromSet(string $key, mixed ...$members): int
    {
        $safeKey = $this->validateKey($key);

        return (int) $this->withLock(function () use ($safeKey, $members): int {
            [$found, $set] = $this->readRaw($safeKey, updateLru: false);
            if (!$found || !is_array($set) || $set === []) {
                return 0;
            }

            $filtered = [];
            $removed = 0;
            foreach ($set as $existing) {
                if (in_array($existing, $members, true)) {
                    $removed++;
                } else {
                    $filtered[] = $existing;
                }
            }

            $this->writeRaw($safeKey, $filtered, null);
            return $removed;
        });
    }

    /**
     * @return list<mixed>
     */
    public function setMembers(string $key): array
    {
        $safeKey = $this->validateKey($key);
        [$found, $set] = $this->readRaw($safeKey);

        return $found && is_array($set) ? array_values($set) : [];
    }

    /**
     * Stores an item associated with one or more invalidation tags.
     *
     * @param list<string> $tags
     */
    public function setTagged(array $tags, string $key, mixed $value, int|Duration|DateTimeInterface|null $ttl = null): bool
    {
        $stored = $this->set($key, $value, $ttl);
        if (!$stored) {
            return false;
        }

        $safeKey = $this->validateKey($key);
        foreach ($tags as $tag) {
            $cleanTag = trim($tag);
            if ($cleanTag === '') {
                continue;
            }
            $this->registerTaggedKey($cleanTag, $safeKey);
        }

        return true;
    }

    /**
     * Invalidates all cached keys associated with the given tag or list of tags.
     *
     * @param string|list<string> $tags
     */
    public function invalidateTags(string|array $tags): int
    {
        $tagList = is_string($tags) ? [$tags] : $tags;
        $deleted = 0;

        foreach ($tagList as $tag) {
            $cleanTag = trim($tag);
            if ($cleanTag === '') {
                continue;
            }

            $tagKey = self::INTERNAL_TAG_PREFIX . $cleanTag;
            [$found, $keys] = $this->readRaw($tagKey, updateLru: false);
            if ($found && is_array($keys)) {
                foreach ($keys as $k) {
                    if (is_string($k) && $this->deleteRaw($k)) {
                        $deleted++;
                    }
                }
            }
            $this->deleteRaw($tagKey);
        }

        return $deleted;
    }

    /**
     * Purges all user keys starting with `$prefix` and returns the count removed.
     */
    public function purgeByPrefix(string $prefix = ''): int
    {
        $deleted = 0;
        foreach ($this->keys() as $key) {
            if (str_starts_with($key, $prefix) && $this->deleteRaw($key)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Purges all user keys matching the given PCRE pattern and returns the count removed.
     */
    public function purgeByPattern(string $pattern): int
    {
        if (!$this->isValidRegex($pattern)) {
            throw new InvalidArgumentException("Invalid regex pattern: {$pattern}");
        }

        $deleted = 0;
        foreach ($this->keys() as $key) {
            if (preg_match($pattern, $key) === 1 && $this->deleteRaw($key)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Actively scans and removes expired entries across RAM cache and IPC queues.
     */
    public function pruneExpired(): int
    {
        $this->opsSincePrune = 0;
        $removed = 0;
        $now = microtime(true);

        if ($this->activeDriver === RamCacheDriver::Memory) {
            foreach ($this->memoryStore as $key => $record) {
                if ($record['expires_at'] !== null && $record['expires_at'] <= $now) {
                    unset($this->memoryStore[$key]);
                    if (!$this->isInternalKey($key)) {
                        $removed++;
                    }
                }
            }
        } elseif ($this->activeDriver === RamCacheDriver::Sysv && $this->shmManager !== null) {
            $removed += $this->shmManager->clearExpired();
        } elseif ($this->activeDriver === RamCacheDriver::Apcu && $this->apcuManager !== null) {
            $nowSec = time();
            foreach ($this->apcuManager->keys(true) as $rawKey) {
                if (!str_starts_with($rawKey, self::INTERNAL_META_PREFIX)) {
                    continue;
                }
                $targetKey = substr($rawKey, strlen(self::INTERNAL_META_PREFIX));
                $exp = $this->apcuManager->get($rawKey);
                if ((is_int($exp) && $exp <= $nowSec) || !$this->apcuManager->has($targetKey)) {
                    $this->apcuManager->delete($rawKey);
                    if ($this->apcuManager->delete($targetKey)) {
                        $removed++;
                    }
                }
            }
        }

        if ($this->queueManager !== null) {
            $removed += $this->queueManager->purgeExpiredMessages();
        }

        return $removed;
    }

    /**
     * Lists all non-expired user keys in the current namespace.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $this->pruneExpired();

        $rawKeys = match ($this->activeDriver) {
            RamCacheDriver::Memory => array_keys($this->memoryStore),
            RamCacheDriver::Apcu => $this->apcuManager !== null ? $this->apcuManager->keys(true) : [],
            RamCacheDriver::Sysv => $this->shmManager !== null ? $this->shmManager->keys() : [],
            RamCacheDriver::Auto => [],
        };

        $filtered = [];
        foreach ($rawKeys as $key) {
            if (!$this->isInternalKey($key)) {
                $filtered[] = $key;
            }
        }

        return $filtered;
    }

    /**
     * Returns all non-expired user items in the current namespace.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $items = [];
        foreach ($this->keys() as $key) {
            [$found, $val] = $this->readRaw($key, updateLru: false);
            if ($found) {
                $items[$key] = $val;
            }
        }

        return $items;
    }

    #[\Override]
    public function count(): int
    {
        return count($this->keys());
    }

    /**
     * Clears all entries within this manager's namespace.
     */
    public function clear(): bool
    {
        if ($this->activeDriver === RamCacheDriver::Memory) {
            $this->memoryStore = [];
            return true;
        }

        if ($this->activeDriver === RamCacheDriver::Apcu && $this->apcuManager !== null) {
            $this->apcuManager->clearNamespace();
            return true;
        }

        if ($this->activeDriver === RamCacheDriver::Sysv && $this->shmManager !== null) {
            $this->shmManager->clear();
            return true;
        }

        return false;
    }

    public function flush(): bool
    {
        return $this->clear();
    }

    /**
     * Executes `$callback` inside a cross-platform mutual exclusion lock.
     */
    public function withLock(callable $callback, bool $nowait = false): mixed
    {
        if ($this->semaphoreManager !== null) {
            return $this->semaphoreManager->withLock(static fn(): mixed => $callback(), $nowait);
        }

        if ($this->activeDriver === RamCacheDriver::Apcu && $this->apcuManager !== null) {
            $lockKey = self::INTERNAL_LOCK_PREFIX . 'mutex';
            $attempts = $nowait ? 1 : 200;
            $acquired = false;

            for ($i = 0; $i < $attempts; $i++) {
                if ($this->apcuManager->add($lockKey, 1, 15)) {
                    $acquired = true;
                    break;
                }
                if (!$nowait) {
                    \usleep(1_000);
                }
            }

            if (!$acquired) {
                throw new RuntimeException('Failed to acquire APCu mutex lock.');
            }

            try {
                return $callback();
            } finally {
                $this->apcuManager->delete($lockKey);
            }
        }

        if ($this->inMemoryLockHeld && $nowait) {
            throw new RuntimeException('In-memory cache lock is already held.');
        }

        $previous = $this->inMemoryLockHeld;
        $this->inMemoryLockHeld = true;
        try {
            return $callback();
        } finally {
            $this->inMemoryLockHeld = $previous;
        }
    }

    /**
     * Enqueues a TTL-aware message into `SysvQueueManager` (if available) or the active RAM cache.
     */
    public function enqueue(mixed $payload, int $channel = 1, int|Duration|null $ttl = null): bool
    {
        $safeChannel = clamp($channel, 1, 1_000_000);

        if ($this->queueManager !== null) {
            return $this->queueManager->sendMessage($payload, $safeChannel, $ttl, blocking: false);
        }

        $expiresAt = $this->computeExpirationFloat($ttl, useDefaultWhenNull: false);
        $envelope = [
            'payload' => $payload,
            'expires_at' => $expiresAt,
        ];

        $queueKey = self::INTERNAL_QUEUE_PREFIX . $safeChannel;
        return (bool) $this->withLock(function () use ($queueKey, $envelope): bool {
            [$found, $queue] = $this->readRaw($queueKey, updateLru: false);
            $items = $found && is_array($queue) ? $queue : [];
            $items[] = $envelope;
            return $this->writeRaw($queueKey, $items, 0);
        });
    }

    /**
     * Dequeues the next non-expired message from the given channel (`null` when empty).
     */
    public function dequeue(int $channel = 1): mixed
    {
        $safeChannel = clamp($channel, 1, 1_000_000);

        if ($this->queueManager !== null) {
            $msg = $this->queueManager->receiveMessage($safeChannel);
            return $msg['message'] ?? null;
        }

        $queueKey = self::INTERNAL_QUEUE_PREFIX . $safeChannel;
        return $this->withLock(function () use ($queueKey): mixed {
            [$found, $queue] = $this->readRaw($queueKey, updateLru: false);
            if (!$found || !is_array($queue) || $queue === []) {
                return null;
            }

            $now = microtime(true);
            $result = null;
            $foundValid = false;

            while ($queue !== []) {
                $item = array_shift($queue);
                if (!is_array($item)) {
                    continue;
                }
                $exp = $item['expires_at'] ?? null;
                if ((is_float($exp) || is_int($exp)) && (float) $exp <= $now) {
                    continue;
                }
                $result = $item['payload'] ?? null;
                $foundValid = true;
                break;
            }

            if ($queue === []) {
                $this->deleteRaw($queueKey);
            } else {
                $this->writeRaw($queueKey, $queue, 0);
            }

            return $foundValid ? $result : null;
        });
    }

    /**
     * Returns diagnostic metrics for the active RAM cache backend.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $totalRequests = $this->hits + $this->misses;

        return [
            'driver' => $this->activeDriver->value,
            'available_drivers' => self::availableDrivers(),
            'namespace' => $this->namespace,
            'default_ttl' => $this->defaultTtlSeconds,
            'items' => $this->count(),
            'max_items' => $this->maxItems,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => $totalRequests > 0 ? \round(($this->hits / $totalRequests) * 100, 2) : 0.0,
            'queue_driver' => $this->queueManager !== null ? 'sysvmsg' : $this->activeDriver->value,
            'lock_driver' => $this->semaphoreManager !== null ? 'sysvsem' : $this->activeDriver->value,
        ];
    }

    private function initializeBackend(
        RamCacheDriver $requested,
        int $baseKey,
        int $permissions,
        int $shmSize,
        bool $strict,
    ): RamCacheDriver {
        if ($requested === RamCacheDriver::Auto || $requested === RamCacheDriver::Apcu) {
            if (self::isApcuAvailable()) {
                $this->apcuManager = new ApcuManager($this->namespace, $this->defaultTtlSeconds, strict: true);
                $this->attachOptionalSysvPrimitives($baseKey, $permissions);
                return RamCacheDriver::Apcu;
            }

            if ($requested === RamCacheDriver::Apcu && $strict) {
                throw new RuntimeException('APCu RAM cache driver was explicitly requested, but APCu is not enabled.');
            }
        }

        if ($requested === RamCacheDriver::Auto || $requested === RamCacheDriver::Sysv) {
            if (self::isSysvShmAvailable()) {
                try {
                    $this->ipcManager = new SysvIpcManager(
                        baseKey: $baseKey,
                        permissions: $permissions,
                        shmSize: $shmSize,
                        defaultTtl: $this->defaultTtlSeconds,
                        namespace: $this->namespace,
                    );
                    $this->shmManager = $this->ipcManager->sharedMemory();
                    $this->semaphoreManager = $this->ipcManager->semaphore();
                    if (self::isSysvQueueAvailable()) {
                        $this->queueManager = $this->ipcManager->queue();
                    }
                    return RamCacheDriver::Sysv;
                } catch (Throwable $e) {
                    if ($requested === RamCacheDriver::Sysv && $strict) {
                        throw new RuntimeException('SysV IPC shared memory initialization failed: ' . $e->getMessage(), 0, $e);
                    }
                }
            } elseif ($requested === RamCacheDriver::Sysv && $strict) {
                throw new RuntimeException('SysV IPC RAM cache driver was explicitly requested, but sysvshm/sysvsem are unavailable.');
            }
        }

        $this->attachOptionalSysvPrimitives($baseKey, $permissions);
        return RamCacheDriver::Memory;
    }

    private function attachOptionalSysvPrimitives(int $baseKey, int $permissions): void
    {
        if ($this->semaphoreManager === null && extension_loaded('sysvsem') && function_exists('sem_get')) {
            try {
                $this->semaphoreManager = new SysvSemaphoreManager($baseKey, $permissions, $this->namespace);
            } catch (Throwable) {
                $this->semaphoreManager = null;
            }
        }

        if ($this->queueManager === null && self::isSysvQueueAvailable()) {
            try {
                $this->queueManager = new SysvQueueManager($baseKey, $permissions, $this->namespace);
            } catch (Throwable) {
                $this->queueManager = null;
            }
        }
    }

    private function writeRaw(string $key, mixed $value, int|Duration|DateTimeInterface|null $ttl): bool
    {
        if ($this->activeDriver === RamCacheDriver::Memory) {
            $now = microtime(true);
            $expiresAt = $this->computeExpirationFloat($ttl, useDefaultWhenNull: true);

            if (!isset($this->memoryStore[$key]) && count($this->memoryStore) >= $this->maxItems) {
                $this->pruneExpired();
                while (count($this->memoryStore) >= $this->maxItems) {
                    $oldest = array_key_first($this->memoryStore);
                    if ($oldest === null) {
                        break;
                    }
                    unset($this->memoryStore[$oldest]);
                }
            }

            $createdAt = $this->memoryStore[$key]['created_at'] ?? $now;
            unset($this->memoryStore[$key]);
            $this->memoryStore[$key] = [
                'value' => $value,
                'created_at' => $createdAt,
                'updated_at' => $now,
                'expires_at' => $expiresAt,
            ];

            return true;
        }

        $ttlSeconds = $this->resolveTtlSeconds($ttl);

        if ($this->activeDriver === RamCacheDriver::Apcu && $this->apcuManager !== null) {
            $ok = $this->apcuManager->set($key, $value, $ttlSeconds);
            if ($ok && !$this->isInternalKey($key)) {
                $this->recordApcuExpirationMeta($key, $ttlSeconds);
            }
            return $ok;
        }

        if ($this->activeDriver === RamCacheDriver::Sysv && $this->shmManager !== null) {
            $this->shmManager->set($key, $value, $ttlSeconds);
            if (!$this->isInternalKey($key)) {
                $this->recordSysvExpirationMeta($key, $ttlSeconds);
            }
            return true;
        }

        return false;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function readRaw(string $key, bool $updateLru = true): array
    {
        if ($this->activeDriver === RamCacheDriver::Memory) {
            if (!array_key_exists($key, $this->memoryStore)) {
                return [false, null];
            }

            $record = $this->memoryStore[$key];
            if ($record['expires_at'] !== null && $record['expires_at'] <= microtime(true)) {
                unset($this->memoryStore[$key]);
                return [false, null];
            }

            if ($updateLru) {
                unset($this->memoryStore[$key]);
                $record['updated_at'] = microtime(true);
                $this->memoryStore[$key] = $record;
            }

            return [true, $record['value']];
        }

        if ($this->activeDriver === RamCacheDriver::Apcu && $this->apcuManager !== null) {
            if (!$this->apcuManager->has($key)) {
                return [false, null];
            }

            return [true, $this->apcuManager->get($key)];
        }

        if ($this->activeDriver === RamCacheDriver::Sysv && $this->shmManager !== null) {
            if (!$this->shmManager->has($key)) {
                return [false, null];
            }

            return [true, $this->shmManager->get($key)];
        }

        return [false, null];
    }

    private function deleteRaw(string $key): bool
    {
        if ($this->activeDriver === RamCacheDriver::Memory) {
            if (!array_key_exists($key, $this->memoryStore)) {
                return false;
            }
            unset($this->memoryStore[$key]);
            return true;
        }

        if ($this->activeDriver === RamCacheDriver::Apcu && $this->apcuManager !== null) {
            $this->apcuManager->delete(self::INTERNAL_META_PREFIX . $key);
            return $this->apcuManager->delete($key);
        }

        if ($this->activeDriver === RamCacheDriver::Sysv && $this->shmManager !== null) {
            $this->shmManager->delete(self::INTERNAL_META_PREFIX . $key);
            return $this->shmManager->delete($key);
        }

        return false;
    }

    private function registerTaggedKey(string $tag, string $key): void
    {
        $tagKey = self::INTERNAL_TAG_PREFIX . $tag;
        [$found, $existing] = $this->readRaw($tagKey, updateLru: false);
        $keys = $found && is_array($existing) ? $existing : [];
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            $this->writeRaw($tagKey, $keys, 0);
        }
    }

    private function recordApcuExpirationMeta(string $key, int $ttlSeconds): void
    {
        if ($this->apcuManager === null) {
            return;
        }

        $metaKey = self::INTERNAL_META_PREFIX . $key;
        if ($ttlSeconds <= 0) {
            $this->apcuManager->delete($metaKey);
            return;
        }

        $this->apcuManager->set($metaKey, time() + $ttlSeconds, $ttlSeconds);
    }

    private function recordSysvExpirationMeta(string $key, int $ttlSeconds): void
    {
        if ($this->shmManager === null) {
            return;
        }

        $metaKey = self::INTERNAL_META_PREFIX . $key;
        if ($ttlSeconds <= 0) {
            $this->shmManager->delete($metaKey);
            return;
        }

        $this->shmManager->set($metaKey, time() + $ttlSeconds, $ttlSeconds);
    }

    private function maybeAutoPrune(): void
    {
        $this->opsSincePrune++;
        if ($this->opsSincePrune >= $this->autoPruneInterval) {
            $this->pruneExpired();
        }
    }

    private function isImmediatelyExpired(int|Duration|DateTimeInterface|null $ttl): bool
    {
        if ($ttl === null) {
            return false;
        }
        if ($ttl instanceof Duration) {
            return $ttl->negative;
        }
        if ($ttl instanceof DateTimeInterface) {
            return $ttl->getTimestamp() <= time();
        }

        return $ttl < 0;
    }

    private function computeExpirationFloat(int|Duration|DateTimeInterface|null $ttl, bool $useDefaultWhenNull): ?float
    {
        if ($ttl === null) {
            if (!$useDefaultWhenNull || $this->defaultTtlSeconds <= 0) {
                return null;
            }
            return microtime(true) + (float) $this->defaultTtlSeconds;
        }

        if ($ttl instanceof DateTimeInterface) {
            return (float) $ttl->getTimestamp();
        }

        if ($ttl instanceof Duration) {
            if ($ttl->negative) {
                return microtime(true) - 1.0;
            }
            $totalSeconds = (float) $ttl->seconds + ((float) $ttl->nanoseconds / 1_000_000_000.0);
            if ($totalSeconds <= 0.0) {
                return null;
            }
            return microtime(true) + $totalSeconds;
        }

        if ($ttl === 0) {
            return null;
        }

        return microtime(true) + (float) $ttl;
    }

    private function resolveTtlSeconds(int|Duration|DateTimeInterface|null $ttl): int
    {
        if ($ttl === null) {
            return $this->defaultTtlSeconds;
        }

        if ($ttl instanceof DateTimeInterface) {
            return clamp($ttl->getTimestamp() - time(), 0, \PHP_INT_MAX);
        }

        return $this->durationToSeconds($ttl);
    }

    private function durationToSeconds(int|Duration $ttl): int
    {
        if ($ttl instanceof Duration) {
            if ($ttl->negative) {
                return 0;
            }
            if ($ttl->seconds === 0 && $ttl->nanoseconds > 0) {
                return 1;
            }
            return $ttl->seconds;
        }

        return clamp($ttl, 0, \PHP_INT_MAX);
    }

    private function validateKey(string $key): string
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Cache key cannot be empty.');
        }

        return $trimmed;
    }

    private function isInternalKey(string $key): bool
    {
        return str_starts_with($key, self::INTERNAL_META_PREFIX)
            || str_starts_with($key, self::INTERNAL_TAG_PREFIX)
            || str_starts_with($key, self::INTERNAL_QUEUE_PREFIX)
            || str_starts_with($key, self::INTERNAL_LOCK_PREFIX);
    }

    private function normalizeNamespace(string $namespace): string
    {
        $trimmed = trim($namespace);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Cache namespace cannot be empty.');
        }

        $sanitized = preg_replace('/[^A-Za-z0-9_\-:.]/', '_', $trimmed);
        if ($sanitized === null || $sanitized === '') {
            throw new InvalidArgumentException('Invalid cache namespace.');
        }

        return $sanitized;
    }

    private function isValidRegex(string $pattern): bool
    {
        set_error_handler(static fn(): bool => true);
        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
