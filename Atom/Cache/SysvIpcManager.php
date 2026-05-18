<?php
declare(strict_types=1);

namespace Atom\Cache;

use Atom\Cache\SysvIpcException;
use Atom\Cache\SysvIpcSupport;
use Atom\Cache\SysvQueueManager;
use Atom\Cache\SysvSemaphoreManager;
use Atom\Cache\SysvSharedMemoryManager;


final class SysvIpcManager
{
    public function __construct(
        private int $baseKey,
        private int $permissions = 0666,
        private int $shmSize = 1048576,
        private int $defaultTtl = 3600,
        private string $namespace = 'sysv-ipc'
    ) {
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
            $this->defaultTtl,
            $this->namespace
        );
    }
}