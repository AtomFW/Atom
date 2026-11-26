<?php

declare(strict_types=1);

namespace Atom\Cache;

use SysvSharedMemory;
use Time\Duration;

/**
 * Shared memory manager using System V IPC and semaphore locking.
 */
final class SysvSharedMemoryManager
{
    private const int META_KEY = 1;

    private SysvSharedMemory $shm;
    private SysvSemaphoreManager $lock;
    private int $defaultTtlSeconds;

    public function __construct(
        private readonly int $baseKey,
        private readonly int $permissions = 0666,
        private readonly int $shmSize = 1_048_576,
        int|Duration $defaultTtl = 3600,
        private readonly string $namespace = 'sysv-ipc',
    ) {
        SysvIpcSupport::requireFunction('shm_attach', 'sysvshm');
        SysvIpcSupport::requireFunction('shm_put_var', 'sysvshm');
        SysvIpcSupport::requireFunction('shm_get_var', 'sysvshm');
        SysvIpcSupport::requireFunction('shm_has_var', 'sysvshm');
        SysvIpcSupport::requireFunction('shm_remove_var', 'sysvshm');
        SysvIpcSupport::requireFunction('shm_detach', 'sysvshm');
        SysvIpcSupport::requireFunction('shm_remove', 'sysvshm');

        if ($this->shmSize <= 0) {
            throw new SysvIpcException('shmSize must be greater than 0.');
        }

        $this->defaultTtlSeconds = $defaultTtl instanceof Duration
            ? ($defaultTtl->negative ? 0 : $defaultTtl->seconds)
            : \clamp($defaultTtl, 0, \PHP_INT_MAX);

        $key = SysvIpcSupport::deriveKey($this->baseKey, $this->namespace, 'shm');
        $shm = \shm_attach($key, $this->shmSize, $this->permissions);

        if ($shm === false) {
            throw new SysvIpcException('Failed to attach shared memory shm_attach().');
        }

        $this->shm = $shm;
        $this->lock = new SysvSemaphoreManager($this->baseKey, $this->permissions, $this->namespace);
        $this->ensureMeta();
    }

    public function getNativeSharedMemory(): SysvSharedMemory
    {
        return $this->shm;
    }

    public function withLock(callable $callback, bool $nowait = false): mixed
    {
        return $this->lock->withLock($callback, $nowait);
    }

