<?php

declare(strict_types=1);

namespace Atom\Cache;

use Time\Duration;

/**
 * Immutable System V IPC Manager Factory.
 */
final readonly class SysvIpcManager
{
    private int $defaultTtlSeconds;

    public function __construct(
        private int $baseKey,
        private int $permissions = 0666,
        private int $shmSize = 1_048_576,
        int|Duration $defaultTtl = 3600,
        private string $namespace = 'sysv-ipc',
    ) {
        $this->defaultTtlSeconds = $defaultTtl instanceof Duration
            ? ($defaultTtl->negative ? 0 : $defaultTtl->seconds)
            : \clamp($defaultTtl, 0, \PHP_INT_MAX);
    }

    public function queue(): SysvQueueManager
    {
        return new SysvQueueManager($this->baseKey, $this->permissions, $this->namespace);
    }

    public function semaphore(): SysvSemaphoreManager
    {
        return new SysvSemaphoreManager($this->baseKey, $this->permissions, $this->namespace);
    }

    public function sharedMemory(): SysvSharedMemoryManager
    {
        return new SysvSharedMemoryManager(
            $this->baseKey,
            $this->permissions,
            $this->shmSize,
            $this->defaultTtlSeconds,
            $this->namespace,
        );
    }
}
