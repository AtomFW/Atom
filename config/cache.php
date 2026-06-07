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
            'host' => env('CACHE_REDIS_HOST', '127.0.0.1'), // The host of the Redis server
            'port' => env('CACHE_REDIS_PORT', 6379), // The port of the Redis server
            'database' => env('CACHE_REDIS_DATABASE', 0), // The database number of the Redis server
        ]
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
