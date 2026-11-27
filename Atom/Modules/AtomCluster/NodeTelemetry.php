<?php

declare(strict_types=1);

namespace Atom\Modules\AtomCluster;

/**
 * Immutable real-time telemetry snapshot synchronized permanently across all Atom cluster nodes
 * (CPU load, RAM usage, active connections, nanosecond UTC clock & drift, RTT latency, health, and role).
 */
final readonly class NodeTelemetry
{
    public function __construct(
        public string $nodeId,
        public string $endpoint,
        public string $role = 'worker',
        public float $cpuLoad = 0.0,
        public float $usedRamMb = 0.0,
        public float $totalRamMb = 1024.0,
        public int $memoryUsageBytes = 0,
        public int $memoryPeakBytes = 0,
        public int $activeConnections = 0,
        public float $rttMs = 0.5,
        public int $timestampNs = 0,
        public float $clockOffsetMs = 0.0,
        public string $timezone = 'UTC',
        public int $weight = 100,
        public string $region = 'eu-central',
        public bool $healthy = true,
        public bool $drained = false,
        public int $consecutiveFailures = 0,
        public float $circuitOpenUntil = 0.0,
    ) {}

    /**
     * Captures live telemetry of the current PHP 8.6 Atom runtime process.
     */
    public static function captureLocal(
        string $nodeId,
        string $endpoint,
        string $role = 'worker',
        int $weight = 100,
        string $region = 'eu-central',
        int $activeConnections = 0,
        ?int $clusterReferenceTimeNs = null,
    ): self {
        $memBytes = \memory_get_usage(true);
        $peakBytes = \memory_get_peak_usage(true);
        $usedRamMb = \round($memBytes / 1_048_576, 2);
        $totalRamMb = self::resolveMemoryLimitMb($usedRamMb);

        $cpuLoad = 0.05;
        if (\function_exists('sys_getloadavg')) {
            $loads = \sys_getloadavg();
            if (\is_array($loads)) {
                $cpuLoad = \clamp((float) $loads[0], 0.0, 16.0);
            }
        }

        $nowNs = (int) (\microtime(true) * 1_000_000_000);
        $clockOffsetMs = $clusterReferenceTimeNs !== null
            ? \round(($nowNs - $clusterReferenceTimeNs) / 1_000_000, 3)
            : 0.0;

        return new self(
            nodeId: $nodeId,
            endpoint: $endpoint,
            role: $role,
            cpuLoad: $cpuLoad,
            usedRamMb: $usedRamMb,
            totalRamMb: $totalRamMb,
            memoryUsageBytes: $memBytes,
            memoryPeakBytes: $peakBytes,
            activeConnections: \max(0, $activeConnections),
            rttMs: 0.1,
            timestampNs: $nowNs,
            clockOffsetMs: $clockOffsetMs,
            timezone: \date_default_timezone_get(),
            weight: \clamp($weight, 1, 1000),
            region: $region,
            healthy: true,
            drained: false,
        );
    }

    /**
     * Returns normalized RAM utilization ratio (`0.0..1.0`).
     */
    public function ramUtilizationRatio(): float
    {
        if ($this->totalRamMb <= 0.0) {
            return 0.0;
        }

        return $this->usedRamMb / $this->totalRamMb |> \clamp(?, 0.0, 1.0);
    }

    /**
     * Returns an updated immutable `NodeTelemetry` snapshot with modified circuit-breaker health state.
     */
    public function withCircuitState(int $consecutiveFailures, float $circuitOpenUntil, bool $healthy): self
    {
        return clone($this, [
            'consecutiveFailures' => $consecutiveFailures,
            'circuitOpenUntil' => $circuitOpenUntil,
            'healthy' => $healthy,
        ]);
    }

    /**
     * Checks if the node's heartbeat has not expired and its circuit breaker is closed.
     */
    public function isRoutable(int $nowNs, int $heartbeatTtlSeconds, ?float $nowFloat = null): bool
    {
        if (!$this->healthy || $this->drained) {
            return false;
        }

        $currentFloat = $nowFloat ?? ($nowNs / 1_000_000_000);
        if ($this->circuitOpenUntil > 0.0 && $this->circuitOpenUntil > $currentFloat) {
            return false;
        }

        if ($this->timestampNs > 0 && $heartbeatTtlSeconds > 0) {
            $ageNs = $nowNs - $this->timestampNs;
            if ($ageNs > ($heartbeatTtlSeconds * 1_000_000_000)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'nodeId' => $this->nodeId,
            'endpoint' => $this->endpoint,
            'role' => $this->role,
            'cpuLoad' => $this->cpuLoad,
            'usedRamMb' => $this->usedRamMb,
            'totalRamMb' => $this->totalRamMb,
            'memoryUsageBytes' => $this->memoryUsageBytes,
            'memoryPeakBytes' => $this->memoryPeakBytes,
            'activeConnections' => $this->activeConnections,
            'rttMs' => $this->rttMs,
            'timestampNs' => $this->timestampNs,
            'clockOffsetMs' => $this->clockOffsetMs,
            'timezone' => $this->timezone,
            'weight' => $this->weight,
            'region' => $this->region,
            'healthy' => $this->healthy,
            'drained' => $this->drained,
            'consecutiveFailures' => $this->consecutiveFailures,
            'circuitOpenUntil' => $this->circuitOpenUntil,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            nodeId: (string) ($data['nodeId'] ?? ''),
            endpoint: (string) ($data['endpoint'] ?? ''),
            role: (string) ($data['role'] ?? 'worker'),
            cpuLoad: (float) ($data['cpuLoad'] ?? 0.0),
            usedRamMb: (float) ($data['usedRamMb'] ?? 0.0),
            totalRamMb: \max(1.0, (float) ($data['totalRamMb'] ?? 1024.0)),
            memoryUsageBytes: (int) ($data['memoryUsageBytes'] ?? 0),
            memoryPeakBytes: (int) ($data['memoryPeakBytes'] ?? 0),
            activeConnections: (int) ($data['activeConnections'] ?? 0),
            rttMs: (float) ($data['rttMs'] ?? 0.5),
            timestampNs: (int) ($data['timestampNs'] ?? 0),
            clockOffsetMs: (float) ($data['clockOffsetMs'] ?? 0.0),
            timezone: (string) ($data['timezone'] ?? 'UTC'),
            weight: \clamp((int) ($data['weight'] ?? 100), 1, 1000),
            region: (string) ($data['region'] ?? 'eu-central'),
            healthy: (bool) ($data['healthy'] ?? true),
            drained: (bool) ($data['drained'] ?? false),
            consecutiveFailures: (int) ($data['consecutiveFailures'] ?? 0),
            circuitOpenUntil: (float) ($data['circuitOpenUntil'] ?? 0.0),
        );
    }

    private static function resolveMemoryLimitMb(float $usedMb): float
    {
        $raw = \trim((string) \ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return \max(1024.0, $usedMb * 4.0);
        }

        $unit = \strtolower(\substr($raw, -1));
        $num = (float) $raw;

        $bytes = match ($unit) {
            'g' => $num * 1_073_741_824,
            'm' => $num * 1_048_576,
            'k' => $num * 1024,
            default => $num,
        };

        return \max(16.0, \round($bytes / 1_048_576, 2));
    }
}
