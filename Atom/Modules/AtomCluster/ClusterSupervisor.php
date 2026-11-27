<?php

declare(strict_types=1);

namespace Atom\Modules\AtomCluster;

use Atom\Cache\Contract\RedisAdapterInterface;
use Atom\Config\Config;
use Atom\Modules\AtomExchange\ExchangeCipher;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use Time\Duration;

/**
 * Atom Cluster Supervisor & Standby Backup Supervisor manager.
 *
 * Guarantees that:
 * 1. ONLY an authorized Supervisor (or promoted Backup Supervisor during primary outage) can sign
 *    and broadcast AEAD-encrypted configuration updates to Atom cluster nodes.
 * 2. Worker nodes strictly reject unauthenticated, unencrypted, replayed, or stale revisions.
 * 3. Backup Supervisor automatically takes over cluster supervision when Primary Supervisor's
 *    heartbeat/lease expires, and yields cleanly when Primary recovers (if configured).
 */
final class ClusterSupervisor
{
    private const string LEASE_KEY = 'supervisor:active_lease';
    private const string CONFIG_CHANNEL = 'supervisor:config_update';

    private string $localNodeId;
    private string $localRole;
    private string $primaryNodeId;
    private bool $onlySupervisorUpdatesConfig;
    private int $leaseTtlSeconds;
    private bool $backupEnabled;
    private string $backupNodeId;
    private int $failoverTimeoutSeconds;
    private bool $autoStepDownOnRecovery;

    /** @var list<string> */
    private array $allowedNamespaces;

    private ExchangeCipher $supervisorCipher;
    private ?RedisAdapterInterface $redisAdapter;

    private bool $actingLeader = false;
    private int $lastIssuedRevision = 0;
    private int $lastAppliedRevision = 0;

    /**
     * @param array<string, mixed> $supervisorConfig
     */
    public function __construct(
        string $localNodeId,
        string $localRole,
        #[SensitiveParameter]
        array $supervisorConfig = [],
        ?RedisAdapterInterface $redisAdapter = null,
    ) {
        $this->localNodeId = $localNodeId;
        $this->localRole = $localRole;
        $this->onlySupervisorUpdatesConfig = (bool) ($supervisorConfig['onlySupervisorUpdatesConfig'] ?? true);
        $this->primaryNodeId = (string) ($supervisorConfig['primaryNodeId'] ?? 'supervisor-primary');
        $this->leaseTtlSeconds = \clamp((int) ($supervisorConfig['leaseTtlSeconds'] ?? 15), 2, 3600);

        $backupCfg = \is_array($supervisorConfig['backupSupervisor'] ?? null) ? $supervisorConfig['backupSupervisor'] : [];
        $this->backupEnabled = (bool) ($backupCfg['on'] ?? true);
        $this->backupNodeId = (string) ($backupCfg['nodeId'] ?? 'supervisor-backup');
        $this->failoverTimeoutSeconds = \clamp((int) ($backupCfg['failoverTimeoutSeconds'] ?? 12), 1, 3600);
        $this->autoStepDownOnRecovery = (bool) ($backupCfg['autoStepDownOnPrimaryRecovery'] ?? true);

        $allowed = $supervisorConfig['allowedConfigNamespaces'] ?? [
            'app', 'cache', 'cluster', 'database', 'exchange', 'filesystem', 'logger', 'mail', 'mcp', 'modul', 'oauth', 'session',
        ];
        $this->allowedNamespaces = \is_array($allowed)
            ? \array_values(\array_map(\strval(...), $allowed))
            : ['app', 'cluster'];

        $secretKey = (string) ($supervisorConfig['secretKey'] ?? '');
        if ($secretKey === '') {
            $secretKey = 'atom-default-supervisor-key-change-in-production';
        }

        $this->supervisorCipher = new ExchangeCipher(
            sharedSecret: $secretKey,
            keyId: 'supervisor-cfg-v1',
            encryptEnabled: true,
            compressEnabled: true,
            replayWindow: Duration::fromSeconds(60),
        );
        $this->redisAdapter = $redisAdapter;

        if ($this->localRole === 'supervisor' && \hash_equals($this->primaryNodeId, $this->localNodeId)) {
            $this->actingLeader = true;
            $this->renewLease();
        }
    }

    public function getLocalRole(): string
    {
        return $this->localRole;
    }

    public function getPrimaryNodeId(): string
    {
        return $this->primaryNodeId;
    }

    public function getBackupNodeId(): string
    {
        return $this->backupNodeId;
    }

    public function getLastAppliedRevision(): int
    {
        return $this->lastAppliedRevision;
    }

