<?php
declare(strict_types=1);

namespace Atom\Cache;

use \SysvSemaphore;
use \SysvSharedMemory;
use \SysvMessageQueue;
use \SysvMessage;
use \SysvMessageInfo;

/**
 * SysV IPC manager for:
 * - System V Message Queue (sysvmsg)
 * - System V Semaphore (sysvsem)
 * - System V Shared Memory (sysvshm)
 *
 * Features:
 * - TTL for shared-memory records
 * - TTL for queue messages (best-effort cleanup)
 * - useful cache-like methods
 * - semaphore-based exclusive access
 * - cooperative lease locks with TTL
 * - cleanup / destroy helpers
 */
final class SysVManager
{
    private const META_KEY = 1;

    private \SysvMessageQueue $queue;
    private \SysvSemaphore $semaphore;
    private \SysvSharedMemory $shm;

    public function __construct(
        private int $baseKey,
        private int $permissions = 0666,
        private int $shmSize = 1048576,
        private int $defaultTtl = 3600,
        private string $namespace = 'sysv-ipc'
    ) {
        if ($this->shmSize <= 0) {
            throw new InvalidArgumentException('shmSize must be greater than 0.');
        }

        $this->queue = \msg_get_queue($this->deriveKey('queue'), $this->permissions, true);
        $this->semaphore = \sem_get($this->deriveKey('semaphore'), 1, $this->permissions, true);
        $this->shm = \shm_attach($this->deriveKey('shm'), $this->shmSize, $this->permissions);

        $this->ensureMeta();
    }

    public function __destruct()
    {
        $this->detach();
    }

    /* -------------------------------------------------------------
     * Accessors
     * ------------------------------------------------------------- */

    public function getQueue(): SysvMessageQueue
    {
        return $this->queue;
    }

    public function getSemaphore(): SysvSemaphore
    {
        return $this->semaphore;
    }

    public function getSharedMemory(): SysvSharedMemory
    {
        return $this->shm;
    }

    /* -------------------------------------------------------------
     * Semaphore helpers
     * ------------------------------------------------------------- */

