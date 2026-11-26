<?php

declare(strict_types=1);

namespace Atom\Component\Cache;

use DateInterval;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SensitiveParameter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Throwable;
use Time\Duration;

/**
 * PSR-6 and PSR-16 Cache wrapper supporting instance-isolated or process-level pools,
 * \Time\Duration TTLs, TagAwareAdapter invalidation, and runtime/cache storage defaults.
 */
final class CacheWrapper implements CacheItemPoolInterface, CacheInterface
{
    private static ?CacheItemPoolInterface $pool = null;
    private static ?LoggerInterface $logger = null;
    private static bool $tagAware = false;

    private ?CacheItemPoolInterface $instancePool = null;
    private ?LoggerInterface $instanceLogger = null;

    public function __construct(
        ?CacheItemPoolInterface $adapter = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->instancePool = $adapter;
        $this->instanceLogger = $logger;
    }

    public static function setAdapter(CacheItemPoolInterface $adapter): void
    {
        self::$pool = $adapter;
        self::$tagAware = $adapter instanceof TagAwareAdapter;
    }

    public static function getAdapter(): ?CacheItemPoolInterface
    {
        return self::$pool;
    }

    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    public function initFilesystemAdapter(
        string $namespace = '',
        int $defaultLifetime = 0,
        ?string $directory = null,
        bool $tagAware = true,
    ): void {
        $dir = $directory ?? (\dirname(__DIR__, 3) . \DIRECTORY_SEPARATOR . 'runtime' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'pool');
        try {
            $adapter = new FilesystemAdapter($namespace, $defaultLifetime, $dir);

            if ($tagAware) {
                $adapter = new TagAwareAdapter($adapter);
                self::$tagAware = true;
            } else {
                self::$tagAware = false;
            }

            self::$pool = $adapter;
            $this->instancePool = $adapter;
        } catch (Throwable $e) {
            $this->resolveLogger()?->error('Failed to initialize FilesystemAdapter: ' . $e->getMessage());
            self::$pool = null;
            $this->instancePool = null;
        }
    }

    public function initRedis(
        #[SensitiveParameter] string $dsn = 'redis://127.0.0.1:6379',
        string $namespace = '',
        int $defaultLifetime = 0,
        bool $tagAware = true,
    ): void {
        try {
            $connection = RedisAdapter::createConnection($dsn);
            $adapter = new RedisAdapter($connection, $namespace, $defaultLifetime);

            if ($tagAware) {
                $adapter = new TagAwareAdapter($adapter);
                self::$tagAware = true;
            } else {
                self::$tagAware = false;
            }

            self::$pool = $adapter;
            $this->instancePool = $adapter;
        } catch (Throwable $e) {
            $this->resolveLogger()?->error('Failed to initialize RedisAdapter: ' . $e->getMessage());
            self::$pool = null;
            $this->instancePool = null;
        }
    }

    private function ensureAdapter(): CacheItemPoolInterface
    {
        $pool = $this->instancePool ?? self::$pool;
        if ($pool === null) {
            throw new \RuntimeException(
                'Cache adapter is not initialized. Call CacheWrapper::setAdapter() or ::initFilesystemAdapter().',
            );
        }

        return $pool;
    }

    private function resolveLogger(): ?LoggerInterface
    {
        return $this->instanceLogger ?? self::$logger;
    }

    private function applyExpiration(CacheItemInterface $item, int|DateInterval|Duration|null $ttl): void
    {
        if ($ttl === null) {
            return;
        }

        if ($ttl instanceof Duration) {
            $item->expiresAfter($ttl->negative ? 0 : $ttl->seconds);
            return;
        }

        if (\is_int($ttl)) {
            $item->expiresAfter($ttl);
            return;
        }

        $item->expiresAt((new \DateTimeImmutable())->add($ttl));
    }

