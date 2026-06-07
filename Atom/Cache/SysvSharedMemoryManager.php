<?php

declare(strict_types=1);

namespace Atom\Cache;

use Atom\Cache\SysvIpcException;
use Atom\Cache\SysvIpcSupport;
use Atom\Cache\SysvQueueManager;
use Atom\Cache\SysvSemaphoreManager;
use SysvSharedMemory;

/**
 * Final class that manages shared memory operations for Sysv IPC implementation
 *
 * This class provides a comprehensive set of methods for managing shared memory
 * segments in System V IPC environment. It handles key derivation, memory allocation,
 * data storage/retrieval, and cleanup operations while maintaining thread safety
 * through proper locking mechanisms.
 *
 * The manager supports various data types through serialization, TTL-based expiration,
 * and provides both synchronous and asynchronous access patterns. It's designed to be
 * a robust foundation for inter-process communication in PHP applications.
 *
 * Key features include:
 * - Automatic slot management for memory allocation
 * - Time-to-live (TTL) based expiration with automatic cleanup
 * - Thread/process safety through Sysv semaphores
 * - Error handling and exception management
 * - Namespace isolation for multiple IPC instances
 * - Memory efficiency through smart slot reuse
 *
 * @since 2.0.0
 */
final class SysvSharedMemoryManager
{
    private const META_KEY = 1;

    private \SysvSharedMemory $shm;
    private SysvSemaphoreManager $lock;

    /**
     * Initializes a new SysvIpc instance for shared memory operations
     *
     * This constructor sets up the shared memory segment and associated metadata
     * for inter-process communication. It performs necessary validations and
     * initializes the underlying shared memory resources.
     *
     * @param int $baseKey Base key for identifying this shared memory segment
     * @param int $permissions Permissions for creating the shared memory segment (default: 0666)
     * @param int $shmSize Size of the shared memory segment in bytes (default: 1048576 = 1MB)
     * @param int $defaultTtl Default time-to-live for stored items in seconds (default: 3600 = 1 hour)
     * @param string $namespace Namespace identifier for this IPC instance (default: 'sysv-ipc')
     * @throws SysvIpcException if required sysvshm functions are not available or memory allocation fails
     */
    public function __construct(
        private int $baseKey,
        private int $permissions = 0666,
        private int $shmSize = 1048576,
        private int $defaultTtl = 3600,
        private string $namespace = 'sysv-ipc'
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

        $key = SysvIpcSupport::deriveKey($this->baseKey, $this->namespace, 'shm');
        $this->shm = shm_attach($key, $this->shmSize, $this->permissions);

        if (!$this->shm) {
            throw new SysvIpcException('Failed to attach shared memory shm_attach().');
        }

        $this->lock = new SysvSemaphoreManager($this->baseKey, $this->permissions, $this->namespace);
        $this->ensureMeta();
    }

    /**
     * Retrieves the underlying SysvSharedMemory resource
     *
     * This method provides direct access to the SysvSharedMemory object, allowing
     * direct manipulation of the shared memory segment when necessary. It's typically
     * used internally by other methods that need to interact directly with the
     * underlying shared memory mechanism.
     *
     * @return SysvSharedMemory The underlying shared memory resource
     */
    public function getNativeSharedMemory(): SysvSharedMemory
    {
        return $this->shm;
    }

    
    /**
     * Executes a callback function with exclusive access to shared memory
     *
     * This method ensures thread safety by acquiring an exclusive lock before
     * executing the provided callback and releasing it afterward. It can operate
     * in blocking or non-blocking mode depending on the nowait parameter.
     *
     * @param callable $callback The function to execute within the lock
     * @param bool $nowait If true, don't block when acquiring the lock
     * @return mixed The result of the callback function
     */
    public function withLock(callable $callback, bool $nowait = false): mixed
    {
        return $this->lock->withLock($callback, $nowait);
    }

