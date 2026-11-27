<?php

declare(strict_types=1);

use Pdo\Mysql;

/**
 * Database configuration template
 */

return [
    /**
     * Disable database connections when running in stateless `noDB` or `microservice` mode.
     *
     * Defaults to false.
     *
     * @var bool
     */
    'noDB' => (bool) env('DB_NO_DB', (bool) env('APP_NO_DB', false)),

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

        /**
         * Unified remote Atom Cluster database driver (`DB_DRIVER=clusterRemote`).
         *
         * Allows all Atom nodes in a cluster pool to share a remote primary SQL database
         * using the exact same `Atom\DataBase\Database` interface.
         *
         * @var array<string, mixed>
         */
        'clusterRemote' => [
            'dsn' => (string) env(
                'CLUSTER_REMOTE_DB_DSN',
                'mysql:host=' . env('CLUSTER_REMOTE_DB_HOST', env('DB_HOST', '127.0.0.1'))
                . ';port=' . env('CLUSTER_REMOTE_DB_PORT', env('DB_PORT', 3306))
                . ';dbname=' . env('CLUSTER_REMOTE_DB_NAME', env('DB_DATABASE', 'Atom'))
                . ';charset=' . env('CLUSTER_REMOTE_DB_CHARSET', 'utf8mb4')
            ),
            'driver' => (string) env('CLUSTER_REMOTE_DB_DRIVER', 'mysql'),
            'host' => (string) env('CLUSTER_REMOTE_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => (int) env('CLUSTER_REMOTE_DB_PORT', env('DB_PORT', 3306)),
            'dbname' => (string) env('CLUSTER_REMOTE_DB_NAME', env('DB_DATABASE', 'Atom')),
            'user' => (string) env('CLUSTER_REMOTE_DB_USER', env('DB_USERNAME', 'root')),
            'password' => (string) env('CLUSTER_REMOTE_DB_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => (string) env('CLUSTER_REMOTE_DB_CHARSET', 'utf8mb4'),
            'prefix' => (string) env('CLUSTER_REMOTE_DB_PREFIX', env('DB_PREFIX', '')),
            'cluster' => true,
            'atom_replication' => true,
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => (int) env('CLUSTER_REMOTE_DB_TIMEOUT', 5),
            ],
            'pool' => [
                'on' => (bool) env('CLUSTER_REMOTE_DB_POOL_ON', false),
                'minConnections' => (int) env('CLUSTER_REMOTE_DB_POOL_MIN', 2),
                'maxConnections' => (int) env('CLUSTER_REMOTE_DB_POOL_MAX', 32),
                'waitTimeoutMs' => (int) env('CLUSTER_REMOTE_DB_POOL_WAIT_MS', 3000),
                'maxIdleTimeSeconds' => (int) env('CLUSTER_REMOTE_DB_POOL_IDLE_SEC', 60),
                'heartbeatIntervalSeconds' => 30,
            ],
        ],
    ],

    /**
     * Configuration for core and application SQL migrations.
     *
     * @var array
     */
    'migrations' => [
        /**
         * Automatically execute pending SQL migrations during kernel startup.
         *
         * Disabled by default (`false`). Ignored when `noDB` or `microservice` mode is active.
         *
         * @var bool
         */
        'autoMigrate' => (bool) env('DB_AUTO_MIGRATE', (bool) env('APP_AUTO_MIGRATE', false)),

        /**
         * Table to store applied core and application migrations.
         *
         * @var string
         */
        'table' => 'migrations',

        /**
         * Whether to update the timestamp of the migration.
         *
         * @var bool
         */
        'update_timestamp' => true,

        /**
         * Directory containing Atom's internal core SQL migration files (`at0001_*.sql`).
         *
         * @var string
         */
        'corePath' => __DIR__ . '/../Atom/DataBase/migrations',

        /**
         * Directory containing application-specific migration files (`/migrations`).
         *
         * @var string
         */
        'appPath' => __DIR__ . '/../migrations',
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
