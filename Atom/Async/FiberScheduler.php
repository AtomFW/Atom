<?php

declare(strict_types=1);

namespace Atom\Async;

use Atom\Async\Exception\AsyncException;
use Atom\Async\Exception\AsyncTimeoutException;
use Closure;
use Fiber;
use Io\Poll\Context as PollContext;
use Io\Poll\Event as PollEvent;
use Io\Poll\Watcher as PollWatcher;
use SplPriorityQueue;
use SplQueue;
use StreamPollHandle;
use Throwable;
use Time\Duration;
use WeakMap;

/**
 * High-performance PHP 8.6 cooperative `\Fiber` + `\Io\Poll\Context` event-loop scheduler.
 *
 * Features:
 * - Zero-polling nanosecond timer min-heap (`SplPriorityQueue` keyed by `-deadlineNs`)
 * - Native OS I/O multiplexing (`epoll` / `kqueue` / `WSAPoll`) via `\Io\Poll\Context`
 * - Zero-leak per-Fiber isolated storage via `\WeakMap<Fiber, array<string, mixed>>`
 * - Nested fiber awaiting without deadlocking or busy-spinning the CPU
 */
final class FiberScheduler
{
    /** @var SplQueue<Closure(): void> */
    private SplQueue $readyQueue;

    /** @var SplPriorityQueue<int, array{id: string, callback: Closure(): void}> */
    private SplPriorityQueue $timers;

    /** @var array<string, true> Cancelled timer IDs */
    private array $cancelledTimers = [];

    private ?PollContext $pollContext = null;

    /**
     * @var array<int, array{
     *     watcher: PollWatcher,
     *     readWaiters: list<Future<bool>>,
     *     writeWaiters: list<Future<bool>>
     * }>
     */
    private array $streamWatchers = [];

    /** @var WeakMap<object, array<string, mixed>> */
    private WeakMap $fiberContexts;

    /** @var array<string, mixed> Context fallback when running on the main root stack */
    private array $rootContext = [];

    private Duration $maxPollTick;
    private int $maxActiveFibers;
    private int $activeFiberCount = 0;
    private int $timerSeq = 0;
    private int $totalSpawnedFibers = 0;

    public function __construct(int $pollTickMs = 5, int $maxActiveFibers = 2048)
    {
        $this->readyQueue = new SplQueue();
        $this->timers = new SplPriorityQueue();
        $this->fiberContexts = new WeakMap();
        $this->maxPollTick = Duration::fromMilliseconds(\clamp($pollTickMs, 1, 500));
        $this->maxActiveFibers = \clamp($maxActiveFibers, 16, 100_000);
    }

    public function activeFiberCount(): int
    {
        return $this->activeFiberCount;
    }

    public function totalSpawnedFibers(): int
    {
        return $this->totalSpawnedFibers;
    }

    public function pollBackendName(): string
    {
        $ctx = $this->ensurePollContext();
        return $ctx->getBackend()->name;
    }

    /**
     * Spawns a callable inside a managed PHP 8.6 `\Fiber` and returns its `Future`.
     * Inherits a snapshot of the parent Fiber's isolated context!
     *
     * @template T
     * @param callable(mixed...): T $task
     * @return Future<T>
     */
    public function spawn(callable $task, mixed ...$args): Future
    {
        if ($this->activeFiberCount >= $this->maxActiveFibers) {
            throw new AsyncException("Maximum active fibers limit ({$this->maxActiveFibers}) reached.");
        }

        $parentContext = $this->currentContextMap();

        /** @var Future<T> $future */
        $future = new Future();
        $future->bindCallbacks(
            awaiter: fn(?Duration $timeout): mixed => $this->awaitFuture($future, $timeout),
        );

        $fiber = new Fiber(function () use ($task, $args, $future): void {
            $this->activeFiberCount++;
            try {
                if ($future->isCancelled()) {
                    return;
                }
                $val = $task(...$args);
                $future->resolve($val);
            } catch (Throwable $e) {
                $future->reject($e);
            } finally {
                $this->activeFiberCount = \max(0, $this->activeFiberCount - 1);
            }
        });

        $this->fiberContexts[$fiber] = $parentContext;
        $this->totalSpawnedFibers++;

        $this->enqueueReady(static function () use ($fiber, $future): void {
            if (!$future->isCancelled() && !$fiber->isStarted()) {
                $fiber->start();
            }
        });

        return $future;
    }