    /**
     * Retrieves a value from shared memory by key with default fallback
     *
     * This method retrieves the value associated with a key from shared memory.
     * It performs comprehensive validation including checking if the key exists,
     * hasn't expired, and the underlying data is valid. If any of these checks fail,
     * it cleans up the invalid entry and returns the default value.
     *
     * @param string $key The key to retrieve value for
     * @param mixed $default The default value to return if key doesn't exist or is invalid
     * @return mixed The value from shared memory or the default value
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->withLock(function () use ($key, $value, $ttl): void {
            $meta = $this->loadMetaUnlocked();
            $this->cleanupExpiredLocked($meta);

            $slot = $this->resolveSlot($key, $meta);
            $now = time();

            $record = [
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => SysvIpcSupport::computeExpiresAt($ttl, $this->defaultTtl),
            ];

            shm_put_var($this->shm, $slot, $record);

            $meta['items'][$key] = [
                'slot' => $slot,
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => $record['expires_at'],
            ];

            $this->saveMetaUnlocked($meta);
        });
    }

    /**
     * Retrieves a value from shared memory by key with default fallback
     *
     * This method retrieves the value associated with a key from shared memory.
     * It performs comprehensive validation including checking if the key exists,
     * hasn't expired, and the underlying data is valid. If any of these checks fail,
     * it cleans up the invalid entry and returns the default value.
     *
     * @param string $key The key to retrieve value for
     * @param mixed $default The default value to return if key doesn't exist or is invalid
     * @return mixed The value from shared memory or the default value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->withLock(function () use ($key, $default): mixed {
            $meta = $this->loadMetaUnlocked();

            if (!isset($meta['items'][$key])) {
                return $default;
            }

            $info = $meta['items'][$key];
            if (SysvIpcSupport::isExpired($info['expires_at'] ?? null)) {
                $this->deleteUnlocked($key, $meta);
                $this->saveMetaUnlocked($meta);
                return $default;
            }

            $slot = (int)($info['slot'] ?? 0);
            if ($slot <= 0 || !shm_has_var($this->shm, $slot)) {
                unset($meta['items'][$key]);
                $this->saveMetaUnlocked($meta);
                return $default;
            }

            $record = shm_get_var($this->shm, $slot);
            if (!is_array($record) || SysvIpcSupport::isExpired($record['expires_at'] ?? null)) {
                $this->deleteUnlocked($key, $meta);
                $this->saveMetaUnlocked($meta);
                return $default;
            }

            return $record['value'] ?? $default;
        });
    }

    /**
     * Checks if a key exists in shared memory and is not expired
     *
     * This method determines whether a key exists in shared memory and has not yet expired.
     * It performs automatic cleanup by removing expired entries during the check.
     * The method is thread-safe and uses locking to ensure consistency.
     *
     * @param string $key The key to check for existence
     * @return bool True if the key exists and is valid, false otherwise
     */
    public function has(string $key): bool
    {
        return $this->withLock(function () use ($key): bool {
            $meta = $this->loadMetaUnlocked();

            if (!isset($meta['items'][$key])) {
                return false;
            }

            if (SysvIpcSupport::isExpired($meta['items'][$key]['expires_at'] ?? null)) {
                $this->deleteUnlocked($key, $meta);
                $this->saveMetaUnlocked($meta);
                return false;
            }

            return true;
        });
    }

    /**
     * Deletes a key from shared memory
     *
     * This method removes a key and its associated value from shared memory.
     * It is thread-safe and uses locking to ensure consistency during the operation.
     * The method properly handles metadata cleanup after deletion.
     *
     * @param string $key The key to delete
     * @return bool True if the key was deleted, false if it didn't exist
     */
    public function delete(string $key): bool
    {
        return $this->withLock(function () use ($key): bool {
            $meta = $this->loadMetaUnlocked();
            $deleted = $this->deleteUnlocked($key, $meta);
            $this->saveMetaUnlocked($meta);
            return $deleted;
        });
    }