    public function acquireSemaphore(int $timeoutMs = 0, int $retryDelayUs = 10_000): bool
    {
        if ($timeoutMs <= 0) {
            return sem_acquire($this->semaphore, false);
        }

        $deadline = microtime(true) + ($timeoutMs / 1000);

        do {
            if (sem_acquire($this->semaphore, true)) {
                return true;
            }

            usleep($retryDelayUs);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function releaseSemaphore(): bool
    {
        return sem_release($this->semaphore);
    }

    public function withSemaphore(callable $callback, int $timeoutMs = 0, int $retryDelayUs = 10_000): mixed
    {
        if (!$this->acquireSemaphore($timeoutMs, $retryDelayUs)) {
            throw new RuntimeException('Unable to acquire semaphore.');
        }

        try {
            return $callback($this);
        } finally {
            $this->releaseSemaphore();
        }
    }

    /* -------------------------------------------------------------
     * Shared memory - key/value storage with TTL
     * ------------------------------------------------------------- */

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->withSemaphore(function (): void {
            $meta = $this->loadMetaUnlocked();
            $this->cleanupExpiredLocked($meta);

            $slot = $this->resolveSlot($key, $meta);
            $expiresAt = $this->computeExpiresAt($ttl);

            $record = [
                'key' => $key,
                'value' => $value,
                'created_at' => time(),
                'updated_at' => time(),
                'expires_at' => $expiresAt,
            ];

            shm_put_var($this->shm, $slot, $record);

            $meta['items'][$key] = [
                'slot' => $slot,
                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
                'expires_at' => $record['expires_at'],
            ];

            $this->saveMetaUnlocked($meta);
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $meta = $this->loadMetaUnlocked();

        if (!isset($meta['items'][$key])) {
            return $default;
        }

        $info = $meta['items'][$key];
        if ($this->isExpiredAt($info['expires_at'] ?? null)) {
            $this->delete($key);
            return $default;
        }

        $slot = (int)($info['slot'] ?? 0);
        if ($slot <= 0 || !shm_var_exists($this->shm, $slot)) {
            return $default;
        }

        $record = shm_get_var($this->shm, $slot);
        if (!is_array($record) || $this->isExpiredAt($record['expires_at'] ?? null)) {
            $this->delete($key);
            return $default;
        }

        return $record['value'] ?? $default;
    }

    public function has(string $key): bool
    {
        return $this->get($key, new stdClass()) !== new stdClass();
    }

    public function delete(string $key): bool
    {
        return $this->withSemaphore(function () use ($key): bool {
            $meta = $this->loadMetaUnlocked();

            if (!isset($meta['items'][$key])) {
                return false;
            }

            $slot = (int)($meta['items'][$key]['slot'] ?? 0);
            if ($slot > 0 && shm_var_exists($this->shm, $slot)) {
                @shm_remove_var($this->shm, $slot);
            }

            unset($meta['items'][$key]);
            $this->saveMetaUnlocked($meta);

            return true;
        });
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->delete($key);
        return $value;
    }

    public function touch(string $key, ?int $ttl = null): bool
    {
        return $this->withSemaphore(function () use ($key, $ttl): bool {
            $meta = $this->loadMetaUnlocked();

            if (!isset($meta['items'][$key])) {
                return false;
            }

            $slot = (int)($meta['items'][$key]['slot'] ?? 0);
            if ($slot <= 0 || !shm_var_exists($this->shm, $slot)) {
                unset($meta['items'][$key]);
                $this->saveMetaUnlocked($meta);
                return false;
            }

            $record = shm_get_var($this->shm, $slot);
            if (!is_array($record)) {
                unset($meta['items'][$key]);
                $this->saveMetaUnlocked($meta);
                return false;
            }

            $record['updated_at'] = time();
            $record['expires_at'] = $this->computeExpiresAt($ttl);

            shm_put_var($this->shm, $slot, $record);
            $meta['items'][$key]['updated_at'] = $record['updated_at'];
            $meta['items'][$key]['expires_at'] = $record['expires_at'];

            $this->saveMetaUnlocked($meta);
            return true;
        });
    }

    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        return (int)$this->withSemaphore(function () use ($key, $by, $ttl): int {
            $current = $this->get($key, 0);
            if (!is_int($current) && !is_float($current) && !is_numeric($current)) {
                throw new RuntimeException("Value under '{$key}' is not numeric.");
            }

            $new = (int)$current + $by;
            $this->set($key, $new, $ttl);
            return $new;
        });
    }

    public function decrement(string $key, int $by = 1, ?int $ttl = null): int
    {
        return $this->increment($key, -abs($by), $ttl);
    }

    public function push(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->withSemaphore(function () use ($key, $value, $ttl): void {
            $current = $this->get($key, []);
            if (!is_array($current)) {
                throw new RuntimeException("Value under '{$key}' is not an array.");
            }

            $current[] = $value;
            $this->set($key, $current, $ttl);
        });
    }

    public function pop(string $key): mixed
    {
        return $this->withSemaphore(function () use ($key): mixed {
            $current = $this->get($key, []);
            if (!is_array($current) || $current === []) {
                return null;
            }

            $value = array_pop($current);
            $this->set($key, $current);
            return $value;
        });
    }

    public function unshift(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->withSemaphore(function () use ($key, $value, $ttl): void {
            $current = $this->get($key, []);
            if (!is_array($current)) {
                throw new RuntimeException("Value under '{$key}' is not an array.");
            }

            array_unshift($current, $value);
            $this->set($key, $current, $ttl);
        });
    }

    public function shift(string $key): mixed
    {
        return $this->withSemaphore(function () use ($key): mixed {
            $current = $this->get($key, []);
            if (!is_array($current) || $current === []) {
                return null;
            }

            $value = array_shift($current);
            $this->set($key, $current);
            return $value;
        });
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $existing = $this->get($key, null);
        if ($existing !== null || $this->has($key)) {
            return $existing;
        }

        $value = $callback($this);
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function all(): array
    {
        $this->clearExpired();

        $meta = $this->loadMetaUnlocked();
        $out = [];

        foreach ($meta['items'] ?? [] as $key => $info) {
            $slot = (int)($info['slot'] ?? 0);
            if ($slot <= 0 || !shm_var_exists($this->shm, $slot)) {
                continue;
            }

            $record = shm_get_var($this->shm, $slot);
            if (is_array($record) && !$this->isExpiredAt($record['expires_at'] ?? null)) {
                $out[$key] = $record['value'] ?? null;
            }
        }

        return $out;
    }

    public function keys(): array
    {
        $this->clearExpired();
        $meta = $this->loadMetaUnlocked();

        return array_keys($meta['items'] ?? []);
    }

    public function count(): int
    {
        return count($this->keys());
    }

    public function clear(): void
    {
        $this->withSemaphore(function (): void {
            $meta = $this->loadMetaUnlocked();

            foreach (($meta['items'] ?? []) as $key => $info) {
                $slot = (int)($info['slot'] ?? 0);
                if ($slot > 0 && shm_var_exists($this->shm, $slot)) {
                    @shm_remove_var($this->shm, $slot);
                }
                unset($meta['items'][$key]);
            }

            $meta['items'] = [];
            $this->saveMetaUnlocked($meta);
        });
    }

    public function clearExpired(): int
    {
        return $this->withSemaphore(function (): int {
            $meta = $this->loadMetaUnlocked();
            $removed = $this->cleanupExpiredLocked($meta);
            $this->saveMetaUnlocked($meta);
            return $removed;
        });
    }

    /* -------------------------------------------------------------
     * Lease locks with TTL
     * ------------------------------------------------------------- */

    public function acquireLease(string $name = 'default', int $ttl = 30, int $timeoutMs = 0): ?string
    {
        return $this->withSemaphore(function () use ($name, $ttl): ?string {
            $meta = $this->loadMetaUnlocked();
            $this->cleanupExpiredLocked($meta);

            $now = time();
            $locks = $meta['locks'] ?? [];

            if (isset($locks[$name]) && !$this->isExpiredAt($locks[$name]['expires_at'] ?? null)) {
                return null;
            }

            $token = bin2hex(random_bytes(16));
            $locks[$name] = [
                'token' => $token,
                'created_at' => $now,
                'expires_at' => $ttl > 0 ? ($now + $ttl) : null,
                'pid' => getmypid(),
            ];

            $meta['locks'] = $locks;
            $this->saveMetaUnlocked($meta);

            return $token;
        }, $timeoutMs);
    }

    public function releaseLease(string $name = 'default', ?string $token = null, bool $force = false): bool
    {
        return $this->withSemaphore(function () use ($name, $token, $force): bool {
            $meta = $this->loadMetaUnlocked();

            if (!isset($meta['locks'][$name])) {
                return false;
            }

            $lock = $meta['locks'][$name];

            if (!$force && $token !== null && (($lock['token'] ?? null) !== $token)) {
                return false;
            }

            if (!$force && $token === null && !$this->isExpiredAt($lock['expires_at'] ?? null)) {
                return false;
            }

            unset($meta['locks'][$name]);
            $this->saveMetaUnlocked($meta);

            return true;
        });
    }

    public function renewLease(string $name, string $token, ?int $ttl = null): bool
    {
        return $this->withSemaphore(function () use ($name, $token, $ttl): bool {
            $meta = $this->loadMetaUnlocked();

            if (!isset($meta['locks'][$name])) {
                return false;
            }

            if (($meta['locks'][$name]['token'] ?? null) !== $token) {
                return false;
            }

            $meta['locks'][$name]['expires_at'] = $this->computeExpiresAt($ttl);
            $this->saveMetaUnlocked($meta);

            return true;
        });
    }

    public function isLeaseActive(string $name = 'default'): bool
    {
        $meta = $this->loadMetaUnlocked();

        if (!isset($meta['locks'][$name])) {
            return false;
        }

        return !$this->isExpiredAt($meta['locks'][$name]['expires_at'] ?? null);
    }

    public function getLeaseInfo(string $name = 'default'): ?array
    {
        $meta = $this->loadMetaUnlocked();

        if (!isset($meta['locks'][$name])) {
            return null;
        }

        $lock = $meta['locks'][$name];
        if ($this->isExpiredAt($lock['expires_at'] ?? null)) {
            return null;
        }

        return $lock;
    }

    /* -------------------------------------------------------------
     * Message queue - envelope with TTL
     * ------------------------------------------------------------- */

    public function sendMessage(
        mixed $payload,
        int $messageType = 1,
        ?int $ttl = null,
        bool $blocking = true
    ): bool {
        $envelope = [
            'id' => bin2hex(random_bytes(16)),
            'payload' => $payload,
            'created_at' => time(),
            'expires_at' => $this->computeExpiresAt($ttl),
        ];

        $errorCode = 0;
        return msg_send($this->queue, $messageType, $envelope, true, $blocking, $errorCode);
    }

    public function sendRawMessage(
        int $messageType,
        string $message,
        bool $blocking = true
    ): bool {
        $errorCode = 0;
        return msg_send($this->queue, $messageType, $message, false, $blocking, $errorCode);
    }

    public function receiveMessage(
        int $desiredType = 0,
        int $flags = MSG_IPC_NOWAIT,
        int $maxMessageSize = 8192,
        bool $discardExpired = true
    ): ?array {
        while (true) {
            $receivedType = 0;
            $message = null;
            $ok = msg_receive(
                $this->queue,
                $desiredType,
                $receivedType,
                $maxMessageSize,
                $message,
                true,
                $flags,
                $errorCode
            );

            if (!$ok) {
                return null;
            }

            if ($discardExpired && is_array($message) && $this->isExpiredAt($message['expires_at'] ?? null)) {
                continue;
            }

            return [
                'type' => $receivedType,
                'message' => is_array($message) ? ($message['payload'] ?? $message) : $message,
                'raw' => $message,
            ];
        }
    }

    public function receiveRawMessage(
        int $desiredType = 0,
        int $flags = MSG_IPC_NOWAIT,
        int $maxMessageSize = 8192
    ): ?array {
        $receivedType = 0;
        $message = null;

        $ok = msg_receive(
            $this->queue,
            $desiredType,
            $receivedType,
            $maxMessageSize,
            $message,
            false,
            $flags,
            $errorCode
        );

        if (!$ok) {
            return null;
        }

        return [
            'type' => $receivedType,
            'message' => $message,
        ];
    }

    public function queueStats(): array
    {
        return msg_stat_queue($this->queue) ?: [];
    }

    /**
     * Best-effort purge:
     * drains the queue, filters expired envelopes and re-sends live ones.
     */
    public function purgeExpiredMessages(int $maxMessageSize = 8192): int
    {
        $drained = [];
        $purged = 0;

        while (true) {
            $receivedType = 0;
            $message = null;

            $ok = @msg_receive(
                $this->queue,
                0,
                $receivedType,
                $maxMessageSize,
                $message,
                true,
                MSG_IPC_NOWAIT,
                $errorCode
            );

            if (!$ok) {
                break;
            }

            if (is_array($message) && $this->isExpiredAt($message['expires_at'] ?? null)) {
                $purged++;
                continue;
            }

            $drained[] = [$receivedType, $message];
        }

        foreach ($drained as [$type, $message]) {
            @msg_send($this->queue, $type, $message, true, true, $errorCode);
        }

        return $purged;
    }

    public function removeQueue(): bool
    {
        return msg_remove_queue($this->queue);
    }

    /* -------------------------------------------------------------
     * Cleanup / destroy
     * ------------------------------------------------------------- */

    public function detach(): void
    {
        @shm_detach($this->shm);
    }

    public function flush(): void
    {
        $this->clear();
        $this->withSemaphore(function (): void {
            $meta = $this->loadMetaUnlocked();
            $meta['locks'] = [];
            $this->saveMetaUnlocked($meta);
        });
    }

    /**
     * Removes queue, semaphore and shared memory.
     * Use with care: this destroys the IPC resources for all processes.
     */
    public function destroy(): void
    {
        $this->flush();

        @msg_remove_queue($this->queue);
        @sem_remove($this->semaphore);
        @shm_remove($this->shm);
        $this->detach();
    }

    /* -------------------------------------------------------------
     * Internal helpers
     * ------------------------------------------------------------- */

    private function deriveKey(string $suffix): int
    {
        $hash = crc32($this->namespace . '|' . $this->baseKey . '|' . $suffix);
        return $hash & 0x7fffffff;
    }

    private function ensureMeta(): void
    {
        if (!shm_var_exists($this->shm, self::META_KEY)) {
            $this->saveMetaUnlocked([
                'items' => [],
                'locks' => [],
            ]);
        }
    }

    private function loadMetaUnlocked(): array
    {
        if (!shm_var_exists($this->shm, self::META_KEY)) {
            return [
                'items' => [],
                'locks' => [],
            ];
        }

        $meta = shm_get_var($this->shm, self::META_KEY);
        if (!is_array($meta)) {
            $meta = [
                'items' => [],
                'locks' => [],
            ];
        }

        $meta['items'] = $meta['items'] ?? [];
        $meta['locks'] = $meta['locks'] ?? [];

        return $meta;
    }

    private function saveMetaUnlocked(array $meta): void
    {
        shm_put_var($this->shm, self::META_KEY, $meta);
    }

    private function computeExpiresAt(?int $ttl): ?int
    {
        $effective = $ttl ?? $this->defaultTtl;

        if ($effective <= 0) {
            return null;
        }

        return time() + $effective;
    }

    private function isExpiredAt(null|int|float|string $expiresAt): bool
    {
        if ($expiresAt === null || $expiresAt === '' || $expiresAt === 0) {
            return false;
        }

        return (int)$expiresAt <= time();
    }

    private function cleanupExpiredLocked(array &$meta): int
    {
        $removed = 0;
        $now = time();

        foreach (($meta['items'] ?? []) as $key => $info) {
            $expiresAt = $info['expires_at'] ?? null;
            if ($expiresAt !== null && (int)$expiresAt <= $now) {
                $slot = (int)($info['slot'] ?? 0);
                if ($slot > 0 && shm_var_exists($this->shm, $slot)) {
                    @shm_remove_var($this->shm, $slot);
                }

                unset($meta['items'][$key]);
                $removed++;
            }
        }

        foreach (($meta['locks'] ?? []) as $name => $lock) {
            $expiresAt = $lock['expires_at'] ?? null;
            if ($expiresAt !== null && (int)$expiresAt <= $now) {
                unset($meta['locks'][$name]);
                $removed++;
            }
        }

        return $removed;
    }

    private function resolveSlot(string $key, array $meta): int
    {
        if (isset($meta['items'][$key]['slot'])) {
            return (int)$meta['items'][$key]['slot'];
        }

        $slot = $this->baseSlot($key);

        $used = [];
        foreach (($meta['items'] ?? []) as $existingKey => $info) {
            if ($existingKey === $key) {
                continue;
            }
            $used[(int)($info['slot'] ?? 0)] = true;
        }

        while (isset($used[$slot])) {
            $slot++;
            if ($slot > 0x7fffffff) {
                $slot = 1;
            }
        }

        return $slot;
    }

    private function baseSlot(string $key): int
    {
        $hash = crc32($this->namespace . '|' . $this->baseKey . '|slot|' . $key);
        $slot = $hash & 0x7fffffff;
        return max(2, $slot);
    }
}
