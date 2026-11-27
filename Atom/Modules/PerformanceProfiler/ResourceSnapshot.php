<?php

declare(strict_types=1);

namespace Atom\Modules\PerformanceProfiler;

/**
 * Immutable low-overhead hardware & PHP engine resource snapshot (monotonic clock, CPU user/sys microseconds,
 * Zend memory & real allocator peak, disk I/O counters, and volume capacity).
 */
final readonly class ResourceSnapshot
{
    public function __construct(
        public int $hrTimeNs,
        public int $wallTimestampNs,
        public int $cpuUserUs,
        public int $cpuSystemUs,
        public int $memoryUsedBytes,
        public int $memoryAllocatedBytes,
        public int $memoryPeakBytes,
        public int $diskReadBytes = 0,
        public int $diskWriteBytes = 0,
        public int $includedFilesCount = 0,
    ) {}

    /**
     * Captures an instantaneous process resource snapshot (`O(1)` syscalls).
     */
    public static function capture(int $extraDiskReadBytes = 0, int $extraDiskWriteBytes = 0): self
    {
        $hrNs = \hrtime(true);
        $wallNs = (int) (\microtime(true) * 1_000_000_000);

        $cpuUserUs = 0;
        $cpuSysUs = 0;
        if (\function_exists('getrusage')) {
            $usage = \getrusage(0);
            if (\is_array($usage)) {
                $cpuUserUs = ((int) ($usage['ru_utime.tv_sec'] ?? 0) * 1_000_000) + (int) ($usage['ru_utime.tv_usec'] ?? 0);
                $cpuSysUs = ((int) ($usage['ru_stime.tv_sec'] ?? 0) * 1_000_000) + (int) ($usage['ru_stime.tv_usec'] ?? 0);
            }
        }

        [$procRead, $procWrite] = self::readLinuxProcIo();

        return new self(
            hrTimeNs: $hrNs,
            wallTimestampNs: $wallNs,
            cpuUserUs: $cpuUserUs,
            cpuSystemUs: $cpuSysUs,
            memoryUsedBytes: \memory_get_usage(false),
            memoryAllocatedBytes: \memory_get_usage(true),
            memoryPeakBytes: \memory_get_peak_usage(true),
            diskReadBytes: $procRead + \max(0, $extraDiskReadBytes),
            diskWriteBytes: $procWrite + \max(0, $extraDiskWriteBytes),
            includedFilesCount: \count(\get_included_files()),
        );
    }

    /**
     * Samples filesystem volume capacity (`freeBytes`, `totalBytes`, `usedPercent`) for a directory once per trace.
     *
     * @return array{freeBytes: int, totalBytes: int, usedBytes: int, usedPercent: float}
     */
    public static function sampleDiskVolume(string $directoryPath): array
    {
        $target = \is_dir($directoryPath) ? $directoryPath : __DIR__;

        \set_error_handler(static fn(): bool => true);
        try {
            $free = \disk_free_space($target);
            $total = \disk_total_space($target);
        } finally {
            \restore_error_handler();
        }

        $freeInt = \is_float($free) ? (int) $free : 0;
        $totalInt = \is_float($total) ? (int) $total : 0;
        $usedInt = \max(0, $totalInt - $freeInt);
        $usedPct = $totalInt > 0 ? \round(($usedInt / $totalInt) * 100.0, 2) : 0.0;

        return [
            'freeBytes' => $freeInt,
            'totalBytes' => $totalInt,
            'usedBytes' => $usedInt,
            'usedPercent' => $usedPct,
        ];
    }

    /**
     * Samples OPcache & JIT status in PHP 8.6 without scanning scripts (`opcache_get_status(false)`).
     *
     * @return array{opcacheEnabled: bool, jitEnabled: bool, hitRatePercent: float, usedMemoryBytes: int}
     */
    public static function sampleEngineStatus(): array
    {
        if (!\function_exists('opcache_get_status')) {
            return [
                'opcacheEnabled' => false,
                'jitEnabled' => false,
                'hitRatePercent' => 0.0,
                'usedMemoryBytes' => 0,
            ];
        }

        $st = \opcache_get_status(false);
        if (!\is_array($st)) {
            return [
                'opcacheEnabled' => false,
                'jitEnabled' => false,
                'hitRatePercent' => 0.0,
                'usedMemoryBytes' => 0,
            ];
        }

        return [
            'opcacheEnabled' => !empty($st['opcache_enabled']),
            'jitEnabled' => !empty($st['jit']['enabled']),
            'hitRatePercent' => \round((float) ($st['opcache_statistics']['opcache_hit_rate'] ?? 0.0), 2),
            'usedMemoryBytes' => (int) ($st['memory_usage']['used_memory'] ?? 0),
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function readLinuxProcIo(): array
    {
        if (\PHP_OS_FAMILY !== 'Linux' || !\is_readable('/proc/self/io')) {
            return [0, 0];
        }

        $raw = \file_get_contents('/proc/self/io');
        if (!\is_string($raw) || $raw === '') {
            return [0, 0];
        }

        $readBytes = 0;
        $writeBytes = 0;
        foreach (\explode("\n", $raw) as $line) {
            if (\str_starts_with($line, 'read_bytes:')) {
                $readBytes = (int) \trim(\substr($line, 11));
            } elseif (\str_starts_with($line, 'write_bytes:')) {
                $writeBytes = (int) \trim(\substr($line, 12));
            }
        }

        return [$readBytes, $writeBytes];
    }
}