    /**
     * Retrieves and removes a key from shared memory
     *
     * This method retrieves the value associated with a key and then deletes the key.
     * It's useful for implementing "get and delete" operations atomically.
     * The method is thread-safe and uses locking to ensure consistency.
     *
     * @param string $key The key to retrieve and remove
     * @param mixed $default Default value to return if key doesn't exist
     * @return mixed The value of the key or default if not found
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        return $this->withLock(function () use ($key, $default): mixed {
            $meta = $this->loadMetaUnlocked();
            $value = $this->getUnlocked($key, $meta, $default);
            $this->deleteUnlocked($key, $meta);
            $this->saveMetaUnlocked($meta);
            return $value;
        });
    }

    /**
     * Updates the expiration time for a key in shared memory
     *
     * This method extends the lifetime of an existing key by updating its expiration time.
     * It refreshes the TTL (time-to-live) for the specified key, pushing back its expiration.
     * If the key doesn't exist or becomes invalid during the operation, it returns false.
     *
     * @param string $key The key to touch (update)
     * @param int|null $ttl Time-to-live in seconds, or null to use default TTL
     * @return bool True if the key was successfully touched, false otherwise
     */
    public function touch(string $key, ?int $ttl = null): bool
    {
        return $this->withLock(function () use ($key, $ttl): bool {
            $meta = $this->loadMetaUnlocked();

            if (!isset($meta['items'][$key])) {
                return false;
            }

            $slot = (int)($meta['items'][$key]['slot'] ?? 0);
            if ($slot <= 0 || !shm_has_var($this->shm, $slot)) {
                unset($meta['items'][$key]);
                $this->saveMetaUnlocked($meta);
                return false;
            }

            $record = shm_get_var($this->shm, $slot);
            if (!\is_array($record)) {
                unset($meta['items'][$key]);
                $this->saveMetaUnlocked($meta);
                return false;
            }

            $now = time();
            $record['updated_at'] = $now;
            $record['expires_at'] = SysvIpcSupport::computeExpiresAt($ttl, $this->defaultTtl);

            shm_put_var($this->shm, $slot, $record);

            $meta['items'][$key]['updated_at'] = $now;
            $meta['items'][$key]['expires_at'] = $record['expires_at'];

            $this->saveMetaUnlocked($meta);
            return true;
        });
    }

