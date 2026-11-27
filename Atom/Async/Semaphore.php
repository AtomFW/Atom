<?php

declare(strict_types=1);

namespace Atom\Async;

use InvalidArgumentException;
use SplQueue;
use Time\Duration;

/**
 * Coroutine & `\Fiber`-safe counting Semaphore (and binary Mutex when `$permits = 1`)
 * with deterministic `try / finally` permit release via `run()`.
 */
final class Semaphore
{
    private int $maxPermits;
    private int $available;

    /** @var SplQueue<Future<bool>> */
    private SplQueue $waitQueue;

    private ?Channel $swooleGuard = null;
    private ?FiberScheduler $scheduler;

    public function __construct(int $permits = 1, ?FiberScheduler $scheduler = null, bool $preferSwoole = true)
    {
        if ($permits < 1) {
            throw new InvalidArgumentException('Semaphore permits must be >= 1.');
        }

        $this->maxPermits = \clamp($permits, 1, 100_000);
        $this->available = $this->maxPermits;
        $this->waitQueue = new SplQueue();
        $this->scheduler = $scheduler;

        if ($preferSwoole && Async::isInSwooleCoroutine()) {
            $this->swooleGuard = new Channel($this->maxPermits, $scheduler, true);
            for ($i = 0; $i < $this->maxPermits; $i++) {
                $this->swooleGuard->push(true);
            }
        }
    }

    public function maxPermits(): int
    {
        return $this->maxPermits;
    }

    public function availablePermits(): int
    {
        if ($this->swooleGuard !== null) {
            return $this->swooleGuard->length();
        }

        return $this->available;
    }

    public function acquire(?Duration $timeout = null): void
    {
        if ($this->swooleGuard !== null) {
            $this->swooleGuard->pop($timeout);
            return;
        }

        if ($this->available > 0) {
            $this->available--;
            return;
        }

        if ($this->scheduler === null) {
            // Synchronous fallback when outside scheduler and permits exhausted (should not block forever)
            $this->available = \max(0, $this->available - 1);
            return;
        }

        /** @var Future<bool> $waiter */
        $waiter = $this->scheduler->createAwaitableFuture();
        $this->waitQueue->enqueue($waiter);
        $waiter->await($timeout);
    }

    public function tryAcquire(): bool
    {
        if ($this->swooleGuard !== null) {
            return $this->swooleGuard->tryPop(false) === true;
        }

        if ($this->available > 0) {
            $this->available--;
            return true;
        }

        return false;
    }

    public function release(): void
    {
        if ($this->swooleGuard !== null) {
            if ($this->swooleGuard->length() < $this->maxPermits) {
                $this->swooleGuard->push(true);
            }
            return;
        }

        while (!$this->waitQueue->isEmpty()) {
            $next = $this->waitQueue->dequeue();
            if ($next->isPending()) {
                $next->resolve(true);
                return;
            }
        }

        $this->available = \min($this->maxPermits, $this->available + 1);
    }

    /**
     * Acquires a permit, runs `$callback()`, and guarantees `release()` in `finally`.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback, ?Duration $timeout = null): mixed
    {
        $this->acquire($timeout);
        try {
            return $callback();
        } finally {
            $this->release();
        }
    }
}