    /**
     * Returns true ONLY if this instance is currently the active Primary Supervisor
     * or the promoted Backup Supervisor during an active failover.
     */
    public function isActingSupervisor(): bool
    {
        return $this->actingLeader;
    }

    /**
     * Evaluates primary supervisor health across cluster telemetry and triggers automatic
     * Backup Supervisor promotion (or graceful step-down when primary recovers).
     *
     * @param iterable<NodeTelemetry> $knownNodes
     */
    public function evaluateLeadership(iterable $knownNodes, ?int $nowNs = null): string
    {
        $currentNs = $nowNs ?? (int) (\microtime(true) * 1_000_000_000);

        $primaryTelemetry = null;
        foreach ($knownNodes as $node) {
            if ($node->nodeId === $this->primaryNodeId) {
                $primaryTelemetry = $node;
                break;
            }
        }

        $primaryHealthy = $primaryTelemetry !== null
            && $primaryTelemetry->isRoutable($currentNs, $this->failoverTimeoutSeconds);

        // Primary supervisor logic
        if ($this->localRole === 'supervisor' && \hash_equals($this->primaryNodeId, $this->localNodeId)) {
            $this->actingLeader = true;
            $this->forceLease($this->localNodeId);
            return $this->localNodeId;
        }

        // Backup supervisor failover & recovery logic
        if ($this->localRole === 'backup_supervisor' && $this->backupEnabled && \hash_equals($this->backupNodeId, $this->localNodeId)) {
            if ($primaryHealthy) {
                if ($this->actingLeader && $this->autoStepDownOnRecovery) {
                    $this->actingLeader = false;
                }
                return $this->primaryNodeId;
            }

            // Primary is unhealthy/unreachable -> promote Backup Supervisor!
            if ($this->redisAdapter !== null && $this->redisAdapter->isAvailable()) {
                $this->forceLease($this->localNodeId);
            }
            $this->actingLeader = true;
            return $this->localNodeId;
        }

        if ($this->redisAdapter !== null && $this->redisAdapter->isAvailable()) {
            $holder = $this->redisAdapter->get(self::LEASE_KEY);
            if (\is_string($holder) && $holder !== '') {
                return $holder;
            }
        }

        return $primaryHealthy || !$this->backupEnabled ? $this->primaryNodeId : $this->backupNodeId;
    }

    /**
     * Creates and broadcasts an AEAD-encrypted configuration update frame.
     * Throws `RuntimeException` if invoked on a regular worker node or inactive standby node!
     *
     * @param array<string, mixed> $configValues
     */
    public function createEncryptedConfigUpdate(
        string $namespace,
        array $configValues,
        string $targetNodeId = '*',
        ?int $explicitRevision = null,
        ?int $nowTimestampNs = null,
    ): string {
        if ($this->onlySupervisorUpdatesConfig && !$this->isAuthorizedToPushConfig()) {
            throw new RuntimeException(
                "Security violation: node '{$this->localNodeId}' (role '{$this->localRole}') is not an active Atom Supervisor and cannot update cluster configuration."
            );
        }

        if (!\in_array($namespace, $this->allowedNamespaces, true)) {
            throw new InvalidArgumentException("Configuration namespace '{$namespace}' is not permitted for remote Supervisor updates.");
        }

        $revision = $explicitRevision ?? (++$this->lastIssuedRevision);
        if ($revision > $this->lastIssuedRevision) {
            $this->lastIssuedRevision = $revision;
        }

        $frame = $this->supervisorCipher->seal(
            senderNodeId: $this->localNodeId,
            recipientNodeId: $targetNodeId,
            channel: self::CONFIG_CHANNEL,
            payload: [
                'namespace' => $namespace,
                'values' => $configValues,
                'revision' => $revision,
                'role' => $this->localRole,
            ],
            binaryOutput: false,
            nowTimestampNs: $nowTimestampNs,
        );

        if ($this->redisAdapter !== null && $this->redisAdapter->isAvailable()) {
            $this->redisAdapter->set('supervisor:latest_config:' . $namespace, $frame, 86_400);
            $this->redisAdapter->publish(self::CONFIG_CHANNEL, $frame);
        }

        return $frame;
    }

