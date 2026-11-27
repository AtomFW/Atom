<?php

declare(strict_types=1);

/**
 * Asynchronous Fibers, PHP 8.6 `\Io\Poll\Context` & Swoole Coroutines configuration template (`Atom\Async\Async`)
 */

return [
    /**
     * Master switch enabling asynchronous execution acceleration across Atom.
     *
     * @var bool
     */
    'on' => (bool) env('ASYNC_ON', true),

    /**
     * Default asynchronous execution engine driver.
     *
     * - 'auto'   : Uses native C Swoole / OpenSwoole coroutines when available, falling back seamlessly
     *              to PHP 8.6 `\Fiber` + `\Io\Poll\Context` (`epoll`/`kqueue`/`WSAPoll`) scheduler.
     * - 'swoole' : Forces Swoole / OpenSwoole coroutine runtime (falls back to 'fiber' unless `strictDriver` is true).
     * - 'fiber'  : Uses pure PHP 8.6 `\Fiber` + `\Io\Poll\Context` cooperative scheduler.
     *
     * @var string
     */
    'default' => (string) env('ASYNC_DRIVER', 'auto'),

    /**
     * Available async runtime driver configurations.
     *
     * @var array<string, array<string, mixed>>
     */
    'choices' => [
        /**
         * Adaptive auto-detection driver (Swoole Coroutine -> PHP 8.6 Fiber + Io\Poll).
         *
         * @var array<string, mixed>
         */
        'auto' => [
            'driver' => 'auto',
            'enableSwooleCliRun' => (bool) env('ASYNC_SWOOLE_CLI_RUN', true),
            'pollTickMs' => (int) env('ASYNC_POLL_TICK_MS', 5),
        ],

        /**
         * Dedicated Swoole / OpenSwoole C coroutine runtime configuration.
         *
         * @var array<string, mixed>
         */
        'swoole' => [
            'driver' => 'swoole',
            'enablePreemptiveHooks' => (bool) env('ASYNC_SWOOLE_HOOK_ALL', true),
            'enableSwooleCliRun' => (bool) env('ASYNC_SWOOLE_CLI_RUN', true),
            'maxConcurrency' => (int) env('ASYNC_SWOOLE_MAX_CONCURRENCY', 256),
        ],

        /**
         * Native PHP 8.6 `\Fiber` + `\Io\Poll\Context` event-loop scheduler configuration.
         *
         * @var array<string, mixed>
         */
        'fiber' => [
            'driver' => 'fiber',
            'pollTickMs' => (int) env('ASYNC_POLL_TICK_MS', 5),
            'maxActiveFibers' => (int) env('ASYNC_MAX_FIBERS', 2048),
        ],
    ],

    /**
     * Default concurrency, I/O streaming, channel, and timeout limits (Swoole & memory-leak safe).
     *
     * @var array<string, mixed>
     */
    'limits' => [
        /**
         * Default bounded concurrency for `map()`, `filter()`, `each()`, and `readFilesAsync()`.
         *
         * @var int
         */
        'defaultConcurrency' => (int) env('ASYNC_DEFAULT_CONCURRENCY', 32),

        /**
         * Default operation timeout in milliseconds (`0` = no implicit timeout unless specified).
         *
         * @var int
         */
        'defaultTimeoutMs' => (int) env('ASYNC_DEFAULT_TIMEOUT_MS', 15000),

        /**
         * Default bounded buffer capacity for `Channel` instances.
         *
         * @var int
         */
        'defaultChannelCapacity' => (int) env('ASYNC_CHANNEL_CAPACITY', 64),

        /**
         * Chunk size in bytes for non-blocking file and socket streaming (`64 KiB` -> `O(1)` RAM).
         *
         * @var int
         */
        'streamChunkBytes' => (int) env('ASYNC_STREAM_CHUNK_BYTES', 65536),

        /**
         * Maximum named mutex locks cached simultaneously before pruning idle locks.
         *
         * @var int
         */
        'maxNamedLocks' => (int) env('ASYNC_MAX_NAMED_LOCKS', 512),
    ],
];
