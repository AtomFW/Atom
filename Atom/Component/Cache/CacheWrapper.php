<?php

declare(strict_types=1);

namespace Atom\Component\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use DateInterval;
use Psr\Log\LoggerInterface;
use Throwable;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * CacheWrapper
 *
 * - storage for underlying Symfony Cache (PSR-6) instance.
 * - Wrapper methods for common PSR-6 operations + useful helpers (get/set/remember/getMultiple/setMultiple/tag).
 * - If you later change cache implementation (another PSR-6 adapter), replace adapter via setAdapter().
 *
 */
final class CacheWrapper implements CacheItemPoolInterface, CacheInterface
// final class CacheWrapper implements CacheInterface
{
    /**
     * PSR-6 cache pool instance (shared across process).
     * Set via setAdapter() or initFilesystemAdapter().
     *
     * @var CacheItemPoolInterface|null
     */
    private static ?CacheItemPoolInterface $pool = null;

    /**
     * Optional logger for debug (not required).
     *
     * @var LoggerInterface|null
     */
    private static ?LoggerInterface $logger = null;

    /**
     * Cache adapter supports TagAwareAdapter.
     *
     * @var bool
     */
    private static bool $tagAware = false;

    // -------------------------
    // Initialization / DI
    // -------------------------

    /**
     * Set the PSR-6 cache adapter instance (static).
     *
     * @param CacheItemPoolInterface $adapter
     */
    public static function setAdapter(CacheItemPoolInterface $adapter): void
    {
        static::$pool = $adapter;
    }

    /**
     * Return currently configured adapter or null.
     *
     * @return CacheItemPoolInterface|null
     */
    public static function getAdapter(): ?CacheItemPoolInterface
    {
        return static::$pool;
    }

    /**
     * Set optional PSR-3 logger for internal debug.
     *
     * @param LoggerInterface|null $logger
     */
    public static function setLogger(?LoggerInterface $logger): void
    {
        static::$logger = $logger;
    }

    /**
     * Create and set a Symfony FilesystemAdapter as the pool.
     *
     * Usage:
     *   CacheWrapper::initFilesystemAdapter('app_cache', 3600, '/tmp/my_cache');
     *
     * @param string $namespace
     * @param int $defaultLifetime seconds
     * @param string|null $directory cache directory; if null, sys_get_temp_dir() is used
     */
    public function initFilesystemAdapter(
        string $namespace = '',
        int $defaultLifetime = 0,
        ?string $directory = null,
        bool $tagAware = true
    ): void {
        $dir = $directory ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atom_cache');
        try {
            $adapter = new FilesystemAdapter($namespace, $defaultLifetime, $dir);

            if ($tagAware) {
                $adapter = new TagAwareAdapter($adapter);
                static::$tagAware = true;
            }

            static::setAdapter($adapter);
        } catch (Throwable $e) {
            // swallow but log if possible
            if (static::$logger !== null) {
                static::$logger->error('Failed to initialize FilesystemAdapter: ' . $e->getMessage());
            }
            static::$pool = null;
        }
    }

    /**
     * Redis factory
     */
    // public  function initRedis(
    //     string $dsn = 'redis://127.0.0.1:6379',
    //     string $namespace = '',
    //     int $defaultLifetime = 0
    // ): void {
    //     $connection = RedisAdapter::createConnection($dsn);
    //     $adapter = new RedisAdapter($connection, $namespace, $defaultLifetime);

    //     self::init($adapter, true);
    // }

    /**
     * Ensure adapter present; throw if not.
     *
     * @throws \RuntimeException
     */
    private function ensureAdapter(): CacheItemPoolInterface
    {
        if (self::$pool === null) {
            throw new \RuntimeException(
                'Cache adapter is not initialized. Call CacheWrapper::setAdapter() or ::initFilesystemAdapter().'
            );
        }
        return self::$pool;
    }

    // -------------------------
    // PSR-6 basic wrappers
    // -------------------------

    /**
     * Get a CacheItem by key.
     */
    public function getItem(string $key): CacheItemInterface
    {
        $pool = self::ensureAdapter();
        return $pool->getItem($key);
    }

    /**
     * Get multiple items by keys.
     *
     * @return CacheItemInterface[]
     */
    public function getItems(array $keys = []): iterable
    {
        $pool = self::ensureAdapter();
        $items = $pool->getItems($keys);
        // getItems returns Traversable; convert to array
        $out = [];
        foreach ($items as $item) {
            $out[$item->getKey()] = $item;
        }
        return $out;
    }

    /**
     * Check item existence.
     */
    public function hasItem(string $key): bool
    {
        $pool = self::ensureAdapter();
        return $pool->hasItem($key);
    }

    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    /**
     * Save item.
     */
    public function save(CacheItemInterface $item): bool
    {
        $pool = self::ensureAdapter();
        try {
            return $pool->save($item);
        } catch (Throwable $e) {
            if (self::$logger !== null) {
                self::$logger->warning('Cache save failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Save deferred.
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        $pool = self::ensureAdapter();
        try {
            return $pool->saveDeferred($item);
        } catch (Throwable $e) {
            if (self::$logger !== null) {
                self::$logger->warning('Cache saveDeferred failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Commit deferred saves.
     */
    public function commit(): bool
    {
        $pool = self::ensureAdapter();
        try {
            return $pool->commit();
        } catch (Throwable $e) {
            if (self::$logger !== null) {
                self::$logger->warning('Cache commit failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Delete a single item.
     */
    public function deleteItem(string $key): bool
    {
        $pool = self::ensureAdapter();
        try {
            return $pool->deleteItem($key);
        } catch (Throwable $e) {
            if (self::$logger !== null) {
                self::$logger->warning('Cache deleteItem failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Delete multiple items.
     */
    public function deleteItems(array $keys): bool
    {
        $pool = self::ensureAdapter();
        try {
            return $pool->deleteItems($keys);
        } catch (Throwable $e) {
            if (self::$logger !== null) {
                self::$logger->warning('Cache deleteItems failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    public function delete(string $key): bool
    {
        return $this->deleteItem($key);
    }

    /**
     * Clear all cache.
     */
    public function clear(): bool
    {
        $pool = self::ensureAdapter();
        try {
            return $pool->clear();
        } catch (Throwable $e) {
            if (self::$logger !== null) {
                self::$logger->warning('Cache clear failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    // -------------------------
    // Convenience helpers (get/set/remember)
    // -------------------------

    /**
     * Simple get - similar to PSR-16 get:
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $item = self::getItem($key);
        if ($item->isHit()) {
            return $item->get();
        }
        return $default;
    }

    /**
     * Simple set helper - TTL in seconds or DateInterval; returns bool
     *
     * @param string $key
     * @param mixed $value
     * @param int|\DateInterval|null $ttl
     */
    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $item = self::getItem($key);
        $item->set($value);
        if ($ttl !== null) {
            if (is_int($ttl)) {
                $item->expiresAfter($ttl);
            } else {
                $item->expiresAt((new \DateTime())->add($ttl));
            }
        }
        return self::save($item);
    }

    /**
     * Get or compute value and save it (atomic-ish for single process).
     *
     * $callback receives CacheItemInterface and should return the value to store.
     *
     * @param string $key
     * @param callable(CacheItemInterface): mixed $callback
     * @param int|\DateInterval|null $ttl
     * @return mixed
     */
    public function remember(
        string $key,
        callable $callback,
        int|\DateInterval|null $ttl = null,
        array $tags = []
    ): mixed {
        $item = self::getItem($key);
        if ($item->isHit()) {
            return $item->get();
        }

        $value = $callback($item);

        $item->set($value);
        if ($ttl !== null) {
            if (\is_int($ttl)) {
                $item->expiresAfter($ttl);
            } else {
                $item->expiresAt((new \DateTime())->add($ttl));
            }
        }

        // if (self::$tagAware && $tags) {
        //     $item->tag($tags);
        // }

        self::save($item);

        return $value;
    }

    /**
     * Get multiple values by keys. Returns associative array key => value (not CacheItem).
     *
     * Missing keys will have $default value.
     *
     * @param string[] $keys
     * @param mixed $default
     * @return iterable<string,mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $items = self::getItems($keys);
        $out = [];
        foreach ($keys as $k) {
            $item = $items[$k] ?? null;
            if ($item instanceof CacheItemInterface && $item->isHit()) {
                $out[$k] = $item->get();
            } else {
                $out[$k] = $default;
            }
        }
        return $out;
    }

    /**
     * Set multiple values. $values is associative array key => value.
     * Returns true on success.
     *
     * @param iterable<string,mixed> $values
     * @param int|\DateInterval|null $ttl
     */
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        $pool = self::ensureAdapter();
        try {
            foreach ($values as $k => $v) {
                $item = $pool->getItem((string)$k);
                $item->set($v);
                if ($ttl !== null) {
                    if (is_int($ttl)) {
                        $item->expiresAfter($ttl);
                    } else {
                        $item->expiresAt((new \DateTime())->add($ttl));
                    }
                }
                $pool->saveDeferred($item);
            }
            return $pool->commit();
        } catch (Throwable $e) {
            if (self::$logger !== null) {
                self::$logger->warning('Cache setMultiple failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Delete multiple keys.
     *
     * @param string[] $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        return self::deleteItems($keys);
    }

    // -------------------------
    // Tagging helpers (best-effort)
    // -------------------------

    /**
     * Check whether underlying adapter supports tagging.
     */
    public function supportsTags(): bool
    {
        $pool = self::$pool;
        if ($pool === null) {
            return false;
        }

        // Tagging in Symfony is provided by TagAwareAdapter (and items returned support tag())
        // Best-effort: check class or interface
        if ($pool instanceof TagAwareAdapter) {
            return true;
        }

        // Some adapters may still provide tag ability on items;
        // check by creating a dummy item and testing method existence.
        try {
            $item = $pool->getItem('__cache_wrapper_tag_test__');
            return method_exists($item, 'tag');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Tag support
     */
    // public function tag(string|array $tags): CacheWrapper
    // {
    //     if (!self::$tagAware) {
    //         throw new \RuntimeException('Tag support is not enabled.');
    //     }

    //     $item = self::ensureAdapter()->getItem('__tag_tmp_' . uniqid());
    //     $item->tag((array)$tags);

    //     return $item;
    // }

    /**
     * Tag an item (if supported). Returns true on success.
     *
     * @param string $key
     * @param string[] $tags
     */
    public function tagItem(string $key, array $tags): bool
    {
        $pool = self::ensureAdapter();
        try {
            $item = $pool->getItem($key);
            if (!method_exists($item, 'tag')) {
                if (self::$logger !== null) {
                    self::$logger->debug('Cache item does not support tagging.');
                }
                return false;
            }
            // tag() may return the item or nothing depending on impl
            // $item->tag($tags);
            return $pool->save($item);
        } catch (Throwable $e) {
            if (self::$logger !== null) {
                self::$logger->warning('Cache tagItem failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Invalidate by tags - if adapter supports TagAwareAdapter->invalidateTags()
     *
     * @param string[] $tags
     */
    public function invalidateTags(array $tags): bool
    {
        $pool = self::ensureAdapter();
        // If underlying adapter is TagAwareAdapter, call its invalidateTags()
        if ($pool instanceof TagAwareAdapter) {
            try {
                return $pool->invalidateTags($tags);
            } catch (Throwable $e) {
                if (self::$logger !== null) {
                    self::$logger->warning('Cache invalidateTags failed: ' . $e->getMessage());
                }
                return false;
            }
        }

        // Otherwise, no-op
        if (self::$logger !== null) {
            self::$logger->debug('Cache adapter does not support invalidateTags.');
        }
        return false;
    }

    // -------------------------
    // Introspection / util
    // -------------------------

    public function isTagAware(): bool
    {
        return static::$tagAware;
    }

    /**
     * Return an array summary of wrapper state (for debugging).
     */
    public function debugState(): array
    {
        return [
            'adapter_set' => self::$pool !== null,
            'adapter_class' => self::$pool ? get_class(self::$pool) : null,
            'supports_tags' => self::supportsTags(),
        ];
    }

    /**
     * Clear adapter (for tests).
     */
    public function reset(): void
    {
        self::$pool = null;
        self::$logger = null;
    }
}
