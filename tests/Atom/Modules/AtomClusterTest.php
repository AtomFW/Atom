<?php

declare(strict_types=1);

namespace Tests\Atom\Modules;

use Atom\Cache\InMemoryRedisAdapter;
use Atom\Config\Config;
use Atom\Modules\AtomCluster\AtomCluster;
use Atom\Modules\AtomCluster\NodeTelemetry;
use Atom\Modules\ModuleMigrationRunner;
use Atom\Support\DataRegistry;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use SortDirection;

final class AtomClusterTest extends TestCase
{
    protected function tearDown(): void
    {
        $ref = new ReflectionClass(Config::class);
        $fromProp = $ref->getProperty('from');
        $fromProp->setValue(null, []);
    }

    public function testRealTimeSyncLoadBalancingAndAllTrafficViaRedis(): void
    {
        $sharedRedis = new InMemoryRedisAdapter(true);
        $clusterKey = 'cluster-shared-aead-key-2026';

        $node1 = new AtomCluster([
            'clusterId' => 'prod-eu',
            'default' => 'redis',
            'choices' => ['redis' => ['driver' => 'redis', 'on' => true, 'routeAllTrafficViaRedis' => true]],
            'node' => ['id' => 'node-1', 'role' => 'worker', 'endpoint' => 'https://n1.internal', 'weight' => 100],
            'encryption' => ['on' => true, 'sharedKey' => $clusterKey, 'keyId' => 'ck1'],
            'loadBalancer' => ['strategy' => 'least_load', 'localFastPathThreshold' => 0.20, 'circuitBreakerFailures' => 2],
        ], $sharedRedis);

        $node2 = new AtomCluster([
            'clusterId' => 'prod-eu',
            'default' => 'redis',
            'choices' => ['redis' => ['driver' => 'redis', 'on' => true, 'routeAllTrafficViaRedis' => true]],
            'node' => ['id' => 'node-2', 'role' => 'worker', 'endpoint' => 'https://n2.internal', 'weight' => 100],
            'encryption' => ['on' => true, 'sharedKey' => $clusterKey, 'keyId' => 'ck1'],
            'loadBalancer' => ['strategy' => 'least_load', 'localFastPathThreshold' => 0.20, 'circuitBreakerFailures' => 2],
        ], $sharedRedis);

        self::assertTrue($node1->isRedisRoutingActive());
        self::assertTrue($node2->isRedisRoutingActive());

        $nowNs = (int) (\microtime(true) * 1_000_000_000);

        // Node 1 is heavily loaded (CPU 3.5, RAM 900/1024 MB, 450 conns), Node 2 is nearly idle!
        $node1->heartbeat(new NodeTelemetry(
            nodeId: 'node-1',
            endpoint: 'https://n1.internal',
            role: 'worker',
            cpuLoad: 3.5,
            usedRamMb: 900.0,
            totalRamMb: 1024.0,
            activeConnections: 450,
            rttMs: 2.0,
            timestampNs: $nowNs,
            clockOffsetMs: 1.2,
        ));

        $node2->heartbeat(new NodeTelemetry(
            nodeId: 'node-2',
            endpoint: 'https://n2.internal',
            role: 'worker',
            cpuLoad: 0.10,
            usedRamMb: 128.0,
            totalRamMb: 1024.0,
            activeConnections: 4,
            rttMs: 0.4,
            timestampNs: $nowNs,
            clockOffsetMs: 0.3,
        ));

        // Both nodes see each other's encrypted telemetry via Redis
        $visibleFromNode1 = $node1->getNodes();
        self::assertCount(2, $visibleFromNode1);
        self::assertSame(128.0, $visibleFromNode1['node-2']->usedRamMb);

        $ranked = $node1->loadBalancer()->rankNodes(\array_values($visibleFromNode1), SortDirection::Ascending, $nowNs);
        self::assertSame('node-2', $ranked[0]->nodeId);
        self::assertSame('node-1', $ranked[1]->nodeId);

        // Real-time custom developer data synchronization across cluster via Redis + DataRegistry mirroring
        $registryOnNode2 = new DataRegistry();
        $node1->syncState('sklep.promocja.kod', 'ATOM50');
        self::assertSame('ATOM50', $node2->getSharedState('sklep.promocja.kod'));

        $node2->syncState('jakaśAplikacjia.cluster.region', 'Warszawa-DC1', $registryOnNode2);
        self::assertSame('Warszawa-DC1', $node1->getSharedState('jakaśAplikacjia.cluster.region'));
        self::assertSame('Warszawa-DC1', $registryOnNode2->get('jakaśAplikacjia.cluster.region'));

        // Dispatch balanced traffic: because node-1 is overloaded, load balancer routes to node-2 via Redis!
        $node1->setPeerTrafficExecutor(static fn(NodeTelemetry $peer, array $payload): array => [
            'handledBy' => $peer->nodeId,
            'job' => $payload['job'],
        ]);

        $dispatch = $node1->dispatchBalancedTraffic(['job' => 'render-invoice']);
        self::assertSame('node-2', $dispatch['targetNodeId']);
        self::assertTrue($dispatch['viaRedis']);
        self::assertFalse($dispatch['localFastPath']);
        self::assertSame(['handledBy' => 'node-2', 'job' => 'render-invoice'], $dispatch['result']);

        $queuedForNode2 = $node2->drainRedisTrafficQueue();
        self::assertCount(1, $queuedForNode2);
        self::assertSame('node-2', $queuedForNode2[0]['target']);

        // Circuit breaker failover: if node-2 throws, automatic retry routes seamlessly to local node-1
        $node1->setPeerTrafficExecutor(static function (NodeTelemetry $peer): never {
            throw new RuntimeException("Peer {$peer->nodeId} connection reset.");
        });
        $failoverResult = $node1->dispatchBalancedTraffic(
            ['job' => 'fallback-task'],
            static fn(array $p): string => 'handled-locally:' . (string) $p['job'],
        );
        self::assertSame('node-1', $failoverResult['targetNodeId']);
        self::assertSame('handled-locally:fallback-task', $failoverResult['result']);
    }