    /**
     * Increments a numeric value in shared memory
     *
     * This method increments the value associated with the given key by the specified amount.
     * It ensures thread safety by using locking during the operation. The method validates
     * that the current value is numeric before performing the increment operation.
     *
     * @param string $key The key to increment
     * @param int $by The amount to increment by (default: 1)
     * @param int|null $ttl Time-to-live for the key in seconds
     * @return int The new incremented value
     * @throws SysvIpcException if the current value is not numeric
     */
    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        return (int)$this->withLock(function () use ($key, $by, $ttl): int {
            $current = $this->get($key, 0);

            if (!is_int($current) && !is_float($current) && !is_numeric($current)) {
                throw new SysvIpcException("The value '{$key}' is not a number.");
            }

            $newValue = (int)$current + $by;
            $this->set($key, $newValue, $ttl);
            return $newValue;
        });
    }

    /**
     * Decrements a numeric value in shared memory
     *
     * This method decrements the value associated with the given key by the specified amount.
     * It reuses the increment logic by passing a negative value to decrement.
     *
     * @param string $key The key to decrement
     * @param int $by The amount to decrement by (default: 1)
     * @param int|null $ttl Time-to-live for the key in seconds
     * @return int The new decremented value
     */
    public function decrement(string $key, int $by = 1, ?int $ttl = null): int
    {
        return $this->increment($key, -abs($by), $ttl);
    }

    /**
     * Adds an element to the end of an array in shared memory
     *
     * This method appends a value to the end of an array stored at the specified key.
     * It ensures thread safety by using locking during the operation.
     *
     * @param string $key The key containing the array
     * @param mixed $value The value to add to the array
     * @param int|null $ttl Time-to-live for the key in seconds
     * @return void
     * @throws SysvIpcException if the key doesn't contain an array
     */
    public function push(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->withLock(function () use ($key, $value, $ttl): void {
            $current = $this->get($key, []);

            if (!\is_array($current)) {
                throw new SysvIpcException("The value '{$key}' is not an array.");
            }

            $current[] = $value;
            $this->set($key, $current, $ttl);
        });
    }

    /**
     * Removes and returns the last element from an array in shared memory
     *
     * This method removes and returns the last element of an array stored at the specified key.
     * It ensures thread safety by using locking during the operation.
     *
     * @param string $key The key containing the array
     * @return mixed The removed value or null if array is empty
     */
    public function pop(string $key): mixed
    {
        return $this->withLock(function () use ($key): mixed {
            $current = $this->get($key, []);

            if (!\is_array($current) || $current === []) {
                return null;
            }

            $value = array_pop($current);
            $this->set($key, $current);
            return $value;
        });
    }

    /**
     * Adds an element to the beginning of an array in shared memory
     *
     * This method prepends a value to the beginning of an array stored at the specified key.
     * It ensures thread safety by using locking during the operation.
     *
     * @param string $key The key containing the array
     * @param mixed $value The value to add to the beginning of the array
     * @param int|null $ttl Time-to-live for the key in seconds
     * @return void
     * @throws SysvIpcException if the key doesn't contain an array
     */
    public function unshift(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->withLock(function () use ($key, $value, $ttl): void {
            $current = $this->get($key, []);

            if (!\is_array($current)) {
                throw new SysvIpcException("The value '{$key}' is not an array.");
            }

            array_unshift($current, $value);
            $this->set($key, $current, $ttl);
        });
    }

    /**
     * Removes and returns the first element from an array in shared memory
     *
     * This method removes and returns the first element of an array stored at the specified key.
     * It ensures thread safety by using locking during the operation.
     *
     * @param string $key The key containing the array
     * @return mixed The removed value or null if array is empty
     */
    public function shift(string $key): mixed
    {
        return $this->withLock(function () use ($key): mixed {
            $current = $this->get($key, []);

            if (!\is_array($current) || $current === []) {
                return null;
            }

            $value = array_shift($current);
            $this->set($key, $current);
            return $value;
        });
    }

    /**
     * Retrieves a value from shared memory or sets it using a callback if not present
     *
     * This method checks if a key exists in shared memory and returns its value if found.
     * If the key doesn't exist, it executes the provided callback to generate a value,
     * stores it in shared memory, and returns it. It ensures thread safety by using locking.
     *
     * @param string $key The key to check or set
     * @param callable $callback Callback that generates a value if key is not found
     * @param int|null $ttl Time-to-live for the key in seconds
     * @return mixed The value from shared memory or the result of the callback
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        return $this->withLock(function () use ($key, $callback, $ttl): mixed {
            $meta = $this->loadMetaUnlocked();
            $value = $this->getUnlocked($key, $meta, null);

            if ($this->existsUnlocked($key, $meta)) {
                return $value;
            }

            $value = $callback($this);
            $this->set($key, $value, $ttl);
            return $value;
        });
    }

    /**
     * Retrieves all key-value pairs from shared memory
     *
     * This method returns an associative array of all key-value pairs stored in shared memory.
     * It also cleans up expired entries during the process and ensures thread safety.
     *
     * @return array All key-value pairs in shared memory
     */
    public function all(): array
    {
        return $this->withLock(function (): array {
            $meta = $this->loadMetaUnlocked();
            $this->cleanupExpiredLocked($meta);

            $out = [];
            foreach ($meta['items'] ?? [] as $key => $info) {
                $slot = (int)($info['slot'] ?? 0);
                if ($slot <= 0 || !shm_has_var($this->shm, $slot)) {
                    continue;
                }

                $record = shm_get_var($this->shm, $slot);
                if (\is_array($record) && !SysvIpcSupport::isExpired($record['expires_at'] ?? null)) {
                    $out[$key] = $record['value'] ?? null;
                }
            }

            $this->saveMetaUnlocked($meta);
            return $out;
        });
    }

    /**
     * Returns all keys from the storage
     *
     * This method retrieves all keys currently stored in the system by getting
     * all available keys from the underlying data structure.
     *
     * @return array Array of all keys
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * Counts the number of items in storage
     *
     * This method returns the total count of items by counting the keys
     * available in the storage system.
     *
     * @return int Number of items in storage
     */
    public function count(): int
    {
        return \count($this->keys());
    }

    /**
     * Clears all expired entries from shared memory
     *
     * This method removes all expired entries from shared memory and returns
     * the count of how many entries were removed. It uses a locking mechanism
     * to ensure thread safety during the operation.
     *
     * @return int Number of expired entries removed
     */
    public function clearExpired(): int
    {
        return $this->withLock(function (): int {
            $meta = $this->loadMetaUnlocked();
            $removed = $this->cleanupExpiredLocked($meta);
            $this->saveMetaUnlocked($meta);
            return $removed;
        });
    }

    /**
     * Clears all entries from shared memory
     *
     * This method removes all entries from shared memory and clears the metadata.
     * It ensures thread safety by using a locking mechanism during the operation.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->withLock(function (): void {
            $meta = $this->loadMetaUnlocked();

            foreach (($meta['items'] ?? []) as $info) {
                $slot = (int)($info['slot'] ?? 0);
                if ($slot > 0 && shm_has_var($this->shm, $slot)) {
                    @shm_remove_var($this->shm, $slot);
                }
            }

            $meta['items'] = [];
            $this->saveMetaUnlocked($meta);
        });
    }

    /**
     * Destroys the shared memory segment
     *
     * This method clears all data from shared memory and removes the shared memory
     * segment from the system. It also detaches from the memory segment.
     *
     * @return void
     */
    public function destroy(): void
    {
        $this->clear();
        @shm_remove($this->shm);
        $this->detach();
    }

    /**
     * Detaches from the shared memory segment
     *
     * This method detaches the current process from the shared memory segment.
     * It should be called when no longer using the shared memory.
     *
     * @return void
     */
    public function detach(): void
    {
        @shm_detach($this->shm);
    }

    /**
     * Ensures that metadata exists in shared memory
     *
     * This private method checks if metadata exists in shared memory and creates
     * it with default values if it doesn't exist yet.
     *
     * @return void
     */
    private function ensureMeta(): void
    {
        if (!shm_has_var($this->shm, self::META_KEY)) {
            shm_put_var($this->shm, self::META_KEY, [
                'items' => [],
            ]);
        }
    }

    /**
     * Loads metadata from shared memory (unlocked version)
     *
     * This private method retrieves metadata from shared memory. If metadata
     * doesn't exist or is invalid, it returns default empty structure.
     *
     * @return array Metadata loaded from shared memory
     */
    private function loadMetaUnlocked(): array
    {
        if (!shm_has_var($this->shm, self::META_KEY)) {
            return ['items' => []];
        }

        $meta = shm_get_var($this->shm, self::META_KEY);

        if (!is_array($meta)) {
            return ['items' => []];
        }

        $meta['items'] = $meta['items'] ?? [];
        return $meta;
    }

    
    /**
     * Saves metadata to the shared memory
     *
     * This function stores an array of metadata into the system's shared memory
     * using the specified shared memory identifier.
     *
     * @param array $meta Array of metadata to save
     * @return void
     */
    private function saveMetaUnlocked(array $meta): void
    {
        shm_put_var($this->shm, self::META_KEY, $meta);
    }

    /**
     * Cleans up expired entries from shared memory
     *
     * This function iterates through all entries in shared memory and removes
     * expired items. It also cleans up the associated data for those entries.
     *
     * @param array $meta Array of metadata to check
     * @return int Number of expired items removed
     */
    private function cleanupExpiredLocked(array &$meta): int
    {
        $removed = 0;
        $now = time();

        foreach (($meta['items'] ?? []) as $key => $info) {
            $expiresAt = $info['expires_at'] ?? null;

            if ($expiresAt !== null && (int)$expiresAt <= $now) {
                $slot = (int)($info['slot'] ?? 0);
                if ($slot > 0 && shm_has_var($this->shm, $slot)) {
                    @shm_remove_var($this->shm, $slot);
                }

                unset($meta['items'][$key]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Deletes an entry from shared memory
     *
     * This function removes a specified key from shared memory and the associated data.
     * It also updates the metadata array by removing information about this entry.
     *
     * @param string $key Key to delete
     * @param array $meta Metadata array to update
     * @return bool true if operation succeeded, false otherwise
     */
    private function deleteUnlocked(string $key, array &$meta): bool
    {
        if (!isset($meta['items'][$key])) {
            return false;
        }

        $slot = (int)($meta['items'][$key]['slot'] ?? 0);
        if ($slot > 0 && shm_has_var($this->shm, $slot)) {
            @shm_remove_var($this->shm, $slot);
        }

        unset($meta['items'][$key]);
        return true;
    }

    /**
     * Checks if an entry exists in shared memory
     *
     * This function checks whether a specified key exists in shared memory
     * and is not expired.
     *
     * @param string $key Key to check
     * @param array $meta Metadata array
     * @return bool true if entry exists and is not expired, false otherwise
     */
    private function existsUnlocked(string $key, array $meta): bool
    {
        if (!isset($meta['items'][$key])) {
            return false;
        }

        return !SysvIpcSupport::isExpired($meta['items'][$key]['expires_at'] ?? null);
    }

    /**
     * Gets a value from shared memory
     *
     * This function retrieves the value for a given key from shared memory.
     * If the entry is expired or doesn't exist, it may return a default value.
     *
     * @param string $key Key to retrieve
     * @param array $meta Metadata array
     * @param mixed $default Default value to return if entry doesn't exist
     * @return mixed Value for the given key or default value
     */
    private function getUnlocked(string $key, array &$meta, mixed $default = null): mixed
    {
        if (!isset($meta['items'][$key])) {
            return $default;
        }

        $info = $meta['items'][$key];
        if (SysvIpcSupport::isExpired($info['expires_at'] ?? null)) {
            $this->deleteUnlocked($key, $meta);
            return $default;
        }

        $slot = (int)($info['slot'] ?? 0);
        if ($slot <= 0 || !shm_has_var($this->shm, $slot)) {
            $this->deleteUnlocked($key, $meta);
            return $default;
        }

        $record = shm_get_var($this->shm, $slot);
        if (!\is_array($record) || SysvIpcSupport::isExpired($record['expires_at'] ?? null)) {
            $this->deleteUnlocked($key, $meta);
            return $default;
        }

        return $record['value'] ?? $default;
    }

    /**
     * Resolves the shared memory slot for a key
     *
     * This function generates or assigns a shared memory slot number for a given key.
     * It uses collision detection mechanisms to avoid slot conflicts.
     *
     * @param string $key Key to resolve slot for
     * @param array $meta Metadata array containing information about existing entries
     * @return int Shared memory slot number
     */
    private function resolveSlot(string $key, array $meta): int
    {
        if (isset($meta['items'][$key]['slot'])) {
            return (int)$meta['items'][$key]['slot'];
        }

        $slot = max(2, SysvIpcSupport::deriveKey($this->baseKey, $this->namespace, 'slot|' . $key));

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
                $slot = 2;
            }
        }

        return $slot;
    }
}
