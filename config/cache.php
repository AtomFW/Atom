<?php

declare(strict_types=1);

/**
 * Cache system configuration template
 */

return [
    /**
     * The default cache driver
     * 
     * Available drivers:
     * file - local file cache
     * redis - remote redis cache
     * 
     * @var string
     */
    'default' => env('CACHE_DRIVER', 'file'),

    /**
     * The available cache drivers
     * 
     * @var array
     */
    'choices' => [
        /**
         * The local file cache driver
         * 
         * This driver stores cache data in a local file.
         * 
         * @var array
         */
        'file' => [
            'driver' => 'file',
            // 'path' => realpath(env('CACHE_FILE_PATH', '../runtime/cache/aplication')), // Path to the cache file
            // 'path' => env('CACHE_FILE_PATH', '../runtime/cache/aplication'), // Path to the cache file
            'path' => realpath(env('CACHE_FILE_PATH', __DIR__ . '/../runtime/cache/')), // Path to the cache file
        ],

        /**
         * The remote Redis cache driver
         * 
         * This driver stores cache data in a remote Redis server.
         * 
         * @var array
         */
        'redis' => [
            'driver' => 'redis',
            'host' => env('CACHE_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')), // The host of the Redis server
            'port' => (int) env('CACHE_REDIS_PORT', env('REDIS_PORT', 6379)), // The port of the Redis server
            'username' => env('CACHE_REDIS_USERNAME', env('REDIS_USERNAME', null)), // Optional Redis ACL username
            'password' => env('CACHE_REDIS_PASSWORD', env('REDIS_PASSWORD', null)), // Optional Redis password
            'database' => (int) env('CACHE_REDIS_DATABASE', 0), // The database number of the Redis server
            'timeout' => (float) env('CACHE_REDIS_TIMEOUT', 1.5), // Connection timeout in seconds
            'readTimeout' => (float) env('CACHE_REDIS_READ_TIMEOUT', 1.5), // Read timeout in seconds
        ],

        /**
         * Adaptive PHP RAM cache driver (`Atom\Cache\MemoryCacheManager`).
         *
         * Automatically selects the fastest available backend in order:
         * `apcu` -> `sysv` (System V shared memory + semaphores) -> `memory` (bounded PHP RAM).
         *
         * @var array
         */
        'memory' => [
            'driver' => 'memory',
            'backend' => env('CACHE_MEMORY_BACKEND', 'auto'),
            'maxItems' => (int) env('CACHE_MEMORY_MAX_ITEMS', 4096),
            'shmSize' => (int) env('CACHE_MEMORY_SHM_SIZE', 1048576),
            'autoPruneInterval' => (int) env('CACHE_MEMORY_AUTO_PRUNE', 64),
        ],
    ],

    /**
     * Default cache item time-to-live (TTL) in seconds.
     *
     * Prevents unbounded cache growth and memory exhaustion.
     *
     * @var int
     */
    'defaultTtl' => (int) env('CACHE_DEFAULT_TTL', 3600),

    /**
     * Maximum serialized payload size allowed for a single cache entry in bytes (defaults to 1 MB).
     *
     * @var int
     */
    'maxItemBytes' => (int) env('CACHE_MAX_ITEM_BYTES', 1048576),

    /**
     * Distributed cache lock settings for safe concurrency across microservices and Swoole workers.
     *
     * @var array
     */
    'lock' => [
        /**
         * Lock store driver ('redis' recommended for stateless microservices).
         *
         * @var string
         */
        'driver' => env('CACHE_LOCK_DRIVER', 'redis'),

        /**
         * Default lock TTL in seconds to prevent deadlocks if a worker crashes.
         *
         * @var int
         */
        'ttlSeconds' => (int) env('CACHE_LOCK_TTL', 15),
    ],

    /**
     * Cache prefix
     * 
     * This is the prefix that will be added to all cache keys.
     * The prefix is generated from the APP_NAME environment variable.
     * 
     * @var string
     */
    'prefix' => env('CACHE_PREFIX', str_replace(' ', '_',  env('APP_NAME', 'Atom'))) . '_cache_',
];
