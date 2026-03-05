<?php

/**
 * Szablon konfiguracji bazy danych
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
            'dsn'      => 'mysql:host='. env('DB_HOST', '127.0.0.1') .';port='. env('DB_PORT', 3306) .';dbname='. env('DB_NAME', 'Atom') . ';charset='. env('DB_CHARSET', 'utf8mb4'),
            'driver'   => 'mysql',
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', 3306),
            'dbname'   => env('DB_NAME', 'Atom'),
            'user'     => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset'  => env('DB_CHARSET', 'utf8mb4'), //'utf8mb4',
            'prefix'   => env('DB_PREFIX', ''),

            // Opcje specyficzne dla PDO (Przyszłościowe)
            'options'  => [
                // Zgłaszanie wyjątków zamiast błędów cichych
                PDO::ATTR_ERRMODE            => env('DB_ERRMODE', PDO::ERRMODE_EXCEPTION),
                // Format zwracania danych (domyślnie tablica asocjacyjna)
                PDO::ATTR_DEFAULT_FETCH_MODE => env('DB_DEFAULT_FETCH_MODE', PDO::FETCH_ASSOC),
                // Wyłączenie emulacji przygotowanych zapytań (bezpieczeństwo)
                PDO::ATTR_EMULATE_PREPARES   => env('DB_EMULATE_PREPARES', false),
                // Trwałe połączenie (opcjonalnie)
                PDO::ATTR_PERSISTENT         => env('DB_PERSISTENT', false),
            ],

            // Ustawienia dodatkowe (np. dla migracji lub cache)
            'engine'    => 'InnoDB',
            'collation' => env('DB_COLLATION', 'utf8mb4_0900_ai_ci'),
            'cluster'   => env('DB_CLUSTER', false),
            'atom_replication' => env('DB_ATOM_REPLICATION', false),
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
        'cluster' => false,

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
            'host'     => '127.0.0.1',

            /**
             * Port of the Redis server
             *
             * @var int
             */
            'port'     => 6379,

            /**
             * Database number of the Redis server
             *
             * @var int
             */
            'database' => 0,
        ],
    ],
    
];