    public function testSupervisorOnlyEncryptedConfigUpdatesAndBackupSupervisorFailover(): void
    {
        $sharedRedis = new InMemoryRedisAdapter(true);
        $supSecret = 'top-secret-supervisor-signing-key-2026';

        $supOptions = [
            'on' => true,
            'onlySupervisorUpdatesConfig' => true,
            'primaryNodeId' => 'sup-primary',
            'secretKey' => $supSecret,
            'leaseTtlSeconds' => 15,
            'backupSupervisor' => [
                'on' => true,
                'nodeId' => 'sup-backup',
                'failoverTimeoutSeconds' => 10,
                'autoStepDownOnPrimaryRecovery' => true,
            ],
        ];

        $baseClusterOpts = [
            'clusterId' => 'sup-cluster',
            'default' => 'redis',
            'choices' => ['redis' => ['driver' => 'redis', 'on' => true]],
            'encryption' => ['on' => true, 'sharedKey' => 'cluster-net-key'],
            'supervisor' => $supOptions,
        ];

        $primarySup = new AtomCluster([...$baseClusterOpts, 'node' => ['id' => 'sup-primary', 'role' => 'supervisor', 'endpoint' => 'https://sup1']], $sharedRedis);
        $backupSup = new AtomCluster([...$baseClusterOpts, 'node' => ['id' => 'sup-backup', 'role' => 'backup_supervisor', 'endpoint' => 'https://sup2']], $sharedRedis);
        $workerNode = new AtomCluster([...$baseClusterOpts, 'node' => ['id' => 'worker-1', 'role' => 'worker', 'endpoint' => 'https://w1']], $sharedRedis);

        $nowNs = (int) (\microtime(true) * 1_000_000_000);
        $primarySup->heartbeat(new NodeTelemetry('sup-primary', 'https://sup1', 'supervisor', timestampNs: $nowNs));
        $backupSup->heartbeat(new NodeTelemetry('sup-backup', 'https://sup2', 'backup_supervisor', timestampNs: $nowNs));
        $workerNode->heartbeat(new NodeTelemetry('worker-1', 'https://w1', 'worker', timestampNs: $nowNs));

        // 1. Worker node MUST NOT be allowed to issue cluster configuration updates
        try {
            $workerNode->supervisor()->createEncryptedConfigUpdate('app', ['name' => 'HackedByWorker']);
            self::fail('Worker node must not be able to push config updates.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not an active Atom Supervisor', $e->getMessage());
        }

        // 2. Standby Backup Supervisor MUST NOT push config while Primary Supervisor is healthy
        self::assertSame('sup-primary', $backupSup->supervisor()->evaluateLeadership($backupSup->getNodes(), $nowNs));
        self::assertFalse($backupSup->supervisor()->isActingSupervisor());

        // 3. Primary Supervisor pushes encrypted config update -> Worker decrypts & applies it
        $encryptedPkg = $primarySup->supervisor()->createEncryptedConfigUpdate('app', ['name' => 'AtomSupervisedCluster']);
        $applied = $workerNode->supervisor()->applyEncryptedConfigUpdate($encryptedPkg);

        self::assertSame('app', $applied['namespace']);
        self::assertSame(1, $applied['revision']);
        self::assertSame('sup-primary', $applied['appliedBy']);
        self::assertSame('AtomSupervisedCluster', config('app.name'));

        // 4. Simulate Primary Supervisor outage (heartbeat expired by 25 seconds > 10s failover timeout)
        $futureNs = $nowNs + (25 * 1_000_000_000);
        $leaderDuringOutage = $backupSup->supervisor()->evaluateLeadership($backupSup->getNodes(), $futureNs);
        self::assertSame('sup-backup', $leaderDuringOutage);
        self::assertTrue($backupSup->supervisor()->isActingSupervisor());

        // Promoted Backup Supervisor can now push encrypted config revision 2!
        $failoverPkg = $backupSup->supervisor()->createEncryptedConfigUpdate(
            namespace: 'app',
            configValues: ['name' => 'ManagedByBackupSupervisor'],
            explicitRevision: 2,
            nowTimestampNs: $futureNs,
        );
        $appliedFailover = $workerNode->supervisor()->applyEncryptedConfigUpdate($failoverPkg, nowTimestampNs: $futureNs);
        self::assertSame(2, $appliedFailover['revision']);
        self::assertSame('sup-backup', $appliedFailover['appliedBy']);
        self::assertSame('ManagedByBackupSupervisor', config('app.name'));

        // 5. Primary Supervisor recovers -> Backup Supervisor steps down automatically
        $recoveryNs = $futureNs + (2 * 1_000_000_000);
        $primarySup->heartbeat(new NodeTelemetry('sup-primary', 'https://sup1', 'supervisor', timestampNs: $recoveryNs));
        self::assertSame('sup-primary', $backupSup->supervisor()->evaluateLeadership($backupSup->getNodes(), $recoveryNs));
        self::assertFalse($backupSup->supervisor()->isActingSupervisor());
    }