    public function set(string $key, mixed $value, int|Duration|null $ttl = null): void
    {
        $this->withLock(function () use ($key, $value, $ttl): void {
            $meta = $this->loadMetaUnlocked();
            [$meta] = $this->cleanupExpiredLocked($meta);
            $this->putUnlocked($key, $value, $ttl, $meta);
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->withLock(function () use ($key, $default): mixed {
            $meta = $this->loadMetaUnlocked();
            [$value, $meta, $dirty] = $this->getUnlocked($key, $meta, $default);
            if ($dirty) {
                $this->saveMetaUnlocked($meta);
            }

            return $value;
        });
    }

    public function has(string $key): bool
    {
        return $this->withLock(function () use ($key): bool {
            $meta = $this->loadMetaUnlocked();

            if (!isset($meta['items'][$key])) {
                return false;
            }

            if (SysvIpcSupport::isExpired($meta['items'][$key]['expires_at'] ?? null)) {
                [, $meta] = $this->deleteUnlocked($key, $meta);
                $this->saveMetaUnlocked($meta);
                return false;
            }

            return true;
        });
    }

    public function delete(string $key): bool
    {
        return $this->withLock(function () use ($key): bool {
            $meta = $this->loadMetaUnlocked();
            [$deleted, $meta] = $this->deleteUnlocked($key, $meta);
            if ($deleted) {
                $this->saveMetaUnlocked($meta);
            }

            return $deleted;
        });
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        return $this->withLock(function () use ($key, $default): mixed {
            $meta = $this->loadMetaUnlocked();
            [$value, $meta] = $this->getUnlocked($key, $meta, $default);
            [, $meta] = $this->deleteUnlocked($key, $meta);
            $this->saveMetaUnlocked($meta);
            return $value;
        });
    }

    public function touch(string $key, int|Duration|null $ttl = null): bool
    {
        return $this->withLock(function () use ($key, $ttl): bool {
            $meta = $this->loadMetaUnlocked();

            if (!isset($meta['items'][$key])) {
                return false;
            }

            $slot = (int) ($meta['items'][$key]['slot'] ?? 0);
            if ($slot <= 0 || !\shm_has_var($this->shm, $slot)) {
                unset($meta['items'][$key]);
                $this->saveMetaUnlocked($meta);
                return false;
            }

            $record = \shm_get_var($this->shm, $slot);
            if (!\is_array($record)) {
                unset($meta['items'][$key]);
                $this->saveMetaUnlocked($meta);
                return false;
            }

            $now = \time();
            $record['updated_at'] = $now;
            $record['expires_at'] = SysvIpcSupport::computeExpiresAt($ttl, $this->defaultTtlSeconds);

            \shm_put_var($this->shm, $slot, $record);

            $meta['items'][$key]['updated_at'] = $now;
            $meta['items'][$key]['expires_at'] = $record['expires_at'];

            $this->saveMetaUnlocked($meta);
            return true;
        });
    }

    public function increment(string $key, int $by = 1, int|Duration|null $ttl = null): int
    {
        return (int) $this->withLock(function () use ($key, $by, $ttl): int {
            $meta = $this->loadMetaUnlocked();
            [$current, $meta] = $this->getUnlocked($key, $meta, 0);

            if (!\is_int($current) && !\is_float($current) && !\is_numeric($current)) {
                throw new SysvIpcException("The value '{$key}' is not a number.");
            }

            $newValue = (int) $current + $by;
            $this->putUnlocked($key, $newValue, $ttl, $meta);
            return $newValue;
        });
    }

    public function decrement(string $key, int $by = 1, int|Duration|null $ttl = null): int
    {
        return $this->increment($key, -\abs($by), $ttl);
    }

    public function push(string $key, mixed $value, int|Duration|null $ttl = null): void
    {
        $this->withLock(function () use ($key, $value, $ttl): void {
            $meta = $this->loadMetaUnlocked();
            [$current, $meta] = $this->getUnlocked($key, $meta, []);

            if (!\is_array($current)) {
                throw new SysvIpcException("The value '{$key}' is not an array.");
            }

            $current[] = $value;
            $this->putUnlocked($key, $current, $ttl, $meta);
        });
    }

    public function pop(string $key): mixed
    {
        return $this->withLock(function () use ($key): mixed {
            $meta = $this->loadMetaUnlocked();
            [$current, $meta] = $this->getUnlocked($key, $meta, []);

            if (!\is_array($current) || $current === []) {
                return null;
            }

            $value = \array_pop($current);
            $this->putUnlocked($key, $current, null, $meta);
            return $value;
        });
    }

    public function unshift(string $key, mixed $value, int|Duration|null $ttl = null): void
    {
        $this->withLock(function () use ($key, $value, $ttl): void {
            $meta = $this->loadMetaUnlocked();
            [$current, $meta] = $this->getUnlocked($key, $meta, []);

            if (!\is_array($current)) {
                throw new SysvIpcException("The value '{$key}' is not an array.");
            }

            \array_unshift($current, $value);
            $this->putUnlocked($key, $current, $ttl, $meta);
        });
    }

    public function shift(string $key): mixed
    {
        return $this->withLock(function () use ($key): mixed {
            $meta = $this->loadMetaUnlocked();
            [$current, $meta] = $this->getUnlocked($key, $meta, []);

            if (!\is_array($current) || $current === []) {
                return null;
            }

            $value = \array_shift($current);
            $this->putUnlocked($key, $current, null, $meta);
            return $value;
        });
    }

    public function remember(string $key, callable $callback, int|Duration|null $ttl = null): mixed
    {
        return $this->withLock(function () use ($key, $callback, $ttl): mixed {
            $meta = $this->loadMetaUnlocked();
            [$value, $meta] = $this->getUnlocked($key, $meta, null);

            if ($this->existsUnlocked($key, $meta)) {
                return $value;
            }

            $computed = $callback($this);
            $this->putUnlocked($key, $computed, $ttl, $meta);
            return $computed;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->withLock(function (): array {
            $meta = $this->loadMetaUnlocked();
            [$meta] = $this->cleanupExpiredLocked($meta);

            $out = [];
            foreach ($meta['items'] ?? [] as $key => $info) {
                $slot = (int) ($info['slot'] ?? 0);
                if ($slot <= 0 || !\shm_has_var($this->shm, $slot)) {
                    continue;
                }

                $record = \shm_get_var($this->shm, $slot);
                if (\is_array($record) && !SysvIpcSupport::isExpired($record['expires_at'] ?? null)) {
                    $out[(string) $key] = $record['value'] ?? null;
                }
            }

            $this->saveMetaUnlocked($meta);
            return $out;
        });
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return \array_keys($this->all());
    }

    public function count(): int
    {
        return \count($this->keys());
    }

    public function clearExpired(): int
    {
        return $this->withLock(function (): int {
            $meta = $this->loadMetaUnlocked();
            [$meta, $removed] = $this->cleanupExpiredLocked($meta);
            $this->saveMetaUnlocked($meta);
            return $removed;
        });
    }

    public function clear(): void
    {
        $this->withLock(function (): void {
            $meta = $this->loadMetaUnlocked();

            foreach ($meta['items'] as $info) {
                $slot = (int) ($info['slot'] ?? 0);
                if ($slot > 0 && \shm_has_var($this->shm, $slot)) {
                    SysvIpcSupport::callQuietly(fn(): bool => \shm_remove_var($this->shm, $slot));
                }
            }

            $meta['items'] = [];
            $this->saveMetaUnlocked($meta);
        });
    }

    public function destroy(): void
    {
        $this->clear();
        SysvIpcSupport::callQuietly(fn(): bool => \shm_remove($this->shm));
        $this->detach();
    }

    public function detach(): void
    {
        SysvIpcSupport::callQuietly(fn(): bool => \shm_detach($this->shm));
    }

    private function ensureMeta(): void
    {
        if (!\shm_has_var($this->shm, self::META_KEY)) {
            \shm_put_var($this->shm, self::META_KEY, ['items' => []]);
        }
    }

    /**
     * @return array{items: array<string, array<string, mixed>>}
     */
    private function loadMetaUnlocked(): array
    {
        if (!\shm_has_var($this->shm, self::META_KEY)) {
            return ['items' => []];
        }

        $meta = \shm_get_var($this->shm, self::META_KEY);
        if (!\is_array($meta) || !isset($meta['items']) || !\is_array($meta['items'])) {
            return ['items' => []];
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function saveMetaUnlocked(array $meta): void
    {
        \shm_put_var($this->shm, self::META_KEY, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function putUnlocked(string $key, mixed $value, int|Duration|null $ttl, array $meta): void
    {
        $slot = $this->resolveSlot($key, $meta);
        $now = \time();

        $record = [
            'key' => $key,
            'value' => $value,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => SysvIpcSupport::computeExpiresAt($ttl, $this->defaultTtlSeconds),
        ];

        \shm_put_var($this->shm, $slot, $record);

        $meta['items'][$key] = [
            'slot' => $slot,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $record['expires_at'],
        ];

        $this->saveMetaUnlocked($meta);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function cleanupExpiredLocked(array $meta): array
    {
        $removed = 0;
        $now = \time();

        foreach (($meta['items'] ?? []) as $key => $info) {
            $expiresAt = $info['expires_at'] ?? null;
            if ($expiresAt !== null && (int) $expiresAt <= $now) {
                $slot = (int) ($info['slot'] ?? 0);
                if ($slot > 0 && \shm_has_var($this->shm, $slot)) {
                    SysvIpcSupport::callQuietly(fn(): bool => \shm_remove_var($this->shm, $slot));
                }

                unset($meta['items'][$key]);
                $removed++;
            }
        }

        return [$meta, $removed];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{0: bool, 1: array<string, mixed>}
     */
    private function deleteUnlocked(string $key, array $meta): array
    {
        if (!isset($meta['items'][$key])) {
            return [false, $meta];
        }

        $slot = (int) ($meta['items'][$key]['slot'] ?? 0);
        if ($slot > 0 && \shm_has_var($this->shm, $slot)) {
            SysvIpcSupport::callQuietly(fn(): bool => \shm_remove_var($this->shm, $slot));
        }

        unset($meta['items'][$key]);
        return [true, $meta];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function existsUnlocked(string $key, array $meta): bool
    {
        if (!isset($meta['items'][$key])) {
            return false;
        }

        return !SysvIpcSupport::isExpired($meta['items'][$key]['expires_at'] ?? null);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{0: mixed, 1: array<string, mixed>, 2: bool}
     */
    private function getUnlocked(string $key, array $meta, mixed $default = null): array
    {
        if (!isset($meta['items'][$key])) {
            return [$default, $meta, false];
        }

        $info = $meta['items'][$key];
        if (SysvIpcSupport::isExpired($info['expires_at'] ?? null)) {
            [, $meta] = $this->deleteUnlocked($key, $meta);
            return [$default, $meta, true];
        }

        $slot = (int) ($info['slot'] ?? 0);
        if ($slot <= 0 || !\shm_has_var($this->shm, $slot)) {
            [, $meta] = $this->deleteUnlocked($key, $meta);
            return [$default, $meta, true];
        }

        $record = \shm_get_var($this->shm, $slot);
        if (!\is_array($record) || SysvIpcSupport::isExpired($record['expires_at'] ?? null)) {
            [, $meta] = $this->deleteUnlocked($key, $meta);
            return [$default, $meta, true];
        }

        return [$record['value'] ?? $default, $meta, false];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolveSlot(string $key, array $meta): int
    {
        if (isset($meta['items'][$key]['slot'])) {
            return (int) $meta['items'][$key]['slot'];
        }

        $slot = \clamp(SysvIpcSupport::deriveKey($this->baseKey, $this->namespace, 'slot|' . $key), 2, 0x7fffffff);

        $used = [];
        foreach (($meta['items'] ?? []) as $existingKey => $info) {
            if ($existingKey === $key) {
                continue;
            }
            $used[(int) ($info['slot'] ?? 0)] = true;
        }

        while (isset($used[$slot])) {
            $slot++;
            if ($slot > 0x7fffffff) {
                $slot = 2;
            }
        }

        return $slot;
    }
}
