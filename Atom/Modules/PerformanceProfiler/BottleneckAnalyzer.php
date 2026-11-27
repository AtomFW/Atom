<?php

declare(strict_types=1);

namespace Atom\Modules\PerformanceProfiler;

use SortDirection;

/**
 * Automated bottleneck & hotspot analyzer diagnosing CPU-bound stalls, blocking I/O waits,
 * memory allocation spikes, slow/repeated SQL queries (N+1), and disk pressure across both
 * Atom Core (`originScope = 'core'`) and Client Applications (`originScope = 'client_app'`).
 */
final readonly class BottleneckAnalyzer
{
    private float $warningSpanMs;
    private float $criticalSpanMs;
    private float $hotspotSharePercent;
    private int $memorySpikeBytes;
    private float $slowQueryMs;

    /**
     * @param array<string, mixed> $thresholds
     */
    public function __construct(array $thresholds = [])
    {
        $this->warningSpanMs = \clamp((float) ($thresholds['warningSpanMs'] ?? 15.0), 0.1, 60_000.0);
        $this->criticalSpanMs = \clamp((float) ($thresholds['criticalSpanMs'] ?? 50.0), 0.5, 120_000.0);
        $this->hotspotSharePercent = \clamp((float) ($thresholds['hotspotSharePercent'] ?? 30.0), 5.0, 100.0);
        $this->memorySpikeBytes = \clamp((int) ($thresholds['memorySpikeBytes'] ?? 2_097_152), 1024, 1_073_741_824);
        $this->slowQueryMs = \clamp((float) ($thresholds['slowQueryMs'] ?? 10.0), 0.1, 60_000.0);
    }

    /**
     * Inspects completed spans and returns prioritized bottleneck findings sorted by severity & self-time.
     *
     * @param list<ProfileSpan> $spans
     * @return list<array{
     *     severity: string,
     *     type: string,
     *     originScope: string,
     *     spanId: string,
     *     spanName: string,
     *     file: ?string,
     *     line: ?int,
     *     selfDurationMs: float,
     *     totalDurationMs: float,
     *     sharePercent: float,
     *     cpuTotalMs: float,
     *     memoryDeltaBytes: int,
     *     diskDeltaBytes: int,
     *     diagnosis: string,
     *     recommendation: string
     * }>
     */
    public function analyze(
        array $spans,
        int $totalTraceDurationNs,
        SortDirection $direction = SortDirection::Descending,
    ): array {
        $findings = [];
        $totalTraceMs = $totalTraceDurationNs / 1_000_000;

        /** @var array<string, list<ProfileSpan>> $dbQueriesBySignature */
        $dbQueriesBySignature = [];

        foreach ($spans as $span) {
            $selfMs = $span->selfDurationMs();
            $sharePct = $totalTraceDurationNs > 0
                ? \round(($span->selfDurationNs / $totalTraceDurationNs) * 100.0, 2)
                : 0.0;

            if ($span->category === 'database') {
                $dbQueriesBySignature[$span->name][] = $span;
            }

            // 1. Slow SQL query check
            if ($span->category === 'database' && $span->durationMs() >= $this->slowQueryMs) {
                $sev = $span->durationMs() >= $this->criticalSpanMs ? 'critical' : 'warning';
                $findings[] = $this->makeFinding(
                    severity: $sev,
                    type: 'slow_database_query',
                    span: $span,
                    sharePct: $sharePct,
                    diagnosis: \sprintf('Slow database operation (%0.2f ms) in %s.', $span->durationMs(), $span->name),
                    recommendation: 'Verify composite SQL indexes, avoid SELECT *, and enable Redis caching or connection pooling.',
                );
                continue;
            }

            // 2. Time-based bottleneck (CPU-bound vs I/O wait classification)
            $isCriticalTime = $selfMs >= $this->criticalSpanMs;
            $isWarningTime = $selfMs >= $this->warningSpanMs;
            $isDominantHotspot = $totalTraceMs >= 5.0 && $sharePct >= $this->hotspotSharePercent && $span->name !== 'kernel.handle';

            if ($isCriticalTime || $isWarningTime || $isDominantHotspot) {
                $cpuMs = $span->cpuTotalMs();
                $cpuRatio = $selfMs > 0.0 ? ($cpuMs / $selfMs) : 0.0;

                if ($span->diskTotalDeltaBytes() >= 262_144) {
                    $bType = 'disk_io_bottleneck';
                    $diag = \sprintf(
                        'Heavy disk I/O bottleneck (%0.2f ms self-time, %0.1f KB disk transfer) in %s (%s).',
                        $selfMs,
                        $span->diskTotalDeltaBytes() / 1024,
                        $span->name,
                        $span->originScope,
                    );
                    $rec = 'Stream files in chunks (Generator), cache compiled templates/configs in RAM/Redis, and avoid synchronous disk writes on hot paths.';
                } elseif ($cpuRatio >= 0.65) {
                    $bType = 'cpu_bound_bottleneck';
                    $diag = \sprintf(
                        'CPU-bound bottleneck (%0.2f ms self-time, %0.2f ms CPU active, %0.1f%% of trace) in %s (%s).',
                        $selfMs,
                        $cpuMs,
                        $sharePct,
                        $span->name,
                        $span->originScope,
                    );
                    $rec = 'Optimize algorithmic loops, leverage PHP 8.6 PFA / array_find / OPcache JIT, and cache repeated computations.';
                } else {
                    $bType = 'io_wait_or_lock_bottleneck';
                    $diag = \sprintf(
                        'Wall-clock wait bottleneck (%0.2f ms self-time vs %0.2f ms CPU, %0.1f%% of trace) in %s (%s).',
                        $selfMs,
                        $cpuMs,
                        $sharePct,
                        $span->name,
                        $span->originScope,
                    );
                    $rec = 'Inspect blocking network/socket/lock waits; multiplex streams with PHP 8.6 \\Io\\Poll\\Context or offload slow tasks to Redis queues.';
                }

                $findings[] = $this->makeFinding(
                    severity: $isCriticalTime ? 'critical' : 'warning',
                    type: $bType,
                    span: $span,
                    sharePct: $sharePct,
                    diagnosis: $diag,
                    recommendation: $rec,
                );
            }

            // 3. Memory spike check
            if ($span->memoryDeltaBytes >= $this->memorySpikeBytes) {
                $mb = \round($span->memoryDeltaBytes / 1_048_576, 2);
                $findings[] = $this->makeFinding(
                    severity: $span->memoryDeltaBytes >= ($this->memorySpikeBytes * 4) ? 'critical' : 'warning',
                    type: 'memory_allocation_spike',
                    span: $span,
                    sharePct: $sharePct,
                    diagnosis: \sprintf('Memory spike (+%0.2f MB allocated) during %s (%s).', $mb, $span->name, $span->originScope),
                    recommendation: 'Replace large array buffering / fetchAll() with Generator (yield) streaming and packed arrays.',
                );
            }
        }

        // 4. N+1 repeated database query pattern detection
        foreach ($dbQueriesBySignature as $sig => $group) {
            $count = \count($group);
            if ($count >= 3) {
                $totalGroupNs = 0;
                foreach ($group as $s) {
                    $totalGroupNs += $s->durationNs;
                }
                $first = $group[0];
                $groupShare = $totalTraceDurationNs > 0 ? \round(($totalGroupNs / $totalTraceDurationNs) * 100.0, 2) : 0.0;
                $findings[] = [
                    'severity' => $count >= 8 ? 'critical' : 'warning',
                    'type' => 'n_plus_one_query',
                    'originScope' => $first->originScope,
                    'spanId' => $first->spanId,
                    'spanName' => $sig . " (x{$count})",
                    'file' => $first->file,
                    'line' => $first->line,
                    'selfDurationMs' => \round($totalGroupNs / 1_000_000, 3),
                    'totalDurationMs' => \round($totalGroupNs / 1_000_000, 3),
                    'sharePercent' => $groupShare,
                    'cpuTotalMs' => $first->cpuTotalMs(),
                    'memoryDeltaBytes' => $first->memoryDeltaBytes,
                    'diskDeltaBytes' => 0,
                    'diagnosis' => \sprintf('Repeated database operation %s executed %d times (potential N+1 query bottleneck).', $sig, $count),
                    'recommendation' => 'Batch queries using WHERE IN (...) or JOINs instead of querying inside a loop.',
                ];
            }
        }

        \usort($findings, static function (array $a, array $b) use ($direction): int {
            $sevRankA = $a['severity'] === 'critical' ? 2 : 1;
            $sevRankB = $b['severity'] === 'critical' ? 2 : 1;
            $cmp = $sevRankA !== $sevRankB
                ? ($sevRankA <=> $sevRankB)
                : ($a['selfDurationMs'] <=> $b['selfDurationMs']);

            return $direction === SortDirection::Descending ? -$cmp : $cmp;
        });

        return $findings;
    }

    /**
     * @return array{
     *     severity: string,
     *     type: string,
     *     originScope: string,
     *     spanId: string,
     *     spanName: string,
     *     file: ?string,
     *     line: ?int,
     *     selfDurationMs: float,
     *     totalDurationMs: float,
     *     sharePercent: float,
     *     cpuTotalMs: float,
     *     memoryDeltaBytes: int,
     *     diskDeltaBytes: int,
     *     diagnosis: string,
     *     recommendation: string
     * }
     */
    private function makeFinding(
        string $severity,
        string $type,
        ProfileSpan $span,
        float $sharePct,
        string $diagnosis,
        string $recommendation,
    ): array {
        return [
            'severity' => $severity,
            'type' => $type,
            'originScope' => $span->originScope,
            'spanId' => $span->spanId,
            'spanName' => $span->name,
            'file' => $span->file,
            'line' => $span->line,
            'selfDurationMs' => $span->selfDurationMs(),
            'totalDurationMs' => $span->durationMs(),
            'sharePercent' => $sharePct,
            'cpuTotalMs' => $span->cpuTotalMs(),
            'memoryDeltaBytes' => $span->memoryDeltaBytes,
            'diskDeltaBytes' => $span->diskTotalDeltaBytes(),
            'diagnosis' => $diagnosis,
            'recommendation' => $recommendation,
        ];
    }
}
