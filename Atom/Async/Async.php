<?php

declare(strict_types=1);

namespace Atom\Async;

use Atom\Atom;
use Atom\Async\Exception\AggregateAsyncException;
use Atom\Async\Exception\AsyncException;
use Atom\Async\Exception\AsyncTimeoutException;
use Closure;
use Fiber;
use Generator;
use InvalidArgumentException;
use Io\Poll\Event as PollEvent;
use Throwable;
use Time\Duration;

/**
 * Unified high-performance Fiber & Coroutine asynchronous engine for Atom and client applications.
 *
 * Automatically leverages native C **Swoole / OpenSwoole** coroutines and preemptive I/O hooks
 * when Swoole is available, and seamlessly executes on PHP 8.6's native **`\Fiber` + `\Io\Poll\Context`**
 * cooperative event-loop scheduler on standard FPM / CLI / FrankenPHP / Windows runtimes.
 */
final class Async
{
    public const string VERSION = '1.0.0';

    private bool $enabled;
    private AsyncDriver $configuredDriver;
    private bool $enableSwooleCliRun;
    private int $defaultConcurrency;
    private int $defaultChannelCapacity;
    private int $streamChunkBytes;
    private int $maxNamedLocks;

    private FiberScheduler $scheduler;

    /** @var array<string, Semaphore> Bounded named mutex map */
    private array $namedLocks = [];

    public function __construct(
        AsyncDriver|string $driver = AsyncDriver::Auto,
        int $defaultConcurrency = 32,
        int $pollTickMs = 5,
        int $maxActiveFibers = 2048,
        int $defaultChannelCapacity = 64,
        int $streamChunkBytes = 65536,
        int $maxNamedLocks = 512,
        bool $enableSwooleCliRun = true,
        bool $enabled = true,
    ) {
        $this->enabled = $enabled;
        $this->configuredDriver = $driver instanceof AsyncDriver
            ? $driver
            : (AsyncDriver::tryFrom(\strtolower(\trim($driver))) ?? AsyncDriver::Auto);
        $this->defaultConcurrency = \clamp($defaultConcurrency, 1, 10_000);
        $this->defaultChannelCapacity = \clamp($defaultChannelCapacity, 1, 100_000);
        $this->streamChunkBytes = \clamp($streamChunkBytes, 1024, 16_777_216);
        $this->maxNamedLocks = \clamp($maxNamedLocks, 16, 65_536);
        $this->enableSwooleCliRun = $enableSwooleCliRun;
        $this->scheduler = new FiberScheduler($pollTickMs, $maxActiveFibers);
    }

    /**
     * Instantiates `Async` from `config/async.php`.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config = []): self
    {
        $driverBlock = \is_array($config['driver'] ?? null) ? $config['driver'] : [];
        $limits = \is_array($config['limits'] ?? null) ? $config['limits'] : [];
        $driverStr = (string) ($driverBlock['driver'] ?? $config['default'] ?? 'auto');

        return new self(
            driver: $driverStr,
            defaultConcurrency: (int) ($limits['defaultConcurrency'] ?? 32),
            pollTickMs: (int) ($driverBlock['pollTickMs'] ?? 5),
            maxActiveFibers: (int) ($driverBlock['maxActiveFibers'] ?? 2048),
            defaultChannelCapacity: (int) ($limits['defaultChannelCapacity'] ?? 64),
            streamChunkBytes: (int) ($limits['streamChunkBytes'] ?? 65536),
            maxNamedLocks: (int) ($limits['maxNamedLocks'] ?? 512),
            enableSwooleCliRun: (bool) ($driverBlock['enableSwooleCliRun'] ?? true),
            enabled: (bool) ($config['on'] ?? true),
        );
    }

    /**
     * Detects whether Swoole or OpenSwoole coroutine runtime is installed in the current PHP process.
     */
    public static function isSwooleAvailable(): bool
    {
        return (\extension_loaded('swoole') && \class_exists('Swoole\\Coroutine'))
            || (\extension_loaded('openswoole') && \class_exists('OpenSwoole\\Coroutine'));
    }

    /**
     * Returns true when execution is currently inside an active Swoole / OpenSwoole coroutine (`cid > 0`).
     */
    public static function isInSwooleCoroutine(): bool
    {
        $cls = self::resolveSwooleCoroutineClass();
        if ($cls === null || !\method_exists($cls, 'getCid')) {
            return false;
        }

        return (int) $cls::getCid() > 0;
    }

    /**
     * Returns true when execution is currently inside a native PHP 8.6 `\Fiber`.
     */
    public static function isInsideFiber(): bool
    {
        return Fiber::getCurrent() !== null;
    }