    #[\Override]
    public function getItem(string $key): CacheItemInterface
    {
        return $this->ensureAdapter()->getItem($key);
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, CacheItemInterface>
     */
    #[\Override]
    public function getItems(array $keys = []): iterable
    {
        $items = $this->ensureAdapter()->getItems($keys);
        $out = [];
        foreach ($items as $item) {
            $out[$item->getKey()] = $item;
        }

        return $out;
    }

    #[\Override]
    public function hasItem(string $key): bool
    {
        return $this->ensureAdapter()->hasItem($key);
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    #[\Override]
    public function save(CacheItemInterface $item): bool
    {
        $pool = $this->ensureAdapter();
        try {
            return $pool->save($item);
        } catch (Throwable $e) {
            $this->resolveLogger()?->warning('Cache save failed: ' . $e->getMessage());
            return false;
        }
    }

    #[\Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        $pool = $this->ensureAdapter();
        try {
            return $pool->saveDeferred($item);
        } catch (Throwable $e) {
            $this->resolveLogger()?->warning('Cache saveDeferred failed: ' . $e->getMessage());
            return false;
        }
    }

    #[\Override]
    public function commit(): bool
    {
        $pool = $this->ensureAdapter();
        try {
            return $pool->commit();
        } catch (Throwable $e) {
            $this->resolveLogger()?->warning('Cache commit failed: ' . $e->getMessage());
            return false;
        }
    }

    #[\Override]
    public function deleteItem(string $key): bool
    {
        $pool = $this->ensureAdapter();
        try {
            return $pool->deleteItem($key);
        } catch (Throwable $e) {
            $this->resolveLogger()?->warning('Cache deleteItem failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @param array<int, string> $keys
     */
    #[\Override]
    public function deleteItems(array $keys): bool
    {
        $pool = $this->ensureAdapter();
        try {
            return $pool->deleteItems($keys);
        } catch (Throwable $e) {
            $this->resolveLogger()?->warning('Cache deleteItems failed: ' . $e->getMessage());
            return false;
        }
    }

    #[\Override]
    public function delete(string $key): bool
    {
        return $this->deleteItem($key);
    }

    #[\Override]
    public function clear(): bool
    {
        $pool = $this->ensureAdapter();
        try {
            return $pool->clear();
        } catch (Throwable $e) {
            $this->resolveLogger()?->warning('Cache clear failed: ' . $e->getMessage());
            return false;
        }
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->getItem($key);
        return $item->isHit() ? $item->get() : $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|DateInterval|Duration $ttl = null): bool
    {
        $item = $this->getItem($key);
        $item->set($value);
        $this->applyExpiration($item, $ttl);
        return $this->save($item);
    }

    /**
     * @param callable(CacheItemInterface): mixed $callback
     * @param array<int, string> $tags
     */
    public function remember(
        string $key,
        callable $callback,
        null|int|DateInterval|Duration $ttl = null,
        array $tags = [],
    ): mixed {
        $item = $this->getItem($key);
        if ($item->isHit()) {
            return $item->get();
        }

        $value = $callback($item);
        $item->set($value);
        $this->applyExpiration($item, $ttl);

        if (self::$tagAware && $tags !== [] && \method_exists($item, 'tag')) {
            $item->tag($tags);
        }

        $this->save($item);
        return $value;
    }

    /**
     * @param iterable<int|string, string> $keys
     * @return array<string, mixed>
     */
    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keyList = [];
        foreach ($keys as $k) {
            $keyList[] = (string) $k;
        }

        $items = $this->getItems($keyList);
        $out = [];
        foreach ($keyList as $k) {
            $item = $items[$k] ?? null;
            $out[$k] = ($item instanceof CacheItemInterface && $item->isHit()) ? $item->get() : $default;
        }

        return $out;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    #[\Override]
    public function setMultiple(iterable $values, null|int|DateInterval|Duration $ttl = null): bool
    {
        $pool = $this->ensureAdapter();
        try {
            foreach ($values as $k => $v) {
                $item = $pool->getItem((string) $k);
                $item->set($v);
                $this->applyExpiration($item, $ttl);
                $pool->saveDeferred($item);
            }
            return $pool->commit();
        } catch (Throwable $e) {
            $this->resolveLogger()?->warning('Cache setMultiple failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @param iterable<int|string, string> $keys
     */
    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $keyList = [];
        foreach ($keys as $k) {
            $keyList[] = (string) $k;
        }

        return $this->deleteItems($keyList);
    }

    public function supportsTags(): bool
    {
        $pool = $this->instancePool ?? self::$pool;
        if ($pool === null) {
            return false;
        }

        if ($pool instanceof TagAwareAdapter) {
            return true;
        }

        try {
            $item = $pool->getItem('__cache_wrapper_tag_test__');
            return \method_exists($item, 'tag');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<int, string> $tags
     */
    public function tagItem(string $key, array $tags): bool
    {
        $pool = $this->ensureAdapter();
        try {
            $item = $pool->getItem($key);
            if (!\method_exists($item, 'tag')) {
                $this->resolveLogger()?->debug('Cache item does not support tagging.');
                return false;
            }

            if (self::$tagAware) {
                $item->tag($tags);
            }

            return $pool->save($item);
        } catch (Throwable $e) {
            $this->resolveLogger()?->warning('Cache tagItem failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @param array<int, string> $tags
     */
    public function invalidateTags(array $tags): bool
    {
        $pool = $this->ensureAdapter();
        if ($pool instanceof TagAwareAdapter) {
            try {
                return $pool->invalidateTags($tags);
            } catch (Throwable $e) {
                $this->resolveLogger()?->warning('Cache invalidateTags failed: ' . $e->getMessage());
                return false;
            }
        }

        $this->resolveLogger()?->debug('Cache adapter does not support invalidateTags.');
        return false;
    }

    public function isTagAware(): bool
    {
        return self::$tagAware;
    }

    /**
     * @return array{adapter_set: bool, adapter_class: ?string, supports_tags: bool}
     */
    public function debugState(): array
    {
        $pool = $this->instancePool ?? self::$pool;

        return [
            'adapter_set' => $pool !== null,
            'adapter_class' => $pool !== null ? $pool::class : null,
            'supports_tags' => $this->supportsTags(),
        ];
    }

    public function reset(): void
    {
        self::$pool = null;
        self::$logger = null;
        self::$tagAware = false;
        $this->instancePool = null;
        $this->instanceLogger = null;
    }
}