    /**
     * Creates an unset `Future` wired to this scheduler's cooperative `awaitFuture` loop.
     *
     * @return Future<mixed>
     */
    public function createAwaitableFuture(?Closure $canceller = null): Future
    {
        /** @var Future<mixed> $future */
        $future = new Future();
        $future->bindCallbacks(
            awaiter: fn(?Duration $timeout): mixed => $this->awaitFuture($future, $timeout),
            canceller: $canceller,
        );

        return $future;
    }

    /**
     * Suspends the current `\Fiber` for `$duration` without blocking other fibers or I/O watchers.
     */
    public function sleep(Duration $duration): void
    {
        $ns = ($duration->seconds * 1_000_000_000) + $duration->nanoseconds;
        if ($ns <= 0) {
            $this->yieldNow();
            return;
        }

        if (Fiber::getCurrent() === null) {
            // Drive pending work until the sleep deadline expires instead of wasting time idle
            $deadline = \hrtime(true) + $ns;
            while (\hrtime(true) < $deadline) {
                if (!$this->hasPendingWork()) {
                    $remainingUs = (int) \ceil(($deadline - \hrtime(true)) / 1000);
                    if ($remainingUs > 0) {
                        \usleep(\min($remainingUs, 50_000));
                    }
                } else {
                    $this->tick($deadline);
                }
            }
            return;
        }

        /** @var \SplFixedArray<string> $timerSlot */
        $timerSlot = new \SplFixedArray(1);
        $future = $this->createAwaitableFuture(function () use ($timerSlot): void {
            $tid = $timerSlot[0];
            if (\is_string($tid) && $tid !== '') {
                $this->cancelTimer($tid);
            }
        });

        $timerSlot[0] = $this->addTimer($ns, static function () use ($future): void {
            $future->resolve(true);
        });

        $future->await();
    }

    /**
     * Cooperatively yields execution from the current `\Fiber` to the ready queue, resuming on the next tick.
     */
    public function yieldNow(): void
    {
        $current = Fiber::getCurrent();
        if ($current === null) {
            $this->tick();
            return;
        }

        $this->enqueueReady(static function () use ($current): void {
            if ($current->isSuspended()) {
                $current->resume();
            }
        });

        Fiber::suspend();
    }

    /**
     * Suspends the current `\Fiber` via PHP 8.6 `\Io\Poll\Context` until `$stream` triggers `$event` (`Read` or `Write`).
     *
     * @param resource $stream
     */
    public function waitStream(mixed $stream, PollEvent $event, ?Duration $timeout = null): void
    {
        if (!\is_resource($stream)) {
            throw new AsyncException('Invalid stream resource passed to waitStream().');
        }

        \stream_set_blocking($stream, false);
        $streamId = \get_resource_id($stream);
        $poll = $this->ensurePollContext();

        if (!isset($this->streamWatchers[$streamId])) {
            $initialEvents = $event === PollEvent::Write ? [PollEvent::Write] : [PollEvent::Read];
            $watcher = $poll->add(new StreamPollHandle($stream), $initialEvents, $streamId);
            $this->streamWatchers[$streamId] = [
                'watcher' => $watcher,
                'readWaiters' => [],
                'writeWaiters' => [],
            ];
        } else {
            $entry = $this->streamWatchers[$streamId];
            $needRead = $event === PollEvent::Read || $entry['readWaiters'] !== [];
            $needWrite = $event === PollEvent::Write || $entry['writeWaiters'] !== [];
            $events = [];
            if ($needRead) {
                $events[] = PollEvent::Read;
            }
            if ($needWrite) {
                $events[] = PollEvent::Write;
            }
            if ($events !== [] && $entry['watcher']->isActive()) {
                $entry['watcher']->modifyEvents($events);
            }
        }

        /** @var Future<bool> $waitFuture */
        $waitFuture = $this->createAwaitableFuture(function () use ($streamId): void {
            $this->cleanupStreamWatcherIfIdle($streamId);
        });

        if ($event === PollEvent::Write) {
            $this->streamWatchers[$streamId]['writeWaiters'][] = $waitFuture;
        } else {
            $this->streamWatchers[$streamId]['readWaiters'][] = $waitFuture;
        }

        $waitFuture->await($timeout);
    }

