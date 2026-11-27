<?php

declare(strict_types=1);

namespace Atom\Modules\AtomCluster;

use Atom\Async\Async;
use Atom\Atom;
use Atom\Cache\Contract\RedisAdapterInterface;
use Atom\Cache\InMemoryRedisAdapter;
use Atom\Cache\RespRedisAdapter;
use Atom\DataBase\Database;
use Atom\Modules\AtomExchange\ExchangeCipher;
use Atom\Modules\Contract\AtomModuleInterface;
use Atom\Support\DataRegistry;
use Atom\Terminal\Terminal;
use Override;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;
use SensitiveParameter;
use SortDirection;
use Throwable;
use Time\Duration;

/**
 * Official `AtomCluster` scaling module (`Atom\Modules\AtomCluster`) providing:
 * - Automatic internal load balancing across independent Atom servers (CPU, RAM, connections, latency, clock drift)
 * - Continuous real-time synchronization of node telemetry AND custom developer state/events
 * - Redis-first ultra-fast mode: when Redis is enabled & online, 100% of cluster sync and traffic routes via Redis
 * - End-to-end AEAD encryption (`XChaCha20-Poly1305`) for all Redis & direct cluster traffic
 * - Unified remote cluster SQL `Database` interface (`$cluster->database()`)
 * - Cryptographically enforced `ClusterSupervisor` (only Supervisor updates config) + automatic `backup_supervisor` failover
 */
final class AtomCluster implements AtomModuleInterface
{
    public const string VERSION = '1.0.0';

    private const string REDIS_NODES_HASH = 'nodes:telemetry';
    private const string REDIS_STATE_HASH = 'state:shared';

    /** @var array<string, mixed> */
    private array $config;

    private string $clusterId;
    private string $localNodeId;
    private string $localRole;
    private string $localEndpoint;
    private int $localWeight;
    private string $localRegion;
    private bool $encryptionEnabled;
    private bool $redisEnabled;
    private bool $routeAllViaRedis;
    private int $maxStateEntries;
    private int $circuitBreakerFailures;
    private int $circuitBreakerCooldownSeconds;

    private ExchangeCipher $clusterCipher;
    private ClusterLoadBalancer $loadBalancer;
    private ClusterDatabaseManager $databaseManager;
    private ClusterSupervisor $supervisor;
    private ?RedisAdapterInterface $redisAdapter;

    /**
     * Bounded local node telemetry map (`nodeId => NodeTelemetry`), synced with Redis when online.
     *
     * @var array<string, NodeTelemetry>
     */
    private array $localNodeTable = [];

    /**
     * Bounded local fallback map for custom developer-synchronized state when Redis is offline.
     *
     * @var array<string, mixed>
     */
    private array $localSharedState = [];

    /**
     * Bounded custom state/channel listeners (`channel => list<callable(string, mixed, string): void>`).
     *
     * @var array<string, list<callable(string, mixed, string): void>>
     */
    private array $stateListeners = [];

    /**
     * Optional peer traffic executor callback (`callable(NodeTelemetry, array<string, mixed>): mixed`).
     *
     * @var (callable(NodeTelemetry, array<string, mixed>): mixed)|null
     */
    private mixed $peerTrafficExecutor = null;