    public function testRemoteClusterDatabaseUnifiedInterfaceAndMigrations(): void
    {
        $remotePdo = new PDO('sqlite::memory:');
        $remotePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $cluster = new AtomCluster([
            'clusterId' => 'db-cluster',
            'remoteDatabase' => ['on' => true, 'prefix' => 'cl_'],
        ]);
        $db = $cluster->databaseManager()->bindPdo($remotePdo, 'cl_');

        $runner = new ModuleMigrationRunner($remotePdo, 'cl_');
        $applied = $runner->migrateUp('AtomCluster');
        self::assertSame([
            'AtomCluster:ac0001_create_cluster_nodes',
            'AtomCluster:ac0002_create_cluster_config_revisions',
        ], $applied);

        $cluster->database()->insertInto('cluster_nodes')
            ->values([
                'cluster_id' => ':cid',
                'node_id' => ':nid',
                'endpoint_url' => ':url',
                'role' => ':role',
                'updated_at' => ':ts',
            ])
            ->setParameters([
                'cid' => 'db-cluster',
                'nid' => 'node-db-1',
                'url' => 'https://db1.internal',
                'role' => 'worker',
                'ts' => '2026-11-27 18:00:00',
            ])
            ->executeStatement();

        $row = $db->selectFrom('cluster_nodes')->where('node_id = :nid')->setParameter('nid', 'node-db-1')->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame('https://db1.internal', $row['endpoint_url']);

        $rolledBack = $runner->migrateDown('AtomCluster', 2);
        self::assertCount(2, $rolledBack);
    }
}