    /**
     * Awaits `$future` either from inside a child `\Fiber` (by suspending the fiber!)
     * or from the root caller stack (by driving the event loop until `$future` settles!).
     *
     * @template T
     * @param Future<T> $future
     * @return T
     */
    public function awaitFuture(Future $future, ?Duration $timeout = null): mixed
    {
        if ($future->isSettled()) {
            return $future->await();
        }

        $timeoutTimerId = '';
        if ($timeout !== null) {
            $timeoutNs = \max(1, ($timeout->seconds * 1_000_000_000) + $timeout->nanoseconds);
            $timeoutTimerId = $this->addTimer($timeoutNs, static function () use ($future, $timeout): void {
                if ($future->isPending()) {
                    $ms = \round(($timeout->seconds * 1000) + ($timeout->nanoseconds / 1_000_000), 2);
                    $future->reject(new AsyncTimeoutException("Async operation timed out after {$ms} ms."));
                }
            });
        }

        $currentFiber = Fiber::getCurrent();

        try {
            if ($currentFiber !== null) {
                $future->onSettle(function () use ($currentFiber): void {
                    $this->enqueueReady(static function () use ($currentFiber): void {
                        if ($currentFiber->isSuspended()) {
                            $currentFiber->resume();
                        }
                    });
                });

                while (!$future->isSettled()) {
                    Fiber::suspend();
                }
            } else {
                while (!$future->isSettled()) {
                    if (!$this->hasPendingWork()) {
                        throw new AsyncException('Deadlock detected: Future is pending with no runnable fibers, timers, or I/O watchers.');
                    }
                    $this->tick();
                }
            }
        } finally {
            if ($timeoutTimerId !== '') {
                $this->cancelTimer($timeoutTimerId);
            }
        }

        return $future->await();
    }

    /**
     * Drives all pending fibers, timers, and I/O watchers until the loop becomes completely idle.
     */
    public function drain(): void
    {
        while ($this->hasPendingWork()) {
            $this->tick();
        }
    }

    public function setContext(string $key, mixed $value): void
    {
        $current = Fiber::getCurrent();
        if ($current === null) {
            $this->rootContext[$key] = $value;
            return;
        }

        $map = $this->fiberContexts[$current] ?? [];
        $map[$key] = $value;
        $this->fiberContexts[$current] = $map;
    }