    private int $activeInflightRequests = 0;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        #[SensitiveParameter]
        array $options = [],
        ?RedisAdapterInterface $redisAdapter = null,
        ?Database $prebuiltRemoteDatabase = null,
    ) {
        $this->config = $options;
        $this->clusterId = (string) ($options['clusterId'] ?? 'atom-cluster-main');

        $nodeCfg = \is_array($options['node'] ?? null) ? $options['node'] : [];
        $this->localNodeId = (string) ($nodeCfg['id'] ?? 'atom-node-1');
        $this->localRole = (string) ($nodeCfg['role'] ?? 'worker');
        $this->localEndpoint = (string) ($nodeCfg['endpoint'] ?? 'https://localhost');
        $this->localWeight = \clamp((int) ($nodeCfg['weight'] ?? 100), 1, 1000);
        $this->localRegion = (string) ($nodeCfg['region'] ?? 'eu-central');

        $lbCfg = \is_array($options['loadBalancer'] ?? null) ? $options['loadBalancer'] : [];
        $this->circuitBreakerFailures = \clamp((int) ($lbCfg['circuitBreakerFailures'] ?? 3), 1, 100);
        $this->circuitBreakerCooldownSeconds = \clamp((int) ($lbCfg['circuitBreakerCooldownSeconds'] ?? 15), 1, 3600);
        $this->loadBalancer = new ClusterLoadBalancer($lbCfg);

        $syncCfg = \is_array($options['sync'] ?? null) ? $options['sync'] : [];
        $this->maxStateEntries = \clamp((int) ($syncCfg['maxStateEntries'] ?? 2048), 32, 100_000);

        $encCfg = \is_array($options['encryption'] ?? null) ? $options['encryption'] : [];
        $this->encryptionEnabled = (bool) ($encCfg['on'] ?? true);
        $sharedKey = (string) ($encCfg['sharedKey'] ?? '');
        if ($sharedKey === '') {
            $sharedKey = 'atom-cluster-default-key-' . $this->clusterId;
        }

        $this->clusterCipher = new ExchangeCipher(
            sharedSecret: $sharedKey,
            keyId: (string) ($encCfg['keyId'] ?? 'cluster-k1'),
            encryptEnabled: $this->encryptionEnabled,
            compressEnabled: true,
            replayWindow: Duration::fromSeconds(\clamp((int) ($encCfg['replayWindowSeconds'] ?? 30), 1, 3600)),
        );

        $redisChoice = \is_array($options['choices']['redis'] ?? null)
            ? $options['choices']['redis']
            : (\is_array($options['driver'] ?? null) && ($options['driver']['driver'] ?? '') === 'redis' ? $options['driver'] : []);
        $this->redisEnabled = (bool) ($redisChoice['on'] ?? (($options['default'] ?? 'redis') === 'redis'));
        $this->routeAllViaRedis = (bool) ($redisChoice['routeAllTrafficViaRedis'] ?? true);

        if ($redisAdapter !== null) {
            $this->redisAdapter = $redisAdapter;
        } elseif (($options['default'] ?? '') === 'memory') {
            $this->redisAdapter = null;
        } elseif ($this->redisEnabled && $redisChoice !== []) {
            $this->redisAdapter = new RespRedisAdapter($redisChoice);
        } else {
            $this->redisAdapter = null;
        }

        $remoteDbCfg = \is_array($options['remoteDatabase'] ?? null) ? $options['remoteDatabase'] : [];
        $this->databaseManager = new ClusterDatabaseManager($remoteDbCfg, $prebuiltRemoteDatabase);

        $supCfg = \is_array($options['supervisor'] ?? null) ? $options['supervisor'] : [];
        $this->supervisor = new ClusterSupervisor(
            localNodeId: $this->localNodeId,
            localRole: $this->localRole,
            supervisorConfig: $supCfg,
            redisAdapter: $this->redisAdapter,
        );
    }

    #[Override]
    public function getName(): string
    {
        return 'AtomCluster';
    }

    #[Override]
    public function getVersion(): string
    {
        return self::VERSION;
    }

    #[Override]
    public function dependencies(): array
    {
        return [];
    }

    public function getClusterId(): string
    {
        return $this->clusterId;
    }

    public function getLocalNodeId(): string
    {
        return $this->localNodeId;
    }

    public function loadBalancer(): ClusterLoadBalancer
    {
        return $this->loadBalancer;
    }

    public function supervisor(): ClusterSupervisor
    {
        return $this->supervisor;
    }

    public function databaseManager(): ClusterDatabaseManager
    {
        return $this->databaseManager;
    }

    /**
     * Returns the unified `Atom\DataBase\Database` connection for the remote shared cluster database.
     */
    public function database(): Database
    {
        return $this->databaseManager->connection();
    }

    /**
     * Returns true when Redis is enabled AND online, meaning 100% of cluster traffic, state sync,
     * and heartbeats are routed directly through Redis.
     */
    public function isRedisRoutingActive(): bool
    {
        return $this->redisEnabled
            && $this->routeAllViaRedis
            && $this->redisAdapter !== null
            && $this->redisAdapter->isAvailable();
    }

    /**
     * Injects or replaces the Redis adapter at runtime (e.g. shared connection pool or test adapter).
     */
    public function setRedisAdapter(?RedisAdapterInterface $adapter, bool $enableRedis = true): void
    {
        $this->redisAdapter = $adapter;
        $this->redisEnabled = $enableRedis;

        $supCfg = \is_array($this->config['supervisor'] ?? null) ? $this->config['supervisor'] : [];
        $this->supervisor = new ClusterSupervisor(
            localNodeId: $this->localNodeId,
            localRole: $this->localRole,
            supervisorConfig: $supCfg,
            redisAdapter: $adapter,
        );
    }

    /**
     * Registers a custom peer executor used when forwarding balanced traffic to a remote node.
     *
     * @param callable(NodeTelemetry, array<string, mixed>): mixed $executor
     */
    public function setPeerTrafficExecutor(callable $executor): void
    {
        $this->peerTrafficExecutor = $executor;
    }

    #[Override]
    public function boot(Atom $app): void
    {
        $clusterCfg = $app->config->get('cluster');
        if (!\is_array($clusterCfg) || empty($clusterCfg['on'])) {
            return;
        }

        // Re-initialize from full `config/cluster.php` when booted inside Atom kernel
        $reconfigured = new self($clusterCfg, $this->redisAdapter);
        $this->config = $reconfigured->config;
        $this->clusterId = $reconfigured->clusterId;
        $this->localNodeId = $reconfigured->localNodeId;
        $this->localRole = $reconfigured->localRole;
        $this->localEndpoint = $reconfigured->localEndpoint;
        $this->localWeight = $reconfigured->localWeight;
        $this->localRegion = $reconfigured->localRegion;
        $this->encryptionEnabled = $reconfigured->encryptionEnabled;
        $this->redisEnabled = $reconfigured->redisEnabled;
        $this->routeAllViaRedis = $reconfigured->routeAllViaRedis;
        $this->clusterCipher = $reconfigured->clusterCipher;
        $this->loadBalancer = $reconfigured->loadBalancer;
        $this->databaseManager = $reconfigured->databaseManager;
        $this->supervisor = $reconfigured->supervisor;
        $this->redisAdapter = $reconfigured->redisAdapter;

        if ($this->databaseManager->isEnabled() && $this->databaseManager->shouldBindAsPrimary() && !$app->isNoDbMode()) {
            $app->db = $this->databaseManager->connection($app->datetime, Atom::$ROOT_URI);
        }

        $this->heartbeat();

        $app->on(Atom::EVENT_BEFORE_REQUEST, function (): void {
            $this->activeInflightRequests++;
            $this->heartbeat();
        });

        $app->on(Atom::EVENT_AFTER_REQUEST, function (): void {
            $this->activeInflightRequests = \max(0, $this->activeInflightRequests - 1);
        });
    }

    /**
     * Captures and broadcasts this node's mandatory telemetry (CPU load, RAM, connections, clock)
     * across the cluster (encrypted via Redis when active).
     */
    public function heartbeat(?NodeTelemetry $explicitTelemetry = null): NodeTelemetry
    {
        $telemetry = $explicitTelemetry ?? NodeTelemetry::captureLocal(
            nodeId: $this->localNodeId,
            endpoint: $this->localEndpoint,
            role: $this->localRole,
            weight: $this->localWeight,
            region: $this->localRegion,
            activeConnections: $this->activeInflightRequests,
        );

        $this->registerNodeTelemetry($telemetry);
        return $telemetry;
    }

    /**
     * Publishes or updates a cluster node's telemetry (encrypted when transmitted via Redis).
     */
    public function registerNodeTelemetry(NodeTelemetry $telemetry): void
    {
        if (\count($this->localNodeTable) >= 256 && !isset($this->localNodeTable[$telemetry->nodeId])) {
            $oldest = \array_key_first($this->localNodeTable);
            unset($this->localNodeTable[$oldest]);
        }
        $this->localNodeTable[$telemetry->nodeId] = $telemetry;

        if ($this->isRedisRoutingActive() && $this->redisAdapter !== null) {
            $sealed = $this->sealClusterData('telemetry', $telemetry->toArray(), $telemetry->nodeId);
            $this->redisAdapter->hSet($this->redisKey(self::REDIS_NODES_HASH), $telemetry->nodeId, $sealed);
            $this->redisAdapter->publish($this->redisKey('events:heartbeat'), $sealed);
        }
    }

    /**
     * Returns all known cluster nodes synchronized from Redis (when active) and local state.
     *
     * @return array<string, NodeTelemetry>
     */
    public function getNodes(): array
    {
        if ($this->isRedisRoutingActive() && $this->redisAdapter !== null) {
            $rawMap = $this->redisAdapter->hGetAll($this->redisKey(self::REDIS_NODES_HASH));
            $hydrated = [];
            foreach ($rawMap as $nodeId => $sealed) {
                $decoded = $this->openClusterData($sealed);
                if (\is_array($decoded)) {
                    $hydrated[$nodeId] = NodeTelemetry::fromArray($decoded);
                }
            }
            if ($hydrated !== []) {
                $this->localNodeTable = $hydrated;
                return $hydrated;
            }
        }

        return $this->localNodeTable;
    }

    /**
     * Synchronizes any custom developer key/value data across all Atom nodes in real time
     * (encrypted over Redis when active, with optional automatic mirroring into `DataRegistry` for `{{html}}`).
     */
    public function syncState(
        string $dotKey,
        mixed $value,
        ?DataRegistry $mirrorToRegistry = null,
    ): void {
        $cleanKey = \trim($dotKey);
        if ($cleanKey === '') {
            return;
        }

        if (\count($this->localSharedState) >= $this->maxStateEntries && !\array_key_exists($cleanKey, $this->localSharedState)) {
            $oldest = \array_key_first($this->localSharedState);
            if (\is_string($oldest)) {
                unset($this->localSharedState[$oldest]);
            }
        }
        $this->localSharedState[$cleanKey] = $value;

        if ($mirrorToRegistry !== null) {
            $mirrorToRegistry->set($cleanKey, $value);
        }

        if ($this->isRedisRoutingActive() && $this->redisAdapter !== null) {
            $sealed = $this->sealClusterData('state:' . $cleanKey, [
                'key' => $cleanKey,
                'value' => $value,
                'origin' => $this->localNodeId,
                'ts' => (int) (\microtime(true) * 1_000_000_000),
            ]);
            $this->redisAdapter->hSet($this->redisKey(self::REDIS_STATE_HASH), $cleanKey, $sealed);
            $this->redisAdapter->publish($this->redisKey('events:state'), $sealed);
        }

        $this->notifyListeners($cleanKey, $value, $this->localNodeId);
    }

    /**
     * Concurrently synchronizes multiple custom key/value pairs across the cluster using `Atom\Async\Async`
     * (Fibers / Swoole coroutines).
     *
     * @param array<string, mixed> $entries
     */
    public function syncStatesAsync(
        array $entries,
        ?DataRegistry $mirrorToRegistry = null,
        int $concurrency = 16,
        ?Async $async = null,
    ): void {
        if ($entries === []) {
            return;
        }

        $engine = $async ?? (isset(Atom::$app) && isset(Atom::$app->async) ? Atom::$app->async : new Async());
        $engine->each(
            $entries,
            fn(mixed $val, string $key) => $this->syncState($key, $val, $mirrorToRegistry),
            \clamp($concurrency, 1, 256),
        );
    }

    /**
     * Concurrently dispatches a batch of balanced internal requests across healthy cluster nodes
     * using `Atom\Async\Async` (Fibers / Swoole coroutines).
     *
     * @param array<int|string, array<string, mixed>> $batchPayloads
     * @param callable(array<string, mixed>): mixed|null $localHandler
     * @return array<int|string, array{targetNodeId: string, viaRedis: bool, localFastPath: bool, result: mixed}>
     */
    public function dispatchBatchAsync(
        array $batchPayloads,
        ?callable $localHandler = null,
        int $concurrency = 16,
        ?Async $async = null,
    ): array {
        if ($batchPayloads === []) {
            return [];
        }

        $engine = $async ?? (isset(Atom::$app) && isset(Atom::$app->async) ? Atom::$app->async : new Async());
        return $engine->map(
            $batchPayloads,
            fn(array $payload): array => $this->dispatchBalancedTraffic($payload, $localHandler),
            \clamp($concurrency, 1, 256),
        );
    }

    /**
     * Reads real-time synchronized custom developer data from the cluster (via Redis first when active).
     */
    public function getSharedState(string $dotKey, mixed $default = null): mixed
    {
        $cleanKey = \trim($dotKey);
        if ($cleanKey === '') {
            return $default;
        }

        if ($this->isRedisRoutingActive() && $this->redisAdapter !== null) {
            $sealed = $this->redisAdapter->hGet($this->redisKey(self::REDIS_STATE_HASH), $cleanKey);
            if (\is_string($sealed) && $sealed !== '') {
                $decoded = $this->openClusterData($sealed);
                if (\is_array($decoded) && \array_key_exists('value', $decoded)) {
                    $this->localSharedState[$cleanKey] = $decoded['value'];
                    return $decoded['value'];
                }
            }
        }

        return $this->localSharedState[$cleanKey] ?? $default;
    }

    /**
     * Registers a listener notified whenever a synchronized custom key or wildcard (`'*'`) updates.
     *
     * @param callable(string, mixed, string): void $listener
     */
    public function onStateChange(string $keyOrWildcard, callable $listener): self
    {
        if (\count($this->stateListeners[$keyOrWildcard] ?? []) < 64) {
            $this->stateListeners[$keyOrWildcard][] = $listener;
        }

        return $this;
    }

    /**
     * Automatically balances and dispatches an internal request/workload to the optimal Atom node
     * with zero-delay failover and Redis-routed queueing when Redis is online.
     *
     * @param array<string, mixed> $requestPayload
     * @param callable(array<string, mixed>): mixed|null $localHandler
     * @return array{targetNodeId: string, viaRedis: bool, localFastPath: bool, result: mixed}
     */
    public function dispatchBalancedTraffic(
        array $requestPayload,
        ?callable $localHandler = null,
        ?string $stickyRoutingKey = null,
    ): array {
        $nodes = $this->getNodes();
        if ($nodes === []) {
            $this->heartbeat();
            $nodes = $this->getNodes();
        }

        $excluded = [];
        $maxAttempts = \max(1, \min(3, \count($nodes)));
        $viaRedis = $this->isRedisRoutingActive();

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $target = $this->loadBalancer->selectTargetNode(
                nodes: \array_values($nodes),
                localNodeId: $this->localNodeId,
                stickyRoutingKey: $stickyRoutingKey,
                excludedNodeIds: $excluded,
            );

            if ($target === null) {
                break;
            }

            $isLocal = ($target->nodeId === $this->localNodeId);

            // When Redis is enabled & online, record/route the encrypted traffic envelope through Redis
            if ($viaRedis && $this->redisAdapter !== null) {
                $sealedTraffic = $this->sealClusterData('traffic:' . $target->nodeId, [
                    'target' => $target->nodeId,
                    'payload' => $requestPayload,
                ]);
                $this->redisAdapter->rPush(
                    $this->redisKey('traffic:queue:' . $target->nodeId),
                    $sealedTraffic,
                    4096,
                );
            }

            try {
                if ($isLocal && $localHandler !== null) {
                    $result = $localHandler($requestPayload);
                    return [
                        'targetNodeId' => $target->nodeId,
                        'viaRedis' => $viaRedis,
                        'localFastPath' => true,
                        'result' => $result,
                    ];
                }

                if ($this->peerTrafficExecutor !== null) {
                    $result = ($this->peerTrafficExecutor)($target, $requestPayload);
                    $this->recordNodeSuccess($target->nodeId);
                    return [
                        'targetNodeId' => $target->nodeId,
                        'viaRedis' => $viaRedis,
                        'localFastPath' => false,
                        'result' => $result,
                    ];
                }

                return [
                    'targetNodeId' => $target->nodeId,
                    'viaRedis' => $viaRedis,
                    'localFastPath' => $isLocal,
                    'result' => $localHandler !== null ? $localHandler($requestPayload) : ['queued' => true, 'node' => $target->nodeId],
                ];
            } catch (Throwable) {
                $this->recordNodeFailure($target->nodeId);
                $excluded[] = $target->nodeId;
                $nodes = $this->getNodes();
            }
        }

        if ($localHandler !== null) {
            return [
                'targetNodeId' => $this->localNodeId,
                'viaRedis' => $viaRedis,
                'localFastPath' => true,
                'result' => $localHandler($requestPayload),
            ];
        }

        throw new RuntimeException('No healthy Atom cluster nodes available to handle traffic.');
    }

    /**
     * Drains encrypted traffic envelopes queued for `$nodeId` in Redis.
     *
     * @return list<array<string, mixed>>
     */
    public function drainRedisTrafficQueue(int $limit = 32, ?string $nodeIdOverride = null): array
    {
        if (!$this->isRedisRoutingActive() || $this->redisAdapter === null) {
            return [];
        }

        $nodeId = $nodeIdOverride ?? $this->localNodeId;
        $queueKey = $this->redisKey('traffic:queue:' . $nodeId);
        $max = \clamp($limit, 1, 512);
        $items = [];

        for ($i = 0; $i < $max; $i++) {
            $sealed = $this->redisAdapter->lPop($queueKey);
            if ($sealed === null || $sealed === '') {
                break;
            }
            $opened = $this->openClusterData($sealed);
            if (\is_array($opened)) {
                $items[] = $opened;
            }
        }

        return $items;
    }

    /**
     * Records a transport failure against a peer node and trips its circuit breaker if threshold reached.
     */
    public function recordNodeFailure(string $nodeId): void
    {
        $nodes = $this->getNodes();
        if (!isset($nodes[$nodeId])) {
            return;
        }

        $current = $nodes[$nodeId];
        $failures = $current->consecutiveFailures + 1;
        $openUntil = $failures >= $this->circuitBreakerFailures
            ? (\microtime(true) + $this->circuitBreakerCooldownSeconds)
            : $current->circuitOpenUntil;

        $updated = $current->withCircuitState(
            consecutiveFailures: $failures,
            circuitOpenUntil: $openUntil,
            healthy: $failures < $this->circuitBreakerFailures,
        );

        $this->registerNodeTelemetry($updated);
    }

    public function recordNodeSuccess(string $nodeId): void
    {
        $nodes = $this->getNodes();
        if (!isset($nodes[$nodeId])) {
            return;
        }

        $current = $nodes[$nodeId];
        if ($current->consecutiveFailures === 0 && $current->healthy) {
            return;
        }

        $updated = $current->withCircuitState(
            consecutiveFailures: 0,
            circuitOpenUntil: 0.0,
            healthy: true,
        );

        $this->registerNodeTelemetry($updated);
    }

    #[Override]
    public function registerCommands(Terminal $terminal): Terminal
    {
        return $terminal->withCommand(
            'cluster:status',
            function (array $args, Terminal $term): int {
                $nodes = $this->getNodes();
                $report = $this->supervisor->inspectCluster(\array_values($nodes));

                $term->table(
                    ['Property', 'Value'],
                    [
                        ['Cluster ID', $this->clusterId],
                        ['Local Node', $this->localNodeId . ' (' . $this->localRole . ')'],
                        ['Redis Routing', $this->isRedisRoutingActive() ? 'ACTIVE (100% via Redis)' : 'Standby / Direct Peer'],
                        ['AEAD Encryption', $this->encryptionEnabled ? 'Enabled (XChaCha20-Poly1305)' : 'Disabled'],
                        ['Active Supervisor', (string) $report['activeSupervisorId'] . ($report['isFailoverActive'] ? ' [BACKUP FAILOVER]' : '')],
                        ['Healthy Nodes', $report['healthyNodes'] . ' / ' . $report['totalNodes']],
                        ['Remote Cluster DB', $this->databaseManager->isEnabled() ? 'Enabled' : 'Disabled'],
                    ],
                );

                if ($nodes !== []) {
                    $ranked = $this->loadBalancer->rankNodes(\array_values($nodes), SortDirection::Ascending);
                    $rows = [];
                    foreach ($ranked as $n) {
                        $rows[] = [
                            $n->nodeId,
                            $n->role,
                            (string) $n->cpuLoad,
                            $n->usedRamMb . '/' . $n->totalRamMb . ' MB',
                            (string) $n->activeConnections,
                            $n->clockOffsetMs . ' ms',
                            (string) $this->loadBalancer->computeScore($n),
                        ];
                    }
                    $term->table(['Node', 'Role', 'CPU', 'RAM', 'Conns', 'Clock Drift', 'LB Score'], $rows);
                }

                return 0;
            },
            'Display AtomCluster load balancer, Redis routing, telemetry, and Supervisor status',
        );
    }

    private function redisKey(string $suffix): string
    {
        return 'cluster:' . $this->clusterId . ':' . $suffix;
    }

    private function sealClusterData(string $channel, mixed $data, ?string $senderOverride = null): string
    {
        return $this->clusterCipher->seal(
            senderNodeId: $senderOverride ?? $this->localNodeId,
            recipientNodeId: '*',
            channel: $channel,
            payload: $data,
            binaryOutput: false,
        );
    }

    private function openClusterData(string $wireFrame): mixed
    {
        try {
            $packet = $this->clusterCipher->open(
                wireFrame: $wireFrame,
                isBinaryInput: false,
                enforceReplayProtection: false,
            );
            return $packet->payload;
        } catch (Throwable) {
            return null;
        }
    }

    private function notifyListeners(string $key, mixed $value, string $originNode): void
    {
        foreach ($this->stateListeners[$key] ?? [] as $cb) {
            $cb($key, $value, $originNode);
        }
        foreach ($this->stateListeners['*'] ?? [] as $cb) {
            $cb($key, $value, $originNode);
        }
    }
}
