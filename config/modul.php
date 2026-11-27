<?php

declare(strict_types=1);

/**
 * Modules configuration template for `Atom\Modules\Modul`.
 *
 * Defines active modules in `Atom/Modules/`, strictly required modules that must remain
 * enabled for the application to boot, and module-specific runtime options.
 */
return [
    /**
     * Map of Atom modules and their activation status (`'on' => bool`).
     *
     * @var array<string, array{on: bool}>
     */
    'enabled' => [
        /**
         * String manipulation utilities module (`Atom/Modules/String`).
         *
         * @var array{on: bool}
         */
        'String' => [
            'on' => (bool) env('MODULE_STRING', true),
        ],

        /**
         * Cryptographically secure identifier and token generator (`Atom/Modules/NameGenerator`).
         *
         * @var array{on: bool}
         */
        'NameGenerator' => [
            'on' => (bool) env('MODULE_NAME_GENERATOR', true),
        ],

        /**
         * Graphical PHP exception and error inspector active when `APP_DEBUG=true` (`Atom/Modules/ErrorDebugger`).
         *
         * @var array{on: bool}
         */
        'ErrorDebugger' => [
            'on' => (bool) env('MODULE_ERROR_DEBUGGER', true),
        ],

        /**
         * Internal user authentication, session login/logout, and `users` SQL module (`Atom/Modules/UserAuth`).
         *
         * @var array{on: bool}
         */
        'UserAuth' => [
            'on' => (bool) env('MODULE_USER_AUTH', true),
        ],

        /**
         * Ultra-fast AEAD-encrypted inter-Atom binary data exchange module (`Atom/Modules/AtomExchange`).
         *
         * @var array{on: bool}
         */
        'AtomExchange' => [
            'on' => (bool) env('MODULE_ATOM_EXCHANGE', true),
        ],

        /**
         * Distributed Atom cluster auto-balancer, real-time sync, Redis router & Supervisor (`Atom/Modules/AtomCluster`).
         *
         * @var array{on: bool}
         */
        'AtomCluster' => [
            'on' => (bool) env('MODULE_ATOM_CLUSTER', (bool) env('CLUSTER_ON', false)),
        ],

        /**
         * OAuth 2.0 / PKCE authorization & Bearer token module integrated with `UserAuth` & Redis (`Atom/Modules/OAuth`).
         *
         * @var array{on: bool}
         */
        'OAuth' => [
            'on' => (bool) env('MODULE_OAUTH', true),
        ],

        /**
         * Model Context Protocol (MCP) JSON-RPC agent management server (`Atom/Modules/McpServer`).
         *
         * @var array{on: bool}
         */
        'McpServer' => [
            'on' => (bool) env('MODULE_MCP_SERVER', (bool) env('MCP_SERVER_ON', false)),
        ],

        /**
         * Bottleneck & CPU/RAM/Disk profiler with visual flow, AI agent export, and Timonix Cloud telemetry (`Atom/Modules/PerformanceProfiler`).
         *
         * @var array{on: bool}
         */
        'PerformanceProfiler' => [
            'on' => (bool) env('MODULE_PERFORMANCE_PROFILER', (bool) env('PROFILER_ON', false)),
        ],

        /**
         * Visual HTTP request/response origin, payload, file-trail, and status reason inspector (`Atom/Modules/TrafficInspector`).
         *
         * @var array{on: bool}
         */
        'TrafficInspector' => [
            'on' => (bool) env('MODULE_TRAFFIC_INSPECTOR', (bool) env('TRAFFIC_INSPECTOR_ON', false)),
        ],
    ],

    /**
     * Modules strictly required by the application.
     *
     * Disabling any module listed here causes `Atom\Modules\Modul` to throw an English
     * `MissingRequiredModuleException` during startup.
     *
     * @var list<string>
     */
    'required' => [
        'String',
    ],

    /**
     * Module-specific options passed to module constructors (`var_export`-safe scalar/array values).
     *
     * @var array<string, array<string, mixed>>
     */
    'options' => [
        /**
         * Options for `String` module.
         *
         * @var array<string, mixed>
         */
        'String' => [
            'prefix' => (string) env('MODULE_STRING_PREFIX', ''),
        ],

        /**
         * Options for `ErrorDebugger` module (`-5` / `+5` context lines by default).
         *
         * @var array<string, mixed>
         */
        'ErrorDebugger' => [
            'contextLines' => (int) env('MODULE_ERROR_DEBUGGER_CONTEXT_LINES', 5),
            'convertPhpErrors' => (bool) env('MODULE_ERROR_DEBUGGER_CONVERT_ERRORS', true),
        ],

        /**
         * Options for `UserAuth` module.
         *
         * @var array<string, mixed>
         */
        'UserAuth' => [
            'userModel' => (string) env('AUTH_MODEL', App\Models\User::class),
            'hashing' => [
                'driver' => (string) env('AUTH_HASH_DRIVER', 'argon2id'),
                'memoryCost' => (int) env('AUTH_HASH_MEMORY_COST', 65536),
                'timeCost' => (int) env('AUTH_HASH_TIME_COST', 4),
                'bcryptCost' => (int) env('AUTH_HASH_BCRYPT_COST', 12),
            ],
        ],
    ],
];