    public function getContext(string $key, mixed $default = null): mixed
    {
        $map = $this->currentContextMap();
        return \array_key_exists($key, $map) ? $map[$key] : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function currentContextMap(): array
    {
        $current = Fiber::getCurrent();
        if ($current === null) {
            return $this->rootContext;
        }

        return $this->fiberContexts[$current] ?? $this->rootContext;
    }

    public function enqueueReady(Closure $callback): void
    {
        $this->readyQueue->enqueue($callback);
    }

    public function addTimer(int $delayNs, Closure $callback): string
    {
        $id = 'tm_' . (++$this->timerSeq);
        $deadlineNs = \hrtime(true) + \max(0, $delayNs);
        // SplPriorityQueue is a max-heap, so negative deadlineNs pops the earliest timer first!
        $this->timers->insert(['id' => $id, 'callback' => $callback], -$deadlineNs);

        return $id;
    }

    public function cancelTimer(string $timerId): void
    {
        $this->cancelledTimers[$timerId] = true;
        if (\count($this->cancelledTimers) > 4096) {
            $this->cancelledTimers = \array_slice($this->cancelledTimers, -2048, null, true);
        }
    }

    private function hasPendingWork(): bool
    {
        return !$this->readyQueue->isEmpty()
            || !$this->timers->isEmpty()
            || $this->streamWatchers !== [];
    }

    private function tick(?int $externalCapDeadlineNs = null): void
    {
        // 1. Fire expired timers
        $nowNs = \hrtime(true);
        $this->timers->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

        while (!$this->timers->isEmpty()) {
            /** @var array{data: array{id: string, callback: Closure(): void}, priority: int} $top */
            $top = $this->timers->top();
            $deadlineNs = -$top['priority'];
            if ($deadlineNs > $nowNs) {
                break;
            }

            $this->timers->extract();
            $timerId = $top['data']['id'];
            if (isset($this->cancelledTimers[$timerId])) {
                unset($this->cancelledTimers[$timerId]);
                continue;
            }

            $this->readyQueue->enqueue($top['data']['callback']);
        }

        // 2. Run a bounded batch of ready callbacks/fibers so I/O & timers are never starved
        $batchCount = $this->readyQueue->count();
        for ($i = 0; $i < $batchCount; $i++) {
            if ($this->readyQueue->isEmpty()) {
                break;
            }
            $cb = $this->readyQueue->dequeue();
            $cb();
        }

        // 3. Poll I/O streams (or sleep cleanly until next timer deadline) without busy-spinning CPU!
        $waitDuration = $this->computeNextWaitDuration($externalCapDeadlineNs);

        if ($this->streamWatchers !== []) {
            $poll = $this->ensurePollContext();
            $pollTimeout = !$this->readyQueue->isEmpty()
                ? Duration::fromNanoseconds(0)
                : ($waitDuration ?? $this->maxPollTick);

            $triggered = $poll->wait($pollTimeout);
            foreach ($triggered as $watcher) {
                $streamId = (int) $watcher->getData();
                if (!isset($this->streamWatchers[$streamId])) {
                    continue;
                }

                $entry = $this->streamWatchers[$streamId];
                $isErrOrHup = $watcher->hasTriggered(PollEvent::Error) || $watcher->hasTriggered(PollEvent::HangUp);

                if ($watcher->hasTriggered(PollEvent::Read) || $isErrOrHup) {
                    $waiters = $entry['readWaiters'];
                    $this->streamWatchers[$streamId]['readWaiters'] = [];
                    foreach ($waiters as $f) {
                        $f->resolve(true);
                    }
                }

                if ($watcher->hasTriggered(PollEvent::Write) || $isErrOrHup) {
                    $waiters = $entry['writeWaiters'];
                    $this->streamWatchers[$streamId]['writeWaiters'] = [];
                    foreach ($waiters as $f) {
                        $f->resolve(true);
                    }
                }

                $this->cleanupStreamWatcherIfIdle($streamId);
            }
        } elseif ($this->readyQueue->isEmpty() && $waitDuration !== null) {
            $sleepUs = (int) \ceil(($waitDuration->seconds * 1_000_000) + ($waitDuration->nanoseconds / 1000));
            if ($sleepUs > 0) {
                \usleep(\min($sleepUs, 25_000));
            }
        }
    }

    private function computeNextWaitDuration(?int $externalCapDeadlineNs = null): ?Duration
    {
        $nowNs = \hrtime(true);
        $nextNs = $externalCapDeadlineNs;

        if (!$this->timers->isEmpty()) {
            $this->timers->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
            /** @var array{data: array{id: string, callback: Closure(): void}, priority: int} $top */
            $top = $this->timers->top();
            $timerDeadline = -$top['priority'];
            $nextNs = $nextNs !== null ? \min($nextNs, $timerDeadline) : $timerDeadline;
        }

        if ($nextNs === null) {
            return null;
        }

        $deltaNs = \max(0, $nextNs - $nowNs);
        return Duration::fromNanoseconds($deltaNs);
    }

    private function cleanupStreamWatcherIfIdle(int $streamId): void
    {
        if (!isset($this->streamWatchers[$streamId])) {
            return;
        }

        $entry = $this->streamWatchers[$streamId];
        $activeReads = \array_values(\array_filter($entry['readWaiters'], static fn(Future $f): bool => $f->isPending()));
        $activeWrites = \array_values(\array_filter($entry['writeWaiters'], static fn(Future $f): bool => $f->isPending()));

        if ($activeReads === [] && $activeWrites === []) {
            if ($entry['watcher']->isActive()) {
                $entry['watcher']->remove();
            }
            unset($this->streamWatchers[$streamId]);
            return;
        }

        $this->streamWatchers[$streamId]['readWaiters'] = $activeReads;
        $this->streamWatchers[$streamId]['writeWaiters'] = $activeWrites;

        $events = [];
        if ($activeReads !== []) {
            $events[] = PollEvent::Read;
        }
        if ($activeWrites !== []) {
            $events[] = PollEvent::Write;
        }
        if ($entry['watcher']->isActive()) {
            $entry['watcher']->modifyEvents($events);
        }
    }

    private function ensurePollContext(): PollContext
    {
        if ($this->pollContext === null) {
            $this->pollContext = new PollContext();
        }

        return $this->pollContext;
    }
}
