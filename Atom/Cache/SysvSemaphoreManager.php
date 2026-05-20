<?php

declare(strict_types=1);

namespace Atom\Cache;

use Atom\Cache\SysvIpcException;
use Atom\Cache\SysvIpcSupport;
use Atom\Cache\SysvQueueManager;
use Atom\Cache\SysvSharedMemoryManager;
use SysvSemaphore;

/**
 * System V IPC (Inter-Process Communication) semaphore manager
 *
 * This class provides a wrapper around System V semaphore operations to enable
 * synchronization between multiple processes. It's used to implement critical
 * sections and mutual exclusion in shared memory operations.
 *
 * The semaphore is created using the SysvIpcSupport::deriveKey() function to
 * generate a unique key based on the base key, namespace, and a component name.
 * This ensures that different semaphore instances don't interfere with each other.
 *
 * @internal This class is part of the internal SysvIpc implementation
 * @see SysvIpc
 */
final class SysvSemaphoreManager
{
    private SysvSemaphore $semaphore;

    /**
     * Creates a new System V semaphore for process synchronization
     *
     * This constructor initializes a System V semaphore that can be used to
     * coordinate access to shared resources between multiple processes. The
     * semaphore is created with a unique key derived from the provided base key,
     * namespace, and component name.
     *
     * @param int $baseKey Base key for generating the semaphore identifier
     * @param int $permissions Permissions for the created semaphore (default: 0666)
     * @param string $namespace Namespace to prevent key collisions (default: 'sysv-ipc')
     *
     * @throws SysvIpcException If semaphore creation fails
     */
    public function __construct(
        private int $baseKey,
        private int $permissions = 0666,
        private string $namespace = 'sysv-ipc'
    ) {
        SysvIpcSupport::requireFunction('sem_get', 'sysvsem');

        $key = SysvIpcSupport::deriveKey($this->baseKey, $this->namespace, 'semaphore');
        $this->semaphore = sem_get($key, 1, $this->permissions, true);

        if (!$this->semaphore) {
            throw new SysvIpcException('Failed to create/semaphore sem_get().');
        }
    }

    /**
     * Returns the native System V semaphore resource
     *
     * This method provides access to the underlying semaphore resource for
     * direct system calls or debugging purposes.
     *
     * @return SysvSemaphore The native semaphore resource
     */
    public function getNativeSemaphore(): SysvSemaphore
    {
        return $this->semaphore;
    }

    /**
     * Acquires a lock on the semaphore (blocking)
     *
     * This method blocks until the semaphore can be acquired. It's used to
     * implement mutual exclusion between processes trying to access shared resources.
     *
     * @param bool $nowait If true, returns immediately if semaphore is unavailable
     * @return bool True if lock was acquired, false otherwise
     */
    public function acquire(bool $nowait = false): bool
    {
        SysvIpcSupport::requireFunction('sem_acquire', 'sysvsem');
        return sem_acquire($this->semaphore, $nowait);
    }

    /**
     * Releases the semaphore lock
     *
     * This method releases a previously acquired semaphore, making it available
     * for other processes to acquire. It's typically called after completing
     * critical section code.
     *
     * @return bool True if unlock was successful, false otherwise
     */
    public function release(): bool
    {
        SysvIpcSupport::requireFunction('sem_release', 'sysvsem');
        return sem_release($this->semaphore);
    }

    /**
     * Executes a callback with exclusive access to the semaphore
     *
     * This method acquires the semaphore, executes the provided callback, and
     * ensures proper release of the semaphore even if an exception occurs.
     *
     * @param callable $callback Function to execute with exclusive semaphore access
     * @param bool $nowait If true, won't block when acquiring the semaphore
     * @return mixed Return value from the callback function
     * @throws SysvIpcException If semaphore acquisition fails
     */
    public function withLock(callable $callback, bool $nowait = false): mixed
    {
        if (!$this->acquire($nowait)) {
            throw new SysvIpcException('Failed to capture the semaphore.');
        }

        try {
            return $callback($this);
        } finally {
            $this->release();
        }
    }

    /**
     * Checks if the semaphore is currently available (non-blocking)
     *
     * This method attempts to acquire the semaphore without blocking. If successful,
         * it immediately releases the semaphore and returns true. If unsuccessful,
     * it returns false.
     *
     * @return bool True if semaphore is available, false otherwise
     */
    public function isAvailable(): bool
    {
        if ($this->acquire(true)) {
            $this->release();
            return true;
        }

        return false;
    }

    /**
     * Removes the semaphore from system resources
     *
     * This method removes the semaphore from the system and frees its resources.
     * It should only be called when you're certain no other processes are using it.
     *
     * @return bool True if removal was successful, false otherwise
     */
    public function remove(): bool
    {
        SysvIpcSupport::requireFunction('sem_remove', 'sysvsem');
        return \sem_remove($this->semaphore);
    }
}
