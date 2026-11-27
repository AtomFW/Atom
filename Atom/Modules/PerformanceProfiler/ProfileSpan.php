<?php

declare(strict_types=1);

namespace Atom\Modules\PerformanceProfiler;

use Time\Duration;

/**
 * Immutable profiling span representing one measured unit of work inside Atom Core or Client Application.
 */
final readonly class ProfileSpan
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $spanId,
        public ?string $parentSpanId,
        public int $depth,
        public string $name,
        public string $category,
        public string $originScope,
        public ?string $file,
        public ?int $line,
        public int $startOffsetNs,
        public int $durationNs,
        public int $selfDurationNs,
        public int $cpuUserDeltaUs,
        public int $cpuSystemDeltaUs,
        public int $memoryDeltaBytes,
        public int $memoryPeakBytes,
        public int $diskReadDeltaBytes = 0,
        public int $diskWriteDeltaBytes = 0,
        public array $meta = [],
        public ?string $errorClass = null,
        public ?string $errorMessage = null,
    ) {}

    public function durationMs(): float
    {
        return \round($this->durationNs / 1_000_000, 3);
    }

    public function selfDurationMs(): float
    {
        return \round($this->selfDurationNs / 1_000_000, 3);
    }

    public function cpuTotalMs(): float
    {
        return \round(($this->cpuUserDeltaUs + $this->cpuSystemDeltaUs) / 1000, 3);
    }

    public function diskTotalDeltaBytes(): int
    {
        return $this->diskReadDeltaBytes + $this->diskWriteDeltaBytes;
    }

    public function toDuration(): Duration
    {
        return Duration::fromNanoseconds($this->durationNs);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(int $totalTraceDurationNs = 0): array
    {
        $sharePct = $totalTraceDurationNs > 0
            ? \round(($this->selfDurationNs / $totalTraceDurationNs) * 100.0, 2)
            : 0.0;

        return [
            'spanId' => $this->spanId,
            'parentSpanId' => $this->parentSpanId,
            'depth' => $this->depth,
            'name' => $this->name,
            'category' => $this->category,
            'originScope' => $this->originScope,
            'file' => $this->file,
            'line' => $this->line,
            'startOffsetMs' => \round($this->startOffsetNs / 1_000_000, 3),
            'durationMs' => $this->durationMs(),
            'selfDurationMs' => $this->selfDurationMs(),
            'sharePercent' => $sharePct,
            'cpuUserUs' => $this->cpuUserDeltaUs,
            'cpuSystemUs' => $this->cpuSystemDeltaUs,
            'cpuTotalMs' => $this->cpuTotalMs(),
            'memoryDeltaBytes' => $this->memoryDeltaBytes,
            'memoryPeakBytes' => $this->memoryPeakBytes,
            'diskReadDeltaBytes' => $this->diskReadDeltaBytes,
            'diskWriteDeltaBytes' => $this->diskWriteDeltaBytes,
            'meta' => $this->meta,
            'error' => $this->errorClass !== null ? [
                'class' => $this->errorClass,
                'message' => $this->errorMessage,
            ] : null,
        ];
    }

    /**
     * Compact positional tuple emitted in `timonix_cloud` raw telemetry mode to minimize wire bytes:
     * `[spanId, parentId, name, category, originScope, startNs, durationNs, selfNs, cpuUs, memDeltaB, diskDeltaB, file, line, hasError]`
     *
     * @return array{0: string, 1: ?string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: int, 8: int, 9: int, 10: int, 11: ?string, 12: ?int, 13: int}
     */
    public function toTimonixCloudTuple(): array
    {
        return [
            $this->spanId,
            $this->parentSpanId,
            $this->name,
            $this->category,
            $this->originScope,
            $this->startOffsetNs,
            $this->durationNs,
            $this->selfDurationNs,
            $this->cpuUserDeltaUs + $this->cpuSystemDeltaUs,
            $this->memoryDeltaBytes,
            $this->diskTotalDeltaBytes(),
            $this->file,
            $this->line,
            $this->errorClass !== null ? 1 : 0,
        ];
    }
}
