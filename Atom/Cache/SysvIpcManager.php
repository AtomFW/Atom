<?php

declare(strict_types=1);

namespace Atom\Cache;

use Atom\Cache\SysvIpcException;
use Atom\Cache\SysvIpcSupport;
use Atom\Cache\SysvQueueManager;
use Atom\Cache\SysvSemaphoreManager;
use Atom\Cache\SysvSharedMemoryManager;

/**
 * System V IPC Manager Factory
 *
 * This class serves as a factory for creating different types of System V IPC managers.
 * It provides a centralized configuration point for System V IPC operations with
 * default values that can be overridden through constructor parameters.
 *
 * The factory pattern allows for easy instantiation of:
 * - Queue managers (SysvQueueManager)
 * - Semaphore managers (SysvSemaphoreManager)
 * - Shared memory managers (SysvSharedMemoryManager)
 *
 * Each manager inherits the configuration from this factory, ensuring consistent
 * behavior across different IPC components while allowing for type-specific
 * operations.
 *
 * @internal This class is part of the internal SysvIpc implementation
 */
final class SysvIpcManager
{
    /**
     * Creates a new System V IPC manager instance with specified parameters
     *
     * This constructor initializes the factory with configuration values that will be
     * passed to all created IPC managers. These parameters define:
     * - Base key for resource identification
     * - File permissions for created resources
     * - Shared memory segment size
     * - Default TTL for cached entries
     * - Namespace for preventing key collisions
     *
     * @param int $baseKey Base identifier for the IPC resources
     * @param int $permissions File permissions for created resources (default: 0666)
     * @param int $shmSize Shared memory segment size in bytes (default: 1048576 = 1MB)
     * @param int $defaultTtl Default time-to-live for cached entries in seconds (default: 3600)
     * @param string $namespace Namespace to prevent key collisions (default: 'sysv-ipc')
     */
    public function __construct(
        private int $baseKey,
        private int $permissions = 0666,
        private int $shmSize = 1048576,
        private int $defaultTtl = 3600,
        private string $namespace = 'sysv-ipc'
    ) {
    }

    /**
     * Creates a new queue manager instance
     *
     * This method returns a new SysvQueueManager instance configured with the
     * factory's base key, permissions, and namespace. The returned manager can be
     * used to perform queue-related operations using System V IPC facilities.
     *
     * @return SysvQueueManager A new queue manager instance
     */
    public function queue(): SysvQueueManager
    {
        return new SysvQueueManager($this->baseKey, $this->permissions, $this->namespace);
    }

    /**
     * Creates a new semaphore manager instance
     *
     * This method returns a new SysvSemaphoreManager instance configured with the
     * factory's base key, permissions, and namespace. The returned manager can be
     * used to perform semaphore-related operations for process synchronization.
     *
     * @return SysvSemaphoreManager A new semaphore manager instance
     */
    public function semaphore(): SysvSemaphoreManager
    {
        return new SysvSemaphoreManager($this->baseKey, $this->permissions, $this->namespace);
    }

    /**
     * Creates a new shared memory manager instance
     *
     * This method returns a new SysvSharedMemoryManager instance configured with
     * the factory's base key, permissions, shared memory size, default TTL, and namespace.
     * The returned manager can be used to perform shared memory operations for inter-process communication.
     *
     * @return SysvSharedMemoryManager A new shared memory manager instance
     */
    public function sharedMemory(): SysvSharedMemoryManager
    {
        return new SysvSharedMemoryManager(
            $this->baseKey,
            $this->permissions,
            $this->shmSize,
            $this->defaultTtl,
            $this->namespace
        );
    }
}
