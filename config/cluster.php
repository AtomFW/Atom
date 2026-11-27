<?php

declare(strict_types=1);

/**
 * Atom Cluster, Auto Load-Balancing, Real-Time Synchronization, Remote Database & Supervisor configuration template
 */

return [
    /**
     * Master switch enabling the Atom Cluster module (`Atom\Modules\AtomCluster`).
     *
     * Defaults to false for single-node deployments; enable in multi-node scaled environments.
     *
     * @var bool
     */
    'on' => (bool) env('CLUSTER_ON', false),

    /**
     * Logical cluster identifier shared by all participating Atom nodes.
     *
     * @var string
     */
    'clusterId' => (string) env('CLUSTER_ID', 'atom-cluster-main'),

    /**
     * Default transport driver for cluster communication, state sync, and traffic routing.
     *
     * When `'redis'` is selected (or when `redis.on` is true and Redis is reachable),
     * 100% of cluster heartbeats, real-time state updates, and internal balanced traffic
     * flow through Redis for ultra-low latency.
     *
     * Available drivers: 'redis', 'direct', 'memory'
     *
     * @var string
     */
    'default' => (string) env('CLUSTER_DRIVER', 'redis'),

    /**
     * Available cluster transport driver configurations.
     *
     * @var array<string, array<string, mixed>>
     */
    'choices' => [
        /**
         * Ultra-fast Redis cluster bus driver (preferred when Redis is enabled and online).
         *
         * @var array<string, mixed>
         */
        'redis' => [
            'driver' => 'redis',
            'on' => (bool) env('CLUSTER_REDIS_ON', true),
            'routeAllTrafficViaRedis' => (bool) env('CLUSTER_REDIS_ROUTE_ALL', true),
            'host' => (string) env('CLUSTER_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
            'port' => (int) env('CLUSTER_REDIS_PORT', env('REDIS_PORT', 6379)),
            'username' => env('CLUSTER_REDIS_USERNAME', env('REDIS_USERNAME', null)),
            'password' => env('CLUSTER_REDIS_PASSWORD', env('REDIS_PASSWORD', null)),
            'database' => (int) env('CLUSTER_REDIS_DATABASE', env('REDIS_DATABASE', 0)),
            'prefix' => (string) env('CLUSTER_REDIS_PREFIX', 'atom_cluster:'),
            'timeoutMs' => (int) env('CLUSTER_REDIS_TIMEOUT_MS', 500),
            'readTimeoutMs' => (int) env('CLUSTER_REDIS_READ_TIMEOUT_MS', 500),
            'maxQueueLength' => (int) env('CLUSTER_REDIS_MAX_QUEUE_LEN', 4096),
            'pool' => [
                'on' => (bool) env('CLUSTER_REDIS_POOL_ON', env('REDIS_POOL_ON', false)),
                'minConnections' => (int) env('CLUSTER_REDIS_POOL_MIN', 2),
                'maxConnections' => (int) env('CLUSTER_REDIS_POOL_MAX', 64),
                'waitTimeoutMs' => (int) env('CLUSTER_REDIS_POOL_WAIT_TIMEOUT_MS', 1000),
                'maxIdleTimeSeconds' => (int) env('CLUSTER_REDIS_POOL_MAX_IDLE_SECONDS', 60),
            ],
        ],

        /**
         * Direct peer-to-peer non-blocking socket/HTTP transport multiplexed via PHP 8.6 `\Io\Poll\Context`.
         *
         * @var array<string, mixed>
         */
        'direct' => [
            'driver' => 'direct',
            'connectTimeoutMs' => (int) env('CLUSTER_DIRECT_CONNECT_TIMEOUT_MS', 400),
            'requestTimeoutMs' => (int) env('CLUSTER_DIRECT_REQUEST_TIMEOUT_MS', 1200),
            'maxParallelStreams' => (int) env('CLUSTER_DIRECT_MAX_PARALLEL', 32),
            'maxRetries' => (int) env('CLUSTER_DIRECT_MAX_RETRIES', 2),
        ],

        /**
         * Bounded in-memory transport for single-process testing and local simulation.
         *
         * @var array<string, mixed>
         */
        'memory' => [
            'driver' => 'memory',
            'maxNodes' => (int) env('CLUSTER_MEMORY_MAX_NODES', 128),
            'maxMessages' => (int) env('CLUSTER_MEMORY_MAX_MESSAGES', 1024),
        ],
    ],

    /**
     * Local Atom node identity and capacity settings within the cluster pool.
     *
     * @var array<string, mixed>
     */
    'node' => [
        /**
         * Unique identifier of this Atom server instance.
         *
         * @var string
         */
        'id' => (string) env('CLUSTER_NODE_ID', env('SERVICE_INSTANCE_ID', 'atom-node-1')),

        /**
         * Cluster role of this Atom instance ('worker', 'supervisor', 'backup_supervisor').
         *
         * @var string
         */
        'role' => (string) env('CLUSTER_NODE_ROLE', 'worker'),

        /**
         * Reachable internal cluster endpoint URL or host:port of this node.
         *
         * @var string
         */
        'endpoint' => (string) env('CLUSTER_NODE_ENDPOINT', env('APP_URL', 'https://localhost')),

        /**
         * Relative capacity weight of this node (1..1000).
         *
         * @var int
         */
        'weight' => (int) env('CLUSTER_NODE_WEIGHT', 100),

        /**
         * Optional availability zone / region tag for locality-aware routing.
         *
         * @var string
         */
        'region' => (string) env('CLUSTER_NODE_REGION', 'eu-central'),

        /**
         * Static seed peer endpoints when Redis discovery is unavailable (`csv` or array).
         *
         * @var list<string>
         */
        'seedPeers' => [],
    ],

    /**
     * Automatic internal traffic load-balancing and circuit-breaker configuration.
     *
     * @var array<string, mixed>
     */
    'loadBalancer' => [
        /**
         * Enable automatic traffic load balancing across healthy cluster nodes.
         *
         * @var bool
         */
        'on' => (bool) env('CLUSTER_LB_ON', true),

        /**
         * Load balancing algorithm ('least_load', 'round_robin', 'least_connections', 'latency_aware', 'consistent_hash').
         *
         * @var string
         */
        'strategy' => (string) env('CLUSTER_LB_STRATEGY', 'least_load'),

        /**
         * Composite score weight for normalized CPU load (0.0..1.0).
         *
         * @var float
         */
        'cpuWeight' => (float) env('CLUSTER_LB_CPU_WEIGHT', 0.40),

        /**
         * Composite score weight for normalized RAM utilization (0.0..1.0).
         *
         * @var float
         */
        'ramWeight' => (float) env('CLUSTER_LB_RAM_WEIGHT', 0.30),

        /**
         * Composite score weight for active inflight connections (0.0..1.0).
         *
         * @var float
         */
        'connectionsWeight' => (float) env('CLUSTER_LB_CONNECTIONS_WEIGHT', 0.20),

        /**
         * Composite score weight for network RTT latency (0.0..1.0).
         *
         * @var float
         */
        'latencyWeight' => (float) env('CLUSTER_LB_LATENCY_WEIGHT', 0.10),

        /**
         * Prefer handling request locally without hop overhead if local load score is below this threshold.
         *
         * @var float
         */
        'localFastPathThreshold' => (float) env('CLUSTER_LB_LOCAL_FAST_PATH', 0.75),

        /**
         * Maximum tolerated clock drift in milliseconds before penalizing/flagging a node.
         *
         * @var int
         */
        'maxClockDriftMs' => (int) env('CLUSTER_LB_MAX_CLOCK_DRIFT_MS', 2000),

        /**
         * Heartbeat TTL in seconds; nodes silent longer than this are treated as offline.
         *
         * @var int
         */
        'heartbeatTtlSeconds' => (int) env('CLUSTER_HEARTBEAT_TTL', 10),

        /**
         * Consecutive transport failures before opening the node's circuit breaker.
         *
         * @var int
         */
        'circuitBreakerFailures' => (int) env('CLUSTER_CB_FAILURES', 3),

        /**
         * Cooldown window in seconds before probing a tripped node again.
         *
         * @var int
         */
        'circuitBreakerCooldownSeconds' => (int) env('CLUSTER_CB_COOLDOWN', 15),
    ],

    /**
     * Real-time cluster synchronization settings (mandatory system telemetry + custom app data).
     *
     * @var array<string, mixed>
     */
    'sync' => [
        /**
         * Enable continuous synchronization of node metrics (CPU, RAM, clock, load) and app state.
         *
         * @var bool
         */
        'on' => (bool) env('CLUSTER_SYNC_ON', true),

        /**
         * Heartbeat & telemetry broadcast interval in milliseconds.
         *
         * @var int
         */
        'heartbeatIntervalMs' => (int) env('CLUSTER_SYNC_INTERVAL_MS', 1000),

        /**
         * Allow application developers to publish and synchronize custom keyed state/channels.
         *
         * @var bool
         */
        'allowCustomData' => (bool) env('CLUSTER_SYNC_CUSTOM_DATA', true),

        /**
         * Default TTL in seconds for synchronized custom application keys.
         *
         * @var int
         */
        'stateTtlSeconds' => (int) env('CLUSTER_SYNC_STATE_TTL', 3600),

        /**
         * Maximum bounded entries kept in local fallback state cache (prevents memory leaks).
         *
         * @var int
         */
        'maxStateEntries' => (int) env('CLUSTER_SYNC_MAX_ENTRIES', 2048),
    ],

    /**
     * Authenticated encryption settings for all cluster & Redis payloads.
     *
     * @var array<string, mixed>
     */
    'encryption' => [
        /**
         * Encrypt all inter-node cluster packets and Redis messages using AEAD (XChaCha20-Poly1305 / AES-256-GCM).
         *
         * @var bool
         */
        'on' => (bool) env('CLUSTER_ENCRYPTION_ON', true),

        /**
         * Shared 256-bit secret key (hex or base64 or passphrase derived via HKDF-SHA256).
         *
         * @var string
         */
        'sharedKey' => (string) env('CLUSTER_ENCRYPTION_KEY', ''),

        /**
         * Active key identifier supporting zero-downtime key rotation.
         *
         * @var string
         */
        'keyId' => (string) env('CLUSTER_KEY_ID', 'cluster-k1'),

        /**
         * Anti-replay timestamp tolerance window in seconds.
         *
         * @var int
         */
        'replayWindowSeconds' => (int) env('CLUSTER_REPLAY_WINDOW', 30),
    ],

    /**
     * Unified remote cluster database configuration.
     *
     * Allows independent Atom cluster servers to share a remote SQL database (or DB cluster)
     * through the exact same `Atom\DataBase\Database` interface.
     *
     * @var array<string, mixed>
     */
    'remoteDatabase' => [
        /**
         * Enable the remote shared cluster database connection.
         *
         * @var bool
         */
        'on' => (bool) env('CLUSTER_REMOTE_DB_ON', false),

        /**
         * Automatically bind the remote cluster database as `$app->db` during module boot.
         *
         * @var bool
         */
        'bindAsPrimary' => (bool) env('CLUSTER_REMOTE_DB_BIND_PRIMARY', false),

        /**
         * PDO driver for the remote cluster database ('mysql', 'pgsql', 'sqlite').
         *
         * @var string
         */
        'driver' => (string) env('CLUSTER_REMOTE_DB_DRIVER', 'mysql'),

        /**
         * Explicit DSN override for the remote cluster database.
         *
         * @var string
         */
        'dsn' => (string) env(
            'CLUSTER_REMOTE_DB_DSN',
            'mysql:host=' . env('CLUSTER_REMOTE_DB_HOST', '127.0.0.1')
            . ';port=' . env('CLUSTER_REMOTE_DB_PORT', 3306)
            . ';dbname=' . env('CLUSTER_REMOTE_DB_NAME', 'AtomCluster')
            . ';charset=' . env('CLUSTER_REMOTE_DB_CHARSET', 'utf8mb4')
        ),

        'host' => (string) env('CLUSTER_REMOTE_DB_HOST', '127.0.0.1'),
        'port' => (int) env('CLUSTER_REMOTE_DB_PORT', 3306),
        'dbname' => (string) env('CLUSTER_REMOTE_DB_NAME', 'AtomCluster'),
        'user' => (string) env('CLUSTER_REMOTE_DB_USER', 'root'),
        'password' => (string) env('CLUSTER_REMOTE_DB_PASSWORD', ''),
        'charset' => (string) env('CLUSTER_REMOTE_DB_CHARSET', 'utf8mb4'),
        'prefix' => (string) env('CLUSTER_REMOTE_DB_PREFIX', ''),
        'timeoutSeconds' => (int) env('CLUSTER_REMOTE_DB_TIMEOUT', 5),

        /**
         * Connection pool configuration for Swoole / microservice workers accessing remote DB.
         *
         * @var array<string, mixed>
         */
        'pool' => [
            'on' => (bool) env('CLUSTER_REMOTE_DB_POOL_ON', false),
            'minConnections' => (int) env('CLUSTER_REMOTE_DB_POOL_MIN', 2),
            'maxConnections' => (int) env('CLUSTER_REMOTE_DB_POOL_MAX', 32),
            'waitTimeoutMs' => (int) env('CLUSTER_REMOTE_DB_POOL_WAIT_MS', 3000),
            'maxIdleTimeSeconds' => (int) env('CLUSTER_REMOTE_DB_POOL_IDLE_SEC', 60),
        ],
    ],

    /**
     * Atom Cluster Supervisor & Backup Supervisor configuration.
     *
     * Enforces that ONLY the active Supervisor (or promoted Backup Supervisor during failover)
     * can push encrypted, signed configuration updates and supervise cluster nodes.
     *
     * @var array<string, mixed>
     */
    'supervisor' => [
        /**
         * Enable cluster supervision and encrypted remote configuration management.
         *
         * @var bool
         */
        'on' => (bool) env('CLUSTER_SUPERVISOR_ON', true),

        /**
         * Strictly restrict configuration updates exclusively to an authenticated Supervisor.
         *
         * @var bool
         */
        'onlySupervisorUpdatesConfig' => (bool) env('CLUSTER_ONLY_SUPERVISOR_UPDATES_CONFIG', true),

        /**
         * Node ID of the designated primary Atom Supervisor server.
         *
         * @var string
         */
        'primaryNodeId' => (string) env('CLUSTER_SUPERVISOR_NODE_ID', 'supervisor-primary'),

        /**
         * Endpoint URL of the primary Atom Supervisor server.
         *
         * @var string
         */
        'primaryEndpoint' => (string) env('CLUSTER_SUPERVISOR_ENDPOINT', ''),

        /**
         * Dedicated secret key used exclusively by Supervisor & Backup Supervisor to sign & encrypt config updates.
         *
         * @var string
         */
        'secretKey' => (string) env('CLUSTER_SUPERVISOR_SECRET_KEY', ''),

        /**
         * Supervisor leader lease TTL in seconds.
         *
         * @var int
         */
        'leaseTtlSeconds' => (int) env('CLUSTER_SUPERVISOR_LEASE_TTL', 15),

        /**
         * Whitelist of configuration namespaces that Supervisor is allowed to update remotely.
         *
         * @var list<string>
         */
        'allowedConfigNamespaces' => [
            'app',
            'cache',
            'cluster',
            'database',
            'exchange',
            'filesystem',
            'logger',
            'mail',
            'mcp',
            'modul',
            'oauth',
            'session',
        ],

        /**
         * Backup Atom Supervisor failover configuration.
         *
         * Automatically promotes the backup supervisor when the primary supervisor's heartbeat/lease expires.
         *
         * @var array<string, mixed>
         */
        'backupSupervisor' => [
            /**
             * Enable standby Backup Supervisor automatic failover.
             *
             * @var bool
             */
            'on' => (bool) env('CLUSTER_BACKUP_SUPERVISOR_ON', true),

            /**
             * Node ID of the designated Backup Supervisor server.
             *
             * @var string
             */
            'nodeId' => (string) env('CLUSTER_BACKUP_SUPERVISOR_NODE_ID', 'supervisor-backup'),

            /**
             * Endpoint URL of the Backup Supervisor server.
             *
             * @var string
             */
            'endpoint' => (string) env('CLUSTER_BACKUP_SUPERVISOR_ENDPOINT', ''),

            /**
             * Seconds without primary supervisor heartbeat before Backup Supervisor takes over leadership.
             *
             * @var int
             */
            'failoverTimeoutSeconds' => (int) env('CLUSTER_BACKUP_FAILOVER_TIMEOUT', 12),

            /**
             * Automatically yield leadership back to primary supervisor once primary recovers health.
             *
             * @var bool
             */
            'autoStepDownOnPrimaryRecovery' => (bool) env('CLUSTER_BACKUP_AUTO_STEP_DOWN', true),
        ],
    ],
];