    /**
     * Returns true when inside either a Swoole coroutine or a PHP `\Fiber`.
     */
    public static function isInAsyncContext(): bool
    {
        return self::isInSwooleCoroutine() || self::isInsideFiber();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Resolves the effective runtime backend (`Swoole` when available and permitted, otherwise `Fiber`).
     */
    public function activeDriver(): AsyncDriver
    {
        if ($this->configuredDriver === AsyncDriver::Fiber) {
            return AsyncDriver::Fiber;
        }

        if (self::isSwooleAvailable()) {
            if (self::isInSwooleCoroutine() || $this->enableSwooleCliRun) {
                return AsyncDriver::Swoole;
            }
        }

        return AsyncDriver::Fiber;
    }

    public function scheduler(): FiberScheduler
    {
        return $this->scheduler;
    }

    /**
     * Returns full diagnostic metadata about the active async backend, Swoole status, and `\Io\Poll` backend.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        return [
            'enabled' => $this->enabled,
            'configuredDriver' => $this->configuredDriver->value,
            'effectiveDriver' => $this->activeDriver()->value,
            'swooleAvailable' => self::isSwooleAvailable(),
            'inSwooleCoroutine' => self::isInSwooleCoroutine(),
            'insidePhpFiber' => self::isInsideFiber(),
            'ioPollBackend' => $this->scheduler->pollBackendName(),
            'activeFibers' => $this->scheduler->activeFiberCount(),
            'totalSpawnedFibers' => $this->scheduler->totalSpawnedFibers(),
            'defaultConcurrency' => $this->defaultConcurrency,
            'streamChunkBytes' => $this->streamChunkBytes,
        ];
    }

    /**
     * Runs a top-level async entrypoint to completion (using Swoole's C coroutine scheduler when available,
     * or driving PHP 8.6's `\Fiber` + `\Io\Poll\Context` loop until all spawned work finishes).
     *
     * @template T
     * @param callable(self): T $entrypoint
     * @return T
     */
    public function run(callable $entrypoint): mixed
    {
        if (!$this->enabled) {
            return $entrypoint($this);
        }

        if ($this->activeDriver() === AsyncDriver::Swoole) {
            if (self::isInSwooleCoroutine()) {
                return $entrypoint($this);
            }

            return $this->runInsideSwooleScheduler($entrypoint);
        }

        if (self::isInsideFiber()) {
            return $entrypoint($this);
        }

        $entryFuture = $this->scheduler->spawn(function () use ($entrypoint): mixed {
            $cur = Fiber::getCurrent();
            if ($cur !== null && \function_exists('_atom_async_fiber_registry')) {
                \_atom_async_fiber_registry()[$cur] = $this;
            }
            return $entrypoint($this);
        });
        $result = $this->scheduler->awaitFuture($entryFuture);
        $this->scheduler->drain();

        return $result;
    }

    /**
     * Spawns `$task` asynchronously inside a PHP `\Fiber` or Swoole coroutine and returns a `Future`.
     *
     * @template T
     * @param callable(mixed...): T $task
     * @return Future<T>
     */
    public function spawn(callable $task, mixed ...$args): Future
    {
        $probe = isset(Atom::$app) ? Atom::$app->probe : null;
        $engineRef = $this;

        $wrapped = static function () use ($task, $args, $probe, $engineRef): mixed {
            $cur = Fiber::getCurrent();
            if ($cur !== null && \function_exists('_atom_async_fiber_registry')) {
                \_atom_async_fiber_registry()[$cur] = $engineRef;
            }

            if ($probe === null) {
                return $task(...$args);
            }

            $span = $probe->startSpan('async.fiber', 'client_app');
            $err = null;
            try {
                return $task(...$args);
            } catch (Throwable $e) {
                $err = $e;
                throw $e;
            } finally {
                $probe->endSpan($span, [], $err);
            }
        };

        if (!$this->enabled) {
            try {
                return Future::resolved($wrapped());
            } catch (Throwable $e) {
                return Future::rejected($e);
            }
        }

        if ($this->activeDriver() === AsyncDriver::Swoole && self::isInSwooleCoroutine()) {
            return $this->spawnInSwooleCoroutine($wrapped);
        }

        return $this->scheduler->spawn($wrapped);
    }

    /**
     * Schedules fire-and-forget / background work onto the async loop and returns its `Future`.
     *
     * @template T
     * @param callable(mixed...): T $task
     * @return Future<T>
     */
    public function defer(callable $task, mixed ...$args): Future
    {
        return $this->spawn($task, ...$args);
    }

    /**
     * Awaits a `Future` with an optional timeout (`\Time\Duration`, seconds `float`, or `int` seconds).
     *
     * @template T
     * @param Future<T> $future
     * @return T
     */
    public function await(Future $future, Duration|int|float|null $timeout = null): mixed
    {
        return $future->await($this->normalizeOptionalDuration($timeout));
    }

    /**
     * Awaits an associative or list array of `Future` objects concurrently, preserving keys.
     *
     * @template TKey of array-key
     * @template TVal
     * @param array<TKey, Future<TVal>> $futures
     * @return array<TKey, TVal>
     */
    public function awaitAll(array $futures, Duration|int|float|null $timeout = null): array
    {
        $dur = $this->normalizeOptionalDuration($timeout);
        $results = [];

        foreach ($futures as $key => $future) {
            $results[$key] = $future->await($dur);
        }

        return $results;
    }

    /**
     * Executes an array of callables concurrently across Fibers / Swoole coroutines, preserving keys.
     * Fails fast and cancels sibling pending tasks if any task throws an exception.
     *
     * @template TKey of array-key
     * @template TVal
     * @param array<TKey, callable(): TVal> $tasks
     * @return array<TKey, TVal>
     */
    public function all(
        array $tasks,
        int $concurrency = 0,
        Duration|int|float|null $timeout = null,
    ): array {
        if ($tasks === []) {
            return [];
        }

        return $this->executeWithinAsyncScope(function () use ($tasks, $concurrency, $timeout): array {
            $dur = $this->normalizeOptionalDuration($timeout);
            $limiter = $concurrency > 0 ? $this->semaphore($concurrency) : null;

            /** @var array<int|string, Future<mixed>> $futures */
            $futures = [];

            foreach ($tasks as $key => $task) {
                $futures[$key] = $this->spawn(static function () use ($task, $limiter, $dur): mixed {
                    if ($limiter === null) {
                        return $task();
                    }
                    return $limiter->run($task, $dur);
                });
            }

            $results = [];
            try {
                foreach ($futures as $key => $f) {
                    $results[$key] = $f->await($dur);
                }
            } catch (Throwable $firstError) {
                foreach ($futures as $f) {
                    if ($f->isPending()) {
                        $f->cancel('Sibling task in Async::all() failed.');
                    }
                }
                throw $firstError;
            }

            return $results;
        });
    }

    /**
     * Executes all callables concurrently and collects settlement descriptors without failing fast.
     *
     * @template TKey of array-key
     * @param array<TKey, callable(): mixed> $tasks
     * @return array<TKey, array{status: 'fulfilled'|'rejected', value?: mixed, reason?: Throwable}>
     */
    public function allSettled(
        array $tasks,
        int $concurrency = 0,
        Duration|int|float|null $timeout = null,
    ): array {
        if ($tasks === []) {
            return [];
        }

        return $this->executeWithinAsyncScope(function () use ($tasks, $concurrency, $timeout): array {
            $dur = $this->normalizeOptionalDuration($timeout);
            $limiter = $concurrency > 0 ? $this->semaphore($concurrency) : null;

            $futures = [];
            foreach ($tasks as $key => $task) {
                $futures[$key] = $this->spawn(static function () use ($task, $limiter, $dur): mixed {
                    return $limiter !== null ? $limiter->run($task, $dur) : $task();
                });
            }

            $outcomes = [];
            foreach ($futures as $key => $future) {
                try {
                    $val = $future->await($dur);
                    $outcomes[$key] = [
                        'status' => 'fulfilled',
                        'value' => $val,
                    ];
                } catch (Throwable $e) {
                    $outcomes[$key] = [
                        'status' => 'rejected',
                        'reason' => $e,
                    ];
                }
            }

            return $outcomes;
        });
    }

    /**
     * Races multiple callables concurrently; settles with the result or error of whichever finishes FIRST
     * and cancels all remaining pending tasks.
     *
     * @param array<int|string, callable(): mixed> $tasks
     */
    public function race(array $tasks, Duration|int|float|null $timeout = null): mixed
    {
        if ($tasks === []) {
            throw new InvalidArgumentException('Async::race() requires at least one task.');
        }

        return $this->executeWithinAsyncScope(function () use ($tasks, $timeout): mixed {
            $winnerFuture = $this->createBridgeFuture();

            /** @var list<Future<mixed>> $spawned */
            $spawned = [];
            foreach ($tasks as $task) {
                $f = $this->spawn($task);
                $spawned[] = $f;
                $f->onSettle(static function (Future $settled) use ($winnerFuture): void {
                    if (!$winnerFuture->isPending()) {
                        return;
                    }
                    if ($settled->isFulfilled()) {
                        $winnerFuture->resolve($settled->await());
                    } elseif ($settled->getError() !== null) {
                        $winnerFuture->reject($settled->getError());
                    }
                });
            }

            try {
                return $winnerFuture->await($this->normalizeOptionalDuration($timeout));
            } finally {
                foreach ($spawned as $f) {
                    if ($f->isPending()) {
                        $f->cancel('Lost Async::race().');
                    }
                }
            }
        });
    }

    /**
     * Races multiple callables concurrently and returns the FIRST fulfilled value.
     * Rejects with `AggregateAsyncException` only when EVERY candidate task fails.
     *
     * @param array<int|string, callable(): mixed> $tasks
     */
    public function any(array $tasks, Duration|int|float|null $timeout = null): mixed
    {
        if ($tasks === []) {
            throw new InvalidArgumentException('Async::any() requires at least one task.');
        }

        return $this->executeWithinAsyncScope(function () use ($tasks, $timeout): mixed {
            $total = \count($tasks);
            /** @var \ArrayObject<int|string, Throwable> $failures */
            $failures = new \ArrayObject();
            $winnerFuture = $this->createBridgeFuture();

            /** @var list<Future<mixed>> $spawned */
            $spawned = [];
            foreach ($tasks as $key => $task) {
                $f = $this->spawn($task);
                $spawned[] = $f;
                $f->onSettle(static function (Future $settled) use ($key, $total, $failures, $winnerFuture): void {
                    if (!$winnerFuture->isPending()) {
                        return;
                    }
                    if ($settled->isFulfilled()) {
                        $winnerFuture->resolve($settled->await());
                        return;
                    }
                    if ($settled->getError() !== null) {
                        $failures[$key] = $settled->getError();
                    }
                    if ($failures->count() >= $total) {
                        $winnerFuture->reject(new AggregateAsyncException('All tasks passed to Async::any() failed.', $failures->getArrayCopy()));
                    }
                });
            }

            try {
                return $winnerFuture->await($this->normalizeOptionalDuration($timeout));
            } finally {
                foreach ($spawned as $f) {
                    if ($f->isPending()) {
                        $f->cancel('Another candidate already fulfilled Async::any().');
                    }
                }
            }
        });
    }

    /**
     * Concurrent `array_map` over `$items` with bounded concurrency (`$concurrency` fibers/coroutines at once),
     * preserving original keys and order!
     *
     * @template TKey of array-key
     * @template TIn
     * @template TOut
     * @param iterable<TKey, TIn> $items
     * @param callable(TIn, TKey): TOut $mapper
     * @return array<TKey, TOut>
     */
    public function map(iterable $items, callable $mapper, int $concurrency = 0): array
    {
        $limit = $concurrency > 0 ? $concurrency : $this->defaultConcurrency;
        $tasks = [];

        foreach ($items as $key => $item) {
            $tasks[$key] = static fn(): mixed => $mapper($item, $key);
        }

        return $this->all($tasks, $limit);
    }

    /**
     * Concurrent `array_filter` over `$items` preserving keys where `$predicate($item, $key)` returns truthy.
     *
     * @template TKey of array-key
     * @template TIn
     * @param iterable<TKey, TIn> $items
     * @param callable(TIn, TKey): bool $predicate
     * @return array<TKey, TIn>
     */
    public function filter(iterable $items, callable $predicate, int $concurrency = 0): array
    {
        $materialized = [];
        foreach ($items as $k => $v) {
            $materialized[$k] = $v;
        }

        $flags = $this->map($materialized, $predicate, $concurrency);
        $kept = [];
        foreach ($flags as $k => $keep) {
            if ($keep) {
                $kept[$k] = $materialized[$k];
            }
        }

        return $kept;
    }

    /**
     * Concurrent `foreach` over `$items` with bounded concurrency.
     *
     * @template TKey of array-key
     * @template TIn
     * @param iterable<TKey, TIn> $items
     * @param callable(TIn, TKey): mixed $consumer
     */
    public function each(iterable $items, callable $consumer, int $concurrency = 0): void
    {
        $this->map($items, $consumer, $concurrency);
    }

    /**
     * Splits `$items` into chunks of `$batchSize` and processes batches concurrently.
     *
     * @template TIn
     * @template TOut
     * @param array<int|string, TIn> $items
     * @param callable(array<int|string, TIn>, int): TOut $batchProcessor
     * @return list<TOut>
     */
    public function batch(
        array $items,
        int $batchSize,
        callable $batchProcessor,
        int $concurrency = 4,
        bool $preserveKeysInChunks = false,
    ): array {
        if ($items === []) {
            return [];
        }

        $chunks = \array_chunk($items, \clamp($batchSize, 1, 100_000), $preserveKeysInChunks);
        /** @var list<TOut> $mapped */
        $mapped = \array_values($this->map($chunks, $batchProcessor, \clamp($concurrency, 1, 1024)));

        return $mapped;
    }

    /**
     * Executes sequential async pipeline steps (`output_i -> input_{i+1}`) while yielding cooperatively between steps.
     *
     * @param list<callable(mixed): mixed> $steps
     */
    public function waterfall(array $steps, mixed $initialValue = null): mixed
    {
        return $this->executeWithinAsyncScope(function () use ($steps, $initialValue): mixed {
            $acc = $initialValue;
            foreach ($steps as $step) {
                $acc = $step($acc);
                if ($acc instanceof Future) {
                    $acc = $acc->await();
                }
                $this->yieldNow();
            }

            return $acc;
        });
    }

    /**
     * Non-blocking sleep suspending only the current `\Fiber` or Swoole coroutine while sibling tasks run at full speed.
     */
    public function sleep(Duration|int|float $duration): void
    {
        $dur = $this->normalizeDuration($duration);

        if (self::isInSwooleCoroutine()) {
            $coroCls = self::resolveSwooleCoroutineClass();
            if ($coroCls !== null && \method_exists($coroCls, 'sleep')) {
                $sec = $dur->seconds + ($dur->nanoseconds / 1_000_000_000);
                $coroCls::sleep(\max(0.001, $sec));
                return;
            }
        }

        $this->scheduler->sleep($dur);
    }

    /**
     * Cooperatively yields control from the current fiber/coroutine so ready tasks and I/O watchers can advance.
     */
    public function yieldNow(): void
    {
        if (self::isInSwooleCoroutine()) {
            $coroCls = self::resolveSwooleCoroutineClass();
            if ($coroCls !== null && \method_exists($coroCls, 'sleep')) {
                $coroCls::sleep(0.001);
                return;
            }
        }

        $this->scheduler->yieldNow();
    }

    /**
     * Executes `$task` with a strict deadline, cancelling it and throwing `AsyncTimeoutException` on expiration.
     *
     * @template T
     * @param callable(): T $task
     * @return T
     */
    public function timeout(callable $task, Duration|int|float $timeout): mixed
    {
        $dur = $this->normalizeDuration($timeout);

        return $this->executeWithinAsyncScope(function () use ($task, $dur): mixed {
            $future = $this->spawn($task);
            try {
                return $future->await($dur);
            } catch (AsyncTimeoutException $e) {
                $future->cancel('Timed out.');
                throw $e;
            }
        });
    }

    /**
     * Retries a transient-failing async callable with non-blocking exponential backoff (`sleep()`).
     *
     * @template T
     * @param callable(int): T $task Receives 1-based attempt number
     * @param (callable(Throwable, int): bool)|null $shouldRetry
     * @return T
     */
    public function retry(
        callable $task,
        int $maxAttempts = 3,
        Duration|int|float $baseDelay = 0.01,
        float $backoffMultiplier = 2.0,
        ?callable $shouldRetry = null,
    ): mixed {
        $attempts = \clamp($maxAttempts, 1, 100);
        $baseDur = $this->normalizeDuration($baseDelay);
        $baseNs = ($baseDur->seconds * 1_000_000_000) + $baseDur->nanoseconds;
        $mult = \max(1.0, $backoffMultiplier);

        return $this->executeWithinAsyncScope(function () use ($task, $attempts, $baseNs, $mult, $shouldRetry): mixed {
            $lastError = null;

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    return $task($attempt);
                } catch (Throwable $e) {
                    $lastError = $e;
                    if ($attempt >= $attempts) {
                        break;
                    }
                    if ($shouldRetry !== null && !$shouldRetry($e, $attempt)) {
                        throw $e;
                    }

                    $delayNs = (int) \round($baseNs * ($mult ** ($attempt - 1)));
                    if ($delayNs > 0) {
                        $this->sleep(Duration::fromNanoseconds($delayNs));
                    }
                }
            }

            throw $lastError;
        });
    }

    /**
     * Creates a bounded coroutine/fiber-safe `Channel` for producer-consumer pipelines.
     */
    public function channel(int $capacity = 0): Channel
    {
        $cap = $capacity > 0 ? $capacity : $this->defaultChannelCapacity;
        return new Channel($cap, $this->scheduler, $this->activeDriver() === AsyncDriver::Swoole);
    }

    /**
     * Creates a counting `Semaphore` limiting concurrent access to `$permits` fibers/coroutines.
     */
    public function semaphore(int $permits = 1): Semaphore
    {
        return new Semaphore($permits, $this->scheduler, $this->activeDriver() === AsyncDriver::Swoole);
    }

    /**
     * Creates a binary `Mutex` (`Semaphore` with `1` permit).
     */
    public function mutex(): Semaphore
    {
        return $this->semaphore(1);
    }

    /**
     * Executes `$criticalSection` exclusively under a named coroutine/fiber-safe lock.
     *
     * @template T
     * @param callable(): T $criticalSection
     * @return T
     */
    public function withLock(
        string $lockName,
        callable $criticalSection,
        Duration|int|float|null $timeout = null,
    ): mixed {
        if (!isset($this->namedLocks[$lockName])) {
            if (\count($this->namedLocks) >= $this->maxNamedLocks) {
                foreach ($this->namedLocks as $k => $sem) {
                    if ($sem->availablePermits() === $sem->maxPermits()) {
                        unset($this->namedLocks[$k]);
                        break;
                    }
                }
            }
            $this->namedLocks[$lockName] = $this->mutex();
        }

        return $this->namedLocks[$lockName]->run($criticalSection, $this->normalizeOptionalDuration($timeout));
    }

    /**
     * Stores a key/value in the isolated context of the current `\Fiber` (`WeakMap`) or Swoole Coroutine (`getContext()`).
     * Automatically inherited by child fibers and garbage-collected with zero memory leaks!
     */
    public function contextSet(string $key, mixed $value): self
    {
        if (self::isInSwooleCoroutine()) {
            $coroCls = self::resolveSwooleCoroutineClass();
            if ($coroCls !== null && \method_exists($coroCls, 'getContext')) {
                $ctx = $coroCls::getContext();
                if ($ctx instanceof \ArrayAccess || \is_object($ctx)) {
                    $ctx[$key] = $value;
                    return $this;
                }
            }
        }

        $this->scheduler->setContext($key, $value);
        return $this;
    }

    /**
     * Reads a value from the isolated context of the current `\Fiber` or Swoole Coroutine.
     */
    public function contextGet(string $key, mixed $default = null): mixed
    {
        if (self::isInSwooleCoroutine()) {
            $coroCls = self::resolveSwooleCoroutineClass();
            if ($coroCls !== null && \method_exists($coroCls, 'getContext')) {
                $ctx = $coroCls::getContext();
                if (($ctx instanceof \ArrayAccess || \is_array($ctx)) && isset($ctx[$key])) {
                    return $ctx[$key];
                }
            }
        }

        return $this->scheduler->getContext($key, $default);
    }

    /**
     * Suspends the current fiber via PHP 8.6 `\Io\Poll\Context` until `$stream` becomes readable.
     *
     * @param resource $stream
     */
    public function waitReadable(mixed $stream, Duration|int|float|null $timeout = null): void
    {
        if (self::isInSwooleCoroutine()) {
            return;
        }

        $this->scheduler->waitStream($stream, PollEvent::Read, $this->normalizeOptionalDuration($timeout));
    }

    /**
     * Suspends the current fiber via PHP 8.6 `\Io\Poll\Context` until `$stream` becomes writable.
     *
     * @param resource $stream
     */
    public function waitWritable(mixed $stream, Duration|int|float|null $timeout = null): void
    {
        if (self::isInSwooleCoroutine()) {
            return;
        }

        $this->scheduler->waitStream($stream, PollEvent::Write, $this->normalizeOptionalDuration($timeout));
    }

    /**
     * Non-blocking stream read multiplexed across Fibers (`\Io\Poll\Context`) or Swoole coroutines.
     *
     * @param resource $stream
     */
    public function readStreamAsync(
        mixed $stream,
        int $maxBytes = 0,
        Duration|int|float|null $timeout = null,
    ): string {
        if (!\is_resource($stream)) {
            throw new InvalidArgumentException('Expected a valid stream resource in readStreamAsync().');
        }

        $chunkLen = $maxBytes > 0 ? \clamp($maxBytes, 1, 16_777_216) : $this->streamChunkBytes;
        if (!self::isInSwooleCoroutine()) {
            \stream_set_blocking($stream, false);
            $chunk = \fread($stream, $chunkLen);
            if (\is_string($chunk) && $chunk !== '') {
                return $chunk;
            }
            if (\feof($stream)) {
                return '';
            }
            $this->waitReadable($stream, $timeout);
        }

        $data = \fread($stream, $chunkLen);
        return \is_string($data) ? $data : '';
    }

    /**
     * Non-blocking full stream write multiplexed across Fibers (`\Io\Poll\Context`) or Swoole coroutines.
     *
     * @param resource $stream
     */
    public function writeStreamAsync(
        mixed $stream,
        string $data,
        Duration|int|float|null $timeout = null,
    ): int {
        if (!\is_resource($stream)) {
            throw new InvalidArgumentException('Expected a valid stream resource in writeStreamAsync().');
        }

        $total = \strlen($data);
        if ($total === 0) {
            return 0;
        }

        if (!self::isInSwooleCoroutine()) {
            \stream_set_blocking($stream, false);
        }

        $written = 0;
        while ($written < $total) {
            $slice = \substr($data, $written, $this->streamChunkBytes);
            $bytes = \fwrite($stream, $slice);
            if ($bytes === false) {
                throw new AsyncException('Stream write failed in writeStreamAsync().');
            }
            if ($bytes === 0) {
                $this->waitWritable($stream, $timeout);
                continue;
            }
            $written += $bytes;
            if ($written < $total) {
                $this->yieldNow();
            }
        }

        return $written;
    }

    /**
     * Asynchronously reads a file in chunks while cooperatively yielding between chunks,
     * with deterministic `try / finally` descriptor cleanup.
     */
    public function readFileAsync(string $filePath, int $chunkBytes = 0): string
    {
        if (!\is_file($filePath)) {
            \clearstatcache(true, $filePath);
            if (!\is_file($filePath)) {
                throw new AsyncException("File not found or not readable: {$filePath}");
            }
        }

        $buffer = '';
        foreach ($this->streamFileChunks($filePath, $chunkBytes) as $chunk) {
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * Reads multiple files concurrently across Fibers / Swoole coroutines (`path => contents`).
     *
     * @param list<string> $filePaths
     * @return array<string, string>
     */
    public function readFilesAsync(array $filePaths, int $concurrency = 8): array
    {
        $tasks = [];
        foreach ($filePaths as $path) {
            $tasks[$path] = fn(): string => $this->readFileAsync($path);
        }

        return $this->all($tasks, \clamp($concurrency, 1, 256));
    }

    /**
     * Streams a potentially huge file chunk-by-chunk with `O(1)` memory footprint,
     * cooperatively yielding to sibling fibers/coroutines after every chunk!
     *
     * @return Generator<int, string>
     */
    public function streamFileChunks(string $filePath, int $chunkBytes = 0): Generator
    {
        $size = $chunkBytes > 0 ? \clamp($chunkBytes, 1024, 16_777_216) : $this->streamChunkBytes;
        $handle = \fopen($filePath, 'rb');
        if (!\is_resource($handle)) {
            throw new AsyncException("Unable to open file for async streaming: {$filePath}");
        }

        try {
            $index = 0;
            while (!\feof($handle)) {
                $chunk = \fread($handle, $size);
                if ($chunk === false) {
                    throw new AsyncException("Error reading chunk from file: {$filePath}");
                }
                if ($chunk !== '') {
                    yield $index++ => $chunk;
                }
                if (!\feof($handle)) {
                    $this->yieldNow();
                }
            }
        } finally {
            \fclose($handle);
        }
    }

    /**
     * Asynchronously writes `$contents` to `$filePath` in cooperative chunks (atomically via temporary file when `$atomic = true`),
     * strictly checking PHP 8.6 `fclose()` flush status.
     */
    public function writeFileAsync(string $filePath, string $contents, bool $atomic = true): int
    {
        $dir = \dirname($filePath);
        if (!\is_dir($dir) && !\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new AsyncException("Unable to create parent directory: {$dir}");
        }

        $target = $atomic
            ? ($filePath . '.tmp.' . \bin2hex(\random_bytes(4)))
            : $filePath;

        $handle = \fopen($target, 'wb');
        if (!\is_resource($handle)) {
            throw new AsyncException("Unable to open target file for async write: {$target}");
        }

        $written = 0;
        $closedCleanly = false;
        $total = \strlen($contents);

        try {
            while ($written < $total) {
                $part = \substr($contents, $written, $this->streamChunkBytes);
                $bytes = \fwrite($handle, $part);
                if ($bytes === false) {
                    throw new AsyncException("Failed writing chunk to file: {$target}");
                }
                $written += $bytes;
                if ($written < $total) {
                    $this->yieldNow();
                }
            }
        } finally {
            $closedCleanly = \fclose($handle);
        }

        if (!$closedCleanly) {
            if ($atomic && \is_file($target)) {
                \unlink($target);
            }
            throw new AsyncException("Stream flush/close failed for file: {$target}");
        }

        if ($atomic && !\rename($target, $filePath)) {
            if (\is_file($target)) {
                \unlink($target);
            }
            throw new AsyncException("Atomic rename failed for file: {$filePath}");
        }

        \clearstatcache(true, $filePath);

        return $written;
    }

    /**
     * Ensures `$operation` runs inside an active async event loop (reusing the current Fiber / Swoole coroutine
     * when already nested, or entering `run()` automatically when called from synchronous user code!).
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function executeWithinAsyncScope(callable $operation): mixed
    {
        if (!$this->enabled || self::isInAsyncContext()) {
            return $operation();
        }

        return $this->run(static fn(): mixed => $operation());
    }

    /**
     * @template T
     * @param callable(self): T $entrypoint
     * @return T
     */
    private function runInsideSwooleScheduler(callable $entrypoint): mixed
    {
        /** @var \ArrayObject<string, mixed> $box */
        $box = new \ArrayObject(['result' => null, 'caught' => null]);

        $runner = function () use ($entrypoint, $box): void {
            try {
                $box['result'] = $entrypoint($this);
            } catch (Throwable $e) {
                $box['caught'] = $e;
            }
        };

        $swooleRunFn = \implode('\\', ['Swoole', 'Coroutine', 'run']);
        $openSwooleCoro = self::resolveSwooleCoroutineClass();

        if (\function_exists($swooleRunFn)) {
            $swooleRunFn($runner);
        } elseif ($openSwooleCoro !== null && \method_exists($openSwooleCoro, 'run')) {
            $openSwooleCoro::run($runner);
        } else {
            $rootFuture = $this->scheduler->spawn(fn(): mixed => $entrypoint($this));
            return $this->scheduler->awaitFuture($rootFuture);
        }

        if ($box['caught'] instanceof Throwable) {
            throw $box['caught'];
        }

        return $box['result'];
    }

    /**
     * @template T
     * @param Closure(): T $task
     * @return Future<T>
     */
    private function spawnInSwooleCoroutine(Closure $task): Future
    {
        $coroCls = self::resolveSwooleCoroutineClass();
        $chanCls = self::resolveSwooleChannelClass();

        if ($coroCls === null || $chanCls === null) {
            return $this->scheduler->spawn($task);
        }

        $doneChannel = new $chanCls(1);

        /** @var Future<T> $future */
        $future = new Future();
        $future->bindCallbacks(
            awaiter: static function (?Duration $timeout) use ($doneChannel, $future): mixed {
                if ($future->isSettled()) {
                    return null;
                }
                $sec = $timeout !== null ? ($timeout->seconds + ($timeout->nanoseconds / 1_000_000_000)) : -1.0;
                $signal = $doneChannel->pop($sec);
                if ($signal === false && $timeout !== null && $future->isPending()) {
                    $ms = \round(($timeout->seconds * 1000) + ($timeout->nanoseconds / 1_000_000), 2);
                    $future->reject(new AsyncTimeoutException("Swoole coroutine timed out after {$ms} ms."));
                }
                return null;
            },
        );

        $coroCls::create(static function () use ($task, $future, $doneChannel): void {
            try {
                if (!$future->isCancelled()) {
                    $future->resolve($task());
                }
            } catch (Throwable $e) {
                $future->reject($e);
            } finally {
                if (\method_exists($doneChannel, 'push')) {
                    $doneChannel->push(true, 0.001);
                }
            }
        });

        return $future;
    }

    /**
     * @return Future<mixed>
     */
    private function createBridgeFuture(): Future
    {
        if ($this->activeDriver() === AsyncDriver::Swoole && self::isInSwooleCoroutine()) {
            $chanCls = self::resolveSwooleChannelClass();
            if ($chanCls !== null) {
                $signalChan = new $chanCls(1);
                $f = new Future();
                $f->bindCallbacks(
                    awaiter: static function (?Duration $timeout) use ($signalChan, $f): mixed {
                        if ($f->isSettled()) {
                            return null;
                        }
                        $sec = $timeout !== null ? ($timeout->seconds + ($timeout->nanoseconds / 1_000_000_000)) : -1.0;
                        $ok = $signalChan->pop($sec);
                        if ($ok === false && $timeout !== null && $f->isPending()) {
                            $f->reject(new AsyncTimeoutException('Async operation timed out.'));
                        }
                        return null;
                    },
                );
                $f->onSettle(static function () use ($signalChan): void {
                    if (\method_exists($signalChan, 'push')) {
                        $signalChan->push(true, 0.001);
                    }
                });
                return $f;
            }
        }

        return $this->scheduler->createAwaitableFuture();
    }

    public static function resolveSwooleCoroutineClass(): ?string
    {
        $swoole = \implode('\\', ['Swoole', 'Coroutine']);
        if (\class_exists($swoole)) {
            return $swoole;
        }
        $openSwoole = \implode('\\', ['OpenSwoole', 'Coroutine']);
        if (\class_exists($openSwoole)) {
            return $openSwoole;
        }

        return null;
    }

    public static function resolveSwooleChannelClass(): ?string
    {
        $swooleChan = \implode('\\', ['Swoole', 'Coroutine', 'Channel']);
        if (\class_exists($swooleChan)) {
            return $swooleChan;
        }
        $openSwooleChan = \implode('\\', ['OpenSwoole', 'Coroutine', 'Channel']);
        if (\class_exists($openSwooleChan)) {
            return $openSwooleChan;
        }

        return null;
    }

    private function normalizeDuration(Duration|int|float $duration): Duration
    {
        if ($duration instanceof Duration) {
            return $duration;
        }
        if (\is_int($duration)) {
            return Duration::fromSeconds(\max(0, $duration));
        }

        return Duration::fromNanoseconds((int) \max(0.0, \round($duration * 1_000_000_000)));
    }

    private function normalizeOptionalDuration(Duration|int|float|null $duration): ?Duration
    {
        return $duration !== null ? $this->normalizeDuration($duration) : null;
    }
}
