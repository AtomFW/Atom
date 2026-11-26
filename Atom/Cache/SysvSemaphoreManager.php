<?php

declare(strict_types=1);

namespace Atom\Cache;

use SysvSemaphore;

/**
 * System V IPC semaphore manager with deterministic try/finally locking.
 */
final readonly class SysvSemaphoreManager
{
    private SysvSemaphore $semaphore;

    public function __construct(
        private int $baseKey,
        private int $permissions = 0666,
        private string $namespace = 'sysv-ipc',
    ) {
        SysvIpcSupport::requireFunction('sem_get', 'sysvsem');

        $key = SysvIpcSupport::deriveKey($this->baseKey, $this->namespace, 'semaphore');
        $semaphore = \sem_get($key, 1, $this->permissions, true);

        if ($semaphore === false) {
            throw new SysvIpcException('Failed to create/semaphore sem_get().');
        }

        $this->semaphore = $semaphore;
    }

    public function getNativeSemaphore(): SysvSemaphore
    {
        return $this->semaphore;
    }

    public function acquire(bool $nowait = false): bool
    {
        SysvIpcSupport::requireFunction('sem_acquire', 'sysvsem');
        return \sem_acquire($this->semaphore, $nowait);
    }

    public function release(): bool
    {
        SysvIpcSupport::requireFunction('sem_release', 'sysvsem');
        return \sem_release($this->semaphore);
    }

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

    public function isAvailable(): bool
    {
        if ($this->acquire(true)) {
            $this->release();
            return true;
        }

        return false;
    }

    public function remove(): bool
    {
        SysvIpcSupport::requireFunction('sem_remove', 'sysvsem');
        return \sem_remove($this->semaphore);
    }
}