    /**
     * Verifies, decrypts, and atomically applies an encrypted Supervisor configuration update on a cluster node.
     *
     * @return array{namespace: string, revision: int, appliedBy: string}
     */
    public function applyEncryptedConfigUpdate(
        string $encryptedFrame,
        ?string $persistCacheAppPath = null,
        bool $allowBackupSender = true,
        ?int $nowTimestampNs = null,
    ): array {
        $packet = $this->supervisorCipher->open(
            wireFrame: $encryptedFrame,
            isBinaryInput: false,
            nowTimestampNs: $nowTimestampNs,
            enforceReplayProtection: true,
        );

        if (!$packet->encrypted || $packet->channel !== self::CONFIG_CHANNEL) {
            throw new RuntimeException('Rejected configuration update: frame must be AEAD-encrypted on the supervisor channel.');
        }

        $senderAllowed = \hash_equals($this->primaryNodeId, $packet->senderNodeId)
            || ($allowBackupSender && $this->backupEnabled && \hash_equals($this->backupNodeId, $packet->senderNodeId));

        if (!$senderAllowed) {
            throw new RuntimeException(
                "Rejected configuration update from unauthorized sender '{$packet->senderNodeId}'."
            );
        }

        if ($packet->recipientNodeId !== '*' && !\hash_equals($this->localNodeId, $packet->recipientNodeId)) {
            throw new RuntimeException("Configuration update targeted '{$packet->recipientNodeId}', not '{$this->localNodeId}'.");
        }

        $body = \is_array($packet->payload) ? $packet->payload : [];
        $namespace = (string) ($body['namespace'] ?? '');
        $values = \is_array($body['values'] ?? null) ? $body['values'] : null;
        $revision = (int) ($body['revision'] ?? 0);

        if ($namespace === '' || !\in_array($namespace, $this->allowedNamespaces, true) || $values === null) {
            throw new RuntimeException('Malformed or disallowed configuration namespace in Supervisor update.');
        }

        if ($revision <= $this->lastAppliedRevision) {
            throw new RuntimeException("Stale Supervisor configuration revision {$revision} (current: {$this->lastAppliedRevision}).");
        }

        Config::applyRuntimeUpdate($namespace, $values, $persistCacheAppPath);
        $this->lastAppliedRevision = $revision;

        return [
            'namespace' => $namespace,
            'revision' => $revision,
            'appliedBy' => $packet->senderNodeId,
        ];
    }

    /**
     * Produces a comprehensive cluster health & supervision report (for Supervisor dashboards / CLI / MCP).
     *
     * @param iterable<NodeTelemetry> $nodes
     * @return array<string, mixed>
     */
    public function inspectCluster(iterable $nodes, ?int $nowNs = null): array
    {
        $currentNs = $nowNs ?? (int) (\microtime(true) * 1_000_000_000);
        $leaderId = $this->evaluateLeadership($nodes, $currentNs);

        $healthyCount = 0;
        $unhealthyNodes = [];
        $driftedNodes = [];
        $totalNodes = 0;

        foreach ($nodes as $node) {
            $totalNodes++;
            if ($node->isRoutable($currentNs, $this->failoverTimeoutSeconds)) {
                $healthyCount++;
            } else {
                $unhealthyNodes[] = $node->nodeId;
            }

            if (\abs($node->clockOffsetMs) > 2000.0) {
                $driftedNodes[] = $node->nodeId;
            }
        }

        return [
            'activeSupervisorId' => $leaderId,
            'primarySupervisorId' => $this->primaryNodeId,
            'backupSupervisorId' => $this->backupEnabled ? $this->backupNodeId : null,
            'isFailoverActive' => $this->backupEnabled && $leaderId === $this->backupNodeId,
            'localIsActingSupervisor' => $this->actingLeader,
            'totalNodes' => $totalNodes,
            'healthyNodes' => $healthyCount,
            'unhealthyNodeIds' => $unhealthyNodes,
            'clockDriftedNodeIds' => $driftedNodes,
            'lastAppliedConfigRevision' => $this->lastAppliedRevision,
        ];
    }

    private function isAuthorizedToPushConfig(): bool
    {
        if (!$this->actingLeader) {
            return false;
        }

        if ($this->localRole === 'supervisor' && \hash_equals($this->primaryNodeId, $this->localNodeId)) {
            return true;
        }

        if ($this->localRole === 'backup_supervisor' && $this->backupEnabled && \hash_equals($this->backupNodeId, $this->localNodeId)) {
            return true;
        }

        return false;
    }

    private function renewLease(): void
    {
        if ($this->redisAdapter !== null && $this->redisAdapter->isAvailable()) {
            $this->redisAdapter->setNx(self::LEASE_KEY, $this->localNodeId, $this->leaseTtlSeconds);
        }
    }

    private function forceLease(string $leaderId): void
    {
        if ($this->redisAdapter !== null && $this->redisAdapter->isAvailable()) {
            $this->redisAdapter->set(self::LEASE_KEY, $leaderId, $this->leaseTtlSeconds);
        }
    }
}
