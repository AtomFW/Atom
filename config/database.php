<?php

declare(strict_types=1);

use Pdo\Mysql;

/**
 * Database configuration template
 */

return [
    'default' => env('DB_DRIVER', 'mysql'),

    /**
     * The choices of database drivers that the application can use.
     *
     * This is a list of database drivers that the application can use.
     * Each driver has its own configuration options.
     * 
     * supported drivers: mysql, sqlite (future)
     *
     * @var array
     */
    'choices' => [
        /**
         * The MySQL database driver.
         *
         * This is the configuration for the MySQL database driver.
         *
         * @var array
         */
        'mysql' => [
            'dsn'      => 'mysql:host='. env('DB_HOST', '127.0.0.1') .';port='. env('DB_PORT', 3306) .';dbname='. env('DB_DATABASE', 'Atom') . ';charset='. env('DB_CHARSET', 'utf8mb4'),
            'driver'   => 'mysql',
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', 3306),
            'dbname'   => env('DB_DATABASE', 'Atom'),
            'user'     => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset'  => env('DB_CHARSET', 'utf8mb4'),
            'prefix'   => env('DB_PREFIX', ''),

            // PDO-specific options
            'options'  => [
                // Throwing exceptions instead of silent errors
                PDO::ATTR_ERRMODE            => env('DB_ERRMODE', PDO::ERRMODE_EXCEPTION),
                // Data return format (associative array by default)
                PDO::ATTR_DEFAULT_FETCH_MODE => env('DB_DEFAULT_FETCH_MODE', PDO::FETCH_ASSOC),
                // Disabling emulation of prepared queries
                PDO::ATTR_EMULATE_PREPARES   => env('DB_EMULATE_PREPARES', false),
                // Returning native types without stringifying numeric values
                PDO::ATTR_STRINGIFY_FETCHES  => env('DB_STRINGIFY_FETCHES', false),
                // Permanent connection (keep false under Swoole connection pool)
                PDO::ATTR_PERSISTENT         => env('DB_PERSISTENT', false),
                // Strict connection timeout in seconds
                PDO::ATTR_TIMEOUT            => (int) env('DB_TIMEOUT', 5),
                // Exception mode
                // PDO::ERRMODE_EXCEPTION       => env('DB_ERRMODE_EXCEPTION', false),
                Mysql::ATTR_INIT_COMMAND => 'SET NAMES '. env('DB_CHARSET', 'utf8mb4') .', time_zone = \'+00:00\'',
            ],

            // Additional settings (e.g. for migration or cache)
            'engine'    => 'InnoDB',
            'collation' => env('DB_COLLATION', 'utf8mb4_0900_ai_ci'),
            'version'   => env('DB_VERSION', '9.6'),
            'cluster'   => env('DB_CLUSTER', false),
            'atom_replication' => env('DB_ATOM_REPLICATION', false),

            /**
             * Connection pool configuration for Swoole / coroutine workers and microservices.
             *
             * @var array
             */
            'pool' => [
                'on' => (bool) env('DB_POOL_ON', false),
                'minConnections' => (int) env('DB_POOL_MIN', 2),
                'maxConnections' => (int) env('DB_POOL_MAX', 32),
                'waitTimeoutMs' => (int) env('DB_POOL_WAIT_TIMEOUT_MS', 3000),
                'maxIdleTimeSeconds' => (int) env('DB_POOL_MAX_IDLE_SECONDS', 60),
                'heartbeatIntervalSeconds' => (int) env('DB_POOL_HEARTBEAT_SECONDS', 30),
            ],
        ],
        'sqlite' => [
            // 'dsn'      => 'sqlite:'. env('DB_PATH', database_path('database.sqlite')),
            // 'driver'   => 'sqlite',
            // 'path'     => env('DB_PATH', database_path('database.sqlite')),

            // // Opcje specyficzne dla PDO (Przyszłościowe)
            // 'options'  => [
            //     PDO::ATTR_ERRMODE            => env('DB_ERRMODE', PDO::ERRMODE_EXCEPTION),
            //     PDO::ATTR_DEFAULT_FETCH_MODE => env('DB_DEFAULT_FETCH_MODE', PDO::FETCH_ASSOC),
            //     PDO::ATTR_EMULATE_PREPARES   => env('DB_EMULATE_PREPARES', false),
            //     PDO::ATTR_PERSISTENT         => env('DB_PERSISTENT', false),
            // ],

            // 'cluster'   => env('DB_CLUSTER', false),
            // 'atom_replication' => env('DB_ATOM_REPLICATION', false),
        ],
    ],

    /**
     * Configuration for migrations
     *
     * @var array
     */
    'migrations' => [
        /**
         * Table to store migrations
         *
         * @var string
         */
        'table' => 'migrations',

        /**
         * Whether to update the timestamp of the migration
         *
         * @var bool
         */
        'update_timestamp' => true,
    ],

    /**
     * Redis configuration
     *
     * @var array
     */
    'redis' => [
        /**
         * Is Redis a cluster?
         *
         * @var bool
         */
        'cluster' => (bool) env('REDIS_CLUSTER', false),

        /**
         * Default Redis settings
         *
         * @var array
         */
        'default' => [
            /**
             * Host of the Redis server
             *
             * @var string
             */
            'host'     => env('REDIS_HOST', '127.0.0.1'),

            /**
             * Port of the Redis server
             *
             * @var int
             */
            'port'     => (int) env('REDIS_PORT', 6379),

            /**
             * Optional Redis authentication username (ACL)
             *
             * @var string|null
             */
            'username' => env('REDIS_USERNAME', null),

            /**
             * Optional Redis authentication password
             *
             * @var string|null
             */
            'password' => env('REDIS_PASSWORD', null),

            /**
             * Database number of the Redis server
             *
             * @var int
             */
            'database' => (int) env('REDIS_DATABASE', 0),

            /**
             * Connection timeout in seconds
             *
             * @var float
             */
            'timeout'  => (float) env('REDIS_TIMEOUT', 1.5),

            /**
             * Read/write stream timeout in seconds
             *
             * @var float
             */
            'readTimeout' => (float) env('REDIS_READ_TIMEOUT', 1.5),

            /**
             * Key prefix for multi-service isolation
             *
             * @var string
             */
            'prefix'   => env('REDIS_PREFIX', str_replace(' ', '_', (string) env('APP_NAME', 'Atom')) . '_'),

            /**
             * Connection pool configuration for Swoole coroutines
             *
             * @var array
             */
            'pool'     => [
                'on' => (bool) env('REDIS_POOL_ON', false),
                'minConnections' => (int) env('REDIS_POOL_MIN', 2),
                'maxConnections' => (int) env('REDIS_POOL_MAX', 64),
                'waitTimeoutMs' => (int) env('REDIS_POOL_WAIT_TIMEOUT_MS', 2000),
                'maxIdleTimeSeconds' => (int) env('REDIS_POOL_MAX_IDLE_SECONDS', 60),
            ],
        ],
    ],
    
];
