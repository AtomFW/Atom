<?php

declare(strict_types=1);

namespace Atom\Modules\AtomCluster;

use SortDirection;

/**
 * Low-latency cluster traffic load balancer scoring nodes across CPU load, RAM pressure,
 * active connections, RTT latency, capacity weight, and clock synchronization health.
 */
final class ClusterLoadBalancer
{
    private string $strategy;
    private float $cpuWeight;
    private float $ramWeight;
    private float $connectionsWeight;
    private float $latencyWeight;
    private float $localFastPathThreshold;
    private int $maxClockDriftMs;
    private int $heartbeatTtlSeconds;
    private int $roundRobinCursor = 0;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->strategy = (string) ($config['strategy'] ?? 'least_load');
        $this->cpuWeight = \clamp((float) ($config['cpuWeight'] ?? 0.40), 0.0, 1.0);
        $this->ramWeight = \clamp((float) ($config['ramWeight'] ?? 0.30), 0.0, 1.0);
        $this->connectionsWeight = \clamp((float) ($config['connectionsWeight'] ?? 0.20), 0.0, 1.0);
        $this->latencyWeight = \clamp((float) ($config['latencyWeight'] ?? 0.10), 0.0, 1.0);
        $this->localFastPathThreshold = \clamp((float) ($config['localFastPathThreshold'] ?? 0.75), 0.0, 1.0);
        $this->maxClockDriftMs = \clamp((int) ($config['maxClockDriftMs'] ?? 2000), 10, 600_000);
        $this->heartbeatTtlSeconds = \clamp((int) ($config['heartbeatTtlSeconds'] ?? 10), 1, 3600);
    }

    /**
     * Computes a normalized load score (`0.0` = idle/fastest, higher = busier/slower) for a node.
     */
    public function computeScore(NodeTelemetry $node): float
    {
        $cpuNorm = \clamp($node->cpuLoad, 0.0, 4.0) / 4.0;
        $ramNorm = $node->ramUtilizationRatio();
        $connNorm = \clamp($node->activeConnections / 1000.0, 0.0, 1.0);
        $rttNorm = \clamp($node->rttMs / 250.0, 0.0, 1.0);

        $composite = ($cpuNorm * $this->cpuWeight)
            + ($ramNorm * $this->ramWeight)
            + ($connNorm * $this->connectionsWeight)
            + ($rttNorm * $this->latencyWeight);

        // Adjust by node weight (higher weight = lower effective load cost)
        $weightFactor = 100.0 / (float) \clamp($node->weight, 1, 1000);
        $score = $composite * $weightFactor;

        // Penalize excessive clock drift to protect distributed consistency
        if (\abs($node->clockOffsetMs) > $this->maxClockDriftMs) {
            $score += 0.50;
        }

        return \round($score, 6);
    }

    /**
     * Ranks healthy, non-expired cluster nodes using PHP 8.6 `\SortDirection`.
     *
     * @param iterable<NodeTelemetry> $nodes
     * @return list<NodeTelemetry>
     */
    public function rankNodes(
        iterable $nodes,
        SortDirection $direction = SortDirection::Ascending,
        ?int $nowNs = null,
        bool $includeSupervisorOnlyIfWorker = true,
    ): array {
        $currentNs = $nowNs ?? (int) (\microtime(true) * 1_000_000_000);
        $routable = [];

        foreach ($nodes as $node) {
            if (!$node->isRoutable($currentNs, $this->heartbeatTtlSeconds)) {
                continue;
            }
            if ($includeSupervisorOnlyIfWorker && $node->role === 'supervisor') {
                continue;
            }
            $routable[] = $node;
        }

        // If only supervisor nodes exist in a small cluster, allow routing rather than dropping traffic
        if ($routable === [] && $includeSupervisorOnlyIfWorker) {
            foreach ($nodes as $node) {
                if ($node->isRoutable($currentNs, $this->heartbeatTtlSeconds)) {
                    $routable[] = $node;
                }
            }
        }

        \usort($routable, function (NodeTelemetry $a, NodeTelemetry $b) use ($direction): int {
            $metricA = match ($this->strategy) {
                'least_connections' => (float) $a->activeConnections / \max(1, $a->weight),
                'latency_aware' => $a->rttMs,
                default => $this->computeScore($a),
            };
            $metricB = match ($this->strategy) {
                'least_connections' => (float) $b->activeConnections / \max(1, $b->weight),
                'latency_aware' => $b->rttMs,
                default => $this->computeScore($b),
            };

            $cmp = $metricA <=> $metricB;
            if ($cmp === 0) {
                $cmp = $a->nodeId <=> $b->nodeId;
            }

            return $direction === SortDirection::Ascending ? $cmp : -$cmp;
        });

        return $routable;
    }

    /**
     * Selects the optimal target node for an incoming request with local fast-path optimization
     * (avoiding network hops when the local node has ample spare capacity).
     *
     * @param iterable<NodeTelemetry> $nodes
     * @param list<string> $excludedNodeIds
     */
    public function selectTargetNode(
        iterable $nodes,
        ?string $localNodeId = null,
        ?string $stickyRoutingKey = null,
        array $excludedNodeIds = [],
        ?int $nowNs = null,
    ): ?NodeTelemetry {
        $ranked = $this->rankNodes($nodes, SortDirection::Ascending, $nowNs);
        if ($excludedNodeIds !== []) {
            $ranked = \array_values(\array_filter(
                $ranked,
                static fn(NodeTelemetry $n): bool => !\in_array($n->nodeId, $excludedNodeIds, true),
            ));
        }

        if ($ranked === []) {
            return null;
        }

        if ($this->strategy === 'consistent_hash' && $stickyRoutingKey !== null && $stickyRoutingKey !== '') {
            $bestHash = -1;
            $chosen = $ranked[0];
            foreach ($ranked as $candidate) {
                $hash = \crc32($stickyRoutingKey . '|' . $candidate->nodeId);
                if ($hash > $bestHash) {
                    $bestHash = $hash;
                    $chosen = $candidate;
                }
            }
            return $chosen;
        }

        if ($this->strategy === 'round_robin') {
            $index = $this->roundRobinCursor % \count($ranked);
            $this->roundRobinCursor = ($this->roundRobinCursor + 1) % 1_000_000;
            return $ranked[$index];
        }

        // Local fast-path: if local node is healthy and lightly loaded, avoid extra network hop latency
        if ($localNodeId !== null && $this->strategy === 'least_load') {
            $localNode = \array_find(
                $ranked,
                static fn(NodeTelemetry $n): bool => $n->nodeId === $localNodeId,
            );
            if ($localNode instanceof NodeTelemetry) {
                $localScore = $this->computeScore($localNode);
                $bestScore = $this->computeScore($ranked[0]);
                if ($localScore <= $this->localFastPathThreshold && ($localScore - $bestScore) <= 0.15) {
                    return $localNode;
                }
            }
        }

        return \array_first($ranked);
    }
}
