<?php

declare(strict_types=1);

namespace Atom\Modules\PerformanceProfiler;

/**
 * Complete immutable execution & resource trace of a single request or profiled workload,
 * supporting Visual HTML rendering, AI Agent analysis export, and raw Timonix Cloud telemetry.
 */
final readonly class ProfileTrace
{
    /**
     * @param array{freeBytes: int, totalBytes: int, usedBytes: int, usedPercent: float} $diskVolume
     * @param array{opcacheEnabled: bool, jitEnabled: bool, hitRatePercent: float, usedMemoryBytes: int} $engineStatus
     * @param list<ProfileSpan> $spans
     * @param list<array<string, mixed>> $bottlenecks
     */
    public function __construct(
        public string $traceId,
        public string $method,
        public string $url,
        public int $statusCode,
        public string $startedAtUtc,
        public int $timestampNs,
        public int $totalDurationNs,
        public int $bootDurationNs,
        public int $coreSelfDurationNs,
        public int $clientAppSelfDurationNs,
        public int $databaseDurationNs,
        public int $viewDurationNs,
        public int $cpuUserUs,
        public int $cpuSystemUs,
        public int $memoryStartBytes,
        public int $memoryEndBytes,
        public int $memoryDeltaBytes,
        public int $memoryPeakBytes,
        public int $diskReadDeltaBytes,
        public int $diskWriteDeltaBytes,
        public int $includedFilesDelta,
        public array $diskVolume,
        public array $engineStatus,
        public array $spans,
        public array $bottlenecks,
    ) {}

    public function totalDurationMs(): float
    {
        return \round($this->totalDurationNs / 1_000_000, 3);
    }

    public function cpuTotalMs(): float
    {
        return \round(($this->cpuUserUs + $this->cpuSystemUs) / 1000, 3);
    }

    public function cpuSaturationPercent(): float
    {
        if ($this->totalDurationNs <= 0) {
            return 0.0;
        }

        $cpuNs = ($this->cpuUserUs + $this->cpuSystemUs) * 1000;
        return \clamp(\round(($cpuNs / $this->totalDurationNs) * 100.0, 2), 0.0, 100.0);
    }

    /**
     * Structured diagnostic payload designed specifically for AI coding & performance agents.
     *
     * @return array<string, mixed>
     */
    public function toAgentReport(): array
    {
        $totalNs = \max(1, $this->totalDurationNs);

        return [
            'reportType' => 'atom.profiler.agent_diagnostics.v1',
            'traceId' => $this->traceId,
            'request' => [
                'method' => $this->method,
                'url' => $this->url,
                'statusCode' => $this->statusCode,
                'startedAtUtc' => $this->startedAtUtc,
            ],
            'summary' => [
                'totalWallMs' => $this->totalDurationMs(),
                'kernelBootMs' => \round($this->bootDurationNs / 1_000_000, 3),
                'coreSelfMs' => \round($this->coreSelfDurationNs / 1_000_000, 3),
                'coreSharePercent' => \round(($this->coreSelfDurationNs / $totalNs) * 100.0, 2),
                'clientAppSelfMs' => \round($this->clientAppSelfDurationNs / 1_000_000, 3),
                'clientAppSharePercent' => \round(($this->clientAppSelfDurationNs / $totalNs) * 100.0, 2),
                'databaseMs' => \round($this->databaseDurationNs / 1_000_000, 3),
                'viewRenderMs' => \round($this->viewDurationNs / 1_000_000, 3),
                'bottleneckCount' => \count($this->bottlenecks),
                'primaryCulpritScope' => $this->clientAppSelfDurationNs > $this->coreSelfDurationNs ? 'client_app' : 'core',
            ],
            'resources' => [
                'cpu' => [
                    'userUs' => $this->cpuUserUs,
                    'systemUs' => $this->cpuSystemUs,
                    'totalMs' => $this->cpuTotalMs(),
                    'saturationPercent' => $this->cpuSaturationPercent(),
                ],
                'ram' => [
                    'startBytes' => $this->memoryStartBytes,
                    'endBytes' => $this->memoryEndBytes,
                    'deltaBytes' => $this->memoryDeltaBytes,
                    'deltaMb' => \round($this->memoryDeltaBytes / 1_048_576, 3),
                    'peakMb' => \round($this->memoryPeakBytes / 1_048_576, 3),
                ],
                'disk' => [
                    'readBytes' => $this->diskReadDeltaBytes,
                    'writeBytes' => $this->diskWriteDeltaBytes,
                    'volumeFreeGb' => \round($this->diskVolume['freeBytes'] / 1_073_741_824, 2),
                    'volumeTotalGb' => \round($this->diskVolume['totalBytes'] / 1_073_741_824, 2),
                    'volumeUsedPercent' => $this->diskVolume['usedPercent'],
                ],
                'engine' => [
                    'phpVersion' => \PHP_VERSION,
                    'zts' => \PHP_ZTS === 1,
                    'includedFilesDelta' => $this->includedFilesDelta,
                    ...$this->engineStatus,
                ],
            ],
            'bottlenecks' => $this->bottlenecks,
            'flowSpans' => \array_map(
                fn(ProfileSpan $s): array => $s->toArray($this->totalDurationNs),
                $this->spans,
            ),
        ];
    }

    /**
     * Ultra-compact, raw metrics-only telemetry packet (`timonix.cloud.telemetry.v1`) with zero UI/prose overhead,
     * enabling Timonix Cloud to ingest raw measurements and build automated analytics & visual graphs externally.
     *
     * @return array<string, mixed>
     */
    public function toTimonixCloudTelemetry(string $projectKey = '', string $nodeId = 'local'): array
    {
        return [
            'schema' => 'timonix.cloud.telemetry.v1',
            'project' => $projectKey,
            'node' => $nodeId,
            'traceId' => $this->traceId,
            'tsNs' => $this->timestampNs,
            'http' => [$this->method, $this->url, $this->statusCode],
            'durationsNs' => [
                'total' => $this->totalDurationNs,
                'boot' => $this->bootDurationNs,
                'coreSelf' => $this->coreSelfDurationNs,
                'appSelf' => $this->clientAppSelfDurationNs,
                'db' => $this->databaseDurationNs,
                'view' => $this->viewDurationNs,
            ],
            'resources' => [
                'cpuUserUs' => $this->cpuUserUs,
                'cpuSysUs' => $this->cpuSystemUs,
                'ramStartB' => $this->memoryStartBytes,
                'ramEndB' => $this->memoryEndBytes,
                'ramDeltaB' => $this->memoryDeltaBytes,
                'ramPeakB' => $this->memoryPeakBytes,
                'diskReadB' => $this->diskReadDeltaBytes,
                'diskWriteB' => $this->diskWriteDeltaBytes,
                'diskFreeB' => $this->diskVolume['freeBytes'],
                'diskTotalB' => $this->diskVolume['totalBytes'],
                'filesDelta' => $this->includedFilesDelta,
                'jit' => $this->engineStatus['jitEnabled'] ? 1 : 0,
                'opcacheHitPct' => $this->engineStatus['hitRatePercent'],
            ],
            'spanColumns' => [
                'id', 'parentId', 'name', 'category', 'scope', 'startNs', 'durNs', 'selfNs', 'cpuUs', 'memDeltaB', 'diskDeltaB', 'file', 'line', 'err',
            ],
            'spans' => \array_map(
                static fn(ProfileSpan $s): array => $s->toTimonixCloudTuple(),
                $this->spans,
            ),
            'bottleneckSpanIds' => \array_values(\array_unique(\array_map(
                static fn(array $b): string => (string) ($b['spanId'] ?? ''),
                $this->bottlenecks,
            ))),
        ];
    }
}
