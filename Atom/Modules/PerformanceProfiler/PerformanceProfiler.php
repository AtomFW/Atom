<?php

declare(strict_types=1);

namespace Atom\Modules\PerformanceProfiler;

use Atom\Atom;
use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;
use Atom\Modules\Contract\AtomModuleInterface;
use Atom\Support\RuntimeProbeInterface;
use Atom\Terminal\Terminal;
use Override;
use Random\Engine\Secure;
use Random\Randomizer;
use Throwable;

/**
 * Official `PerformanceProfiler` module (`Atom\Modules\PerformanceProfiler`) providing:
 * - Zero-overhead idle mode on production (`Atom::$probe === null` when `PROFILER_ON=false`)
 * - Nanosecond profiling of both Atom Core (`core`) and Client Applications (`client_app`)
 * - CPU (user/sys µs), RAM (used/delta/peak), Disk I/O & volume, and SQL N+1 bottleneck diagnosis
 * - Interactive visual Waterfall & Hotspot HTML dashboard (`/_atom/profiler`)
 * - Structured AI Agent diagnostic export (`exportForAgent()` / `/_atom/profiler/agent`)
 * - Raw metrics-only mode (`timonix_cloud` / `exportForTimonixCloud()` / `/_atom/profiler/cloud`) for Timonix Cloud
 */
final class PerformanceProfiler implements AtomModuleInterface, RuntimeProbeInterface
{
    public const string VERSION = '1.0.0';

    /** @var array<string, mixed> */
    private array $config;

    private bool $enabled;
    private string $mode;
    private string $dashboardPath;
    private bool $exposeHttpDashboard;
    private string $timonixProjectKey;
    private bool $writeTimonixNdjson;
    private string $timonixNdjsonPath;
    private int $maxSpansPerTrace;
    private int $maxStoredTraces;

    private BottleneckAnalyzer $analyzer;
    private PerformanceReportRenderer $renderer;
    private Randomizer $randomizer;

    /**
     * Optional callback invoked automatically in `timonix_cloud` mode with raw telemetry packets.
     *
     * @var (callable(array<string, mixed>): void)|null
     */
    private mixed $timonixCloudExporter = null;

    /** @var array<string, ProfileTrace> Bounded FIFO buffer of completed traces */
    private array $completedTraces = [];

    private ?ResourceSnapshot $activeStartSnapshot = null;
    private int $activeBootDurationNs = 0;
    private string $activeTraceId = '';
    private string $activeStartedUtc = '';
    private int $trackedDiskReadBytes = 0;
    private int $trackedDiskWriteBytes = 0;
    private bool $bypassCurrentRequest = false;
    private int $spanCounter = 0;

    /** @var list<string> */
    private array $openSpanStack = [];

    /**
     * @var array<string, array{
     *     parentId: ?string,
     *     depth: int,
     *     name: string,
     *     category: string,
     *     originScope: string,
     *     file: ?string,
     *     line: ?int,
     *     startSnapshot: ResourceSnapshot,
     *     childrenDurationNs: int,
     *     meta: array<string, mixed>
     * }>
     */
    private array $openSpans = [];

    /** @var list<ProfileSpan> */
    private array $closedSpans = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->config = $options;
        $this->randomizer = new Randomizer(new Secure());
        $this->renderer = new PerformanceReportRenderer();
        $this->applyConfiguration($options);
    }

    #[Override]
    public function getName(): string
    {
        return 'PerformanceProfiler';
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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;
        return $this;
    }

    /**
     * Registers a live exporter receiving raw `timonix.cloud.telemetry.v1` packets at the end of each trace.
     *
     * @param callable(array<string, mixed>): void $exporter
     */
    public function setTimonixCloudExporter(callable $exporter): self
    {
        $this->timonixCloudExporter = $exporter;
        return $this;
    }

    /**
     * Records explicit disk read/write byte counts from application or framework I/O operations.
     */
    public function recordDiskIo(int $readBytes = 0, int $writeBytes = 0): void
    {
        $this->trackedDiskReadBytes += \max(0, $readBytes);
        $this->trackedDiskWriteBytes += \max(0, $writeBytes);
    }

    /**
     * Client-application & core profiling helper: wraps any callable in a measured span
     * capturing CPU, RAM, Disk I/O, wall-time, self-time, and exact caller `file:line`.
     *
     * When the profiler is disabled on production, executes `$callback()` directly with zero overhead!
     *
     * @template T
     * @param callable(): T $callback
     * @param array<string, mixed> $meta
     * @return T
     */
    public function measure(
        string $name,
        callable $callback,
        string $category = 'client_app',
        array $meta = [],
    ): mixed {
        if (!$this->enabled) {
            return $callback();
        }

        $autoStartTrace = $this->activeStartSnapshot === null;
        if ($autoStartTrace) {
            $this->beginStandaloneTrace($name);
        }

        $caller = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        $file = isset($caller['file']) ? (string) $caller['file'] : null;
        $line = isset($caller['line']) ? (int) $caller['line'] : null;

        $spanId = $this->startSpan($name, $category, $file, $line, $meta);
        $err = null;
        try {
            return $callback();
        } catch (Throwable $e) {
            $err = $e;
            throw $e;
        } finally {
            $this->endSpan($spanId, [], $err);
            if ($autoStartTrace) {
                $this->finishStandaloneTrace($name, $err === null ? 200 : 500);
            }
        }
    }

    #[Override]
    public function boot(Atom $app): void
    {
        $profCfg = $app->config->get('profiler');
        if (\is_array($profCfg)) {
            $this->config = $profCfg;
            $this->applyConfiguration($profCfg);
        }

        // Strict production guarantee: when disabled, do NOT touch $app->probe!
        if (!$this->enabled) {
            return;
        }

        $this->activeBootDurationNs = $app->bootDurationNs;
        $app->attachProbe($this);

        if ($this->exposeHttpDashboard) {
            $base = \rtrim($this->dashboardPath, '/');

            $app->router->get($base, function (Request $req, Response $res): string {
                $res->setHeader('Content-Type', 'text/html; charset=utf-8');
                $traceId = (string) ($req->getQueryParams()['trace'] ?? '');
                return $this->renderVisualReport($traceId !== '' ? $traceId : null);
            });

            $app->router->get($base . '/agent', function (Request $req, Response $res): string {
                $res->setHeader('Content-Type', 'application/json; charset=utf-8');
                $traceId = (string) ($req->getQueryParams()['trace'] ?? '');
                return \json_encode(
                    $this->exportForAgent($traceId !== '' ? $traceId : null),
                    \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
                ) ?: '{}';
            });

            $app->router->get($base . '/cloud', function (Request $req, Response $res): string {
                $res->setHeader('Content-Type', 'application/json; charset=utf-8');
                $traceId = (string) ($req->getQueryParams()['trace'] ?? '');
                return \json_encode(
                    $this->exportForTimonixCloud($traceId !== '' ? $traceId : null),
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
                ) ?: '{}';
            });
        }
    }

    #[Override]
    public function onRequestStart(Request $request, Response $response): void
    {
        if (!$this->enabled) {
            return;
        }

        $url = $request->getUrl();
        if (\str_starts_with($url, \rtrim($this->dashboardPath, '/'))) {
            $this->bypassCurrentRequest = true;
            return;
        }

        $this->bypassCurrentRequest = false;
        $this->resetActiveState();
        $this->activeTraceId = \bin2hex($this->randomizer->getBytes(8));
        $this->activeStartedUtc = \gmdate('Y-m-d\TH:i:s\Z');
        $this->activeStartSnapshot = ResourceSnapshot::capture($this->trackedDiskReadBytes, $this->trackedDiskWriteBytes);
    }

    #[Override]
    public function startSpan(
        string $name,
        string $category = 'core',
        ?string $file = null,
        ?int $line = null,
        array $meta = [],
    ): string {
        if (!$this->enabled || $this->bypassCurrentRequest || $this->activeStartSnapshot === null) {
            return '';
        }

        if ($this->spanCounter >= $this->maxSpansPerTrace) {
            return '';
        }

        $spanId = 'sp_' . (++$this->spanCounter);
        $parentId = $this->openSpanStack !== [] ? \array_last($this->openSpanStack) : null;
        $depth = \count($this->openSpanStack);
        $originScope = $this->classifyOriginScope($category, $file, $name);

        $this->openSpans[$spanId] = [
            'parentId' => $parentId,
            'depth' => $depth,
            'name' => $name,
            'category' => $category,
            'originScope' => $originScope,
            'file' => $file,
            'line' => $line,
            'startSnapshot' => ResourceSnapshot::capture($this->trackedDiskReadBytes, $this->trackedDiskWriteBytes),
            'childrenDurationNs' => 0,
            'meta' => $meta,
        ];
        $this->openSpanStack[] = $spanId;

        return $spanId;
    }

    #[Override]
    public function endSpan(string $spanId, array $meta = [], ?Throwable $error = null): void
    {
        if ($spanId === '' || !isset($this->openSpans[$spanId]) || $this->activeStartSnapshot === null) {
            return;
        }

        $open = $this->openSpans[$spanId];
        unset($this->openSpans[$spanId]);

        $stackIdx = \array_search($spanId, $this->openSpanStack, true);
        if ($stackIdx !== false) {
            \array_splice($this->openSpanStack, (int) $stackIdx, 1);
        }

        $endSnap = ResourceSnapshot::capture($this->trackedDiskReadBytes, $this->trackedDiskWriteBytes);
        $startSnap = $open['startSnapshot'];

        $durationNs = \max(0, $endSnap->hrTimeNs - $startSnap->hrTimeNs);
        $selfDurationNs = \max(0, $durationNs - $open['childrenDurationNs']);

        $parentId = $open['parentId'];
        if ($parentId !== null && isset($this->openSpans[$parentId])) {
            $this->openSpans[$parentId]['childrenDurationNs'] += $durationNs;
        }

        $this->closedSpans[] = new ProfileSpan(
            spanId: $spanId,
            parentSpanId: $parentId,
            depth: $open['depth'],
            name: $open['name'],
            category: $open['category'],
            originScope: $open['originScope'],
            file: $open['file'],
            line: $open['line'],
            startOffsetNs: \max(0, $startSnap->hrTimeNs - $this->activeStartSnapshot->hrTimeNs),
            durationNs: $durationNs,
            selfDurationNs: $selfDurationNs,
            cpuUserDeltaUs: \max(0, $endSnap->cpuUserUs - $startSnap->cpuUserUs),
            cpuSystemDeltaUs: \max(0, $endSnap->cpuSystemUs - $startSnap->cpuSystemUs),
            memoryDeltaBytes: $endSnap->memoryUsedBytes - $startSnap->memoryUsedBytes,
            memoryPeakBytes: $endSnap->memoryPeakBytes,
            diskReadDeltaBytes: \max(0, $endSnap->diskReadBytes - $startSnap->diskReadBytes),
            diskWriteDeltaBytes: \max(0, $endSnap->diskWriteBytes - $startSnap->diskWriteBytes),
            meta: $meta !== [] ? [...$open['meta'], ...$meta] : $open['meta'],
            errorClass: $error !== null ? $error::class : null,
            errorMessage: $error?->getMessage(),
        );
    }

    #[Override]
    public function onRouteMatched(
        string $method,
        string $url,
        string $matchedPattern,
        string $handlerSummary,
        ?string $file = null,
        ?int $line = null,
        array $routeMeta = [],
    ): void {}

    #[Override]
    public function recordTraversedFile(string $filePath, string $role = 'code', ?int $line = null): void {}

    #[Override]
    public function onResponseStatusChanged(
        int $statusCode,
        string $reason,
        ?string $callerFile = null,
        ?int $callerLine = null,
        ?string $callerFunction = null,
    ): void {}

    #[Override]
    public function onRequestEnd(
        Request $request,
        Response $response,
        string $responseBody,
        ?Throwable $uncaughtOrHandled = null,
    ): void {
        if (!$this->enabled || $this->bypassCurrentRequest || $this->activeStartSnapshot === null) {
            $this->bypassCurrentRequest = false;
            return;
        }

        $this->finalizeTrace($request->getMethod(), $request->getUrl(), $response->getStatusCode());
    }

    public function lastTrace(): ?ProfileTrace
    {
        if ($this->completedTraces === []) {
            return null;
        }

        return \array_last($this->completedTraces);
    }

    public function getTrace(string $traceId): ?ProfileTrace
    {
        return $this->completedTraces[$traceId] ?? null;
    }

    /**
     * @return list<ProfileTrace>
     */
    public function getTraces(): array
    {
        return \array_values($this->completedTraces);
    }

    /**
     * Exports structured bottleneck & resource diagnostics for an AI Agent.
     *
     * @return array<string, mixed>
     */
    public function exportForAgent(?string $traceId = null): array
    {
        $trace = $traceId !== null ? $this->getTrace($traceId) : $this->lastTrace();
        if ($trace === null) {
            return ['reportType' => 'atom.profiler.agent_diagnostics.v1', 'empty' => true];
        }

        return $trace->toAgentReport();
    }

    /**
     * Exports pure, compact machine telemetry (`timonix.cloud.telemetry.v1`) for Timonix Cloud.
     *
     * @return array<string, mixed>
     */
    public function exportForTimonixCloud(?string $traceId = null, string $nodeId = 'local'): array
    {
        $trace = $traceId !== null ? $this->getTrace($traceId) : $this->lastTrace();
        if ($trace === null) {
            return ['schema' => 'timonix.cloud.telemetry.v1', 'empty' => true];
        }

        return $trace->toTimonixCloudTelemetry($this->timonixProjectKey, $nodeId);
    }

    /**
     * Renders the visual interactive HTML Bottleneck & Flow dashboard.
     */
    public function renderVisualReport(?string $traceId = null): string
    {
        $trace = $traceId !== null ? $this->getTrace($traceId) : $this->lastTrace();
        if ($trace === null) {
            return '<!DOCTYPE html><html><body style="background:#090d16;color:#e2e8f0;font-family:sans-serif;padding:32px"><h2>Atom Performance Profiler</h2><p>No profiled requests recorded yet.</p></body></html>';
        }

        return $this->renderer->renderHtml($trace, \array_reverse($this->getTraces()), $this->timonixProjectKey);
    }

    #[Override]
    public function registerCommands(Terminal $terminal): Terminal
    {
        return $terminal
            ->withCommand(
                'profiler:status',
                function (array $args, Terminal $term): int {
                    $term->table(
                        ['Property', 'Value'],
                        [
                            ['Module', $this->getName() . ' v' . $this->getVersion()],
                            ['Enabled (PROFILER_ON)', $this->enabled ? 'YES' : 'NO (Zero Production Overhead)'],
                            ['Active Mode', $this->mode],
                            ['Recorded Traces', (string) \count($this->completedTraces) . ' / ' . $this->maxStoredTraces],
                            ['Dashboard Endpoint', $this->dashboardPath . ' (+ /agent, /cloud)'],
                            ['SQL Migrations', 'pp0001 (Atom/Modules/PerformanceProfiler/migration)'],
                        ],
                    );
                    return 0;
                },
                'Display PerformanceProfiler bottleneck analyzer and Timonix Cloud telemetry status',
            );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyConfiguration(array $options): void
    {
        $this->enabled = (bool) ($options['on'] ?? false);
        $this->mode = (string) ($options['default'] ?? $options['mode'] ?? 'visual');

        $visualCfg = \is_array($options['choices']['visual'] ?? null) ? $options['choices']['visual'] : [];
        $this->exposeHttpDashboard = (bool) ($visualCfg['exposeHttpDashboard'] ?? true);
        $this->dashboardPath = (string) ($visualCfg['dashboardPath'] ?? '/_atom/profiler');

        $cloudCfg = \is_array($options['choices']['timonix_cloud'] ?? null) ? $options['choices']['timonix_cloud'] : [];
        $this->timonixProjectKey = (string) ($cloudCfg['projectKey'] ?? '');
        $this->writeTimonixNdjson = (bool) ($cloudCfg['writeNdjsonLog'] ?? false);
        $this->timonixNdjsonPath = (string) ($cloudCfg['ndjsonPath'] ?? '');

        $limits = \is_array($options['limits'] ?? null) ? $options['limits'] : [];
        $this->maxSpansPerTrace = \clamp((int) ($limits['maxSpansPerTrace'] ?? 512), 16, 10_000);
        $this->maxStoredTraces = \clamp((int) ($limits['maxStoredTraces'] ?? 32), 1, 512);

        $thresholds = \is_array($options['thresholds'] ?? null) ? $options['thresholds'] : [];
        $this->analyzer = new BottleneckAnalyzer($thresholds);
    }

    private function beginStandaloneTrace(string $label): void
    {
        $this->resetActiveState();
        $this->activeTraceId = \bin2hex($this->randomizer->getBytes(8));
        $this->activeStartedUtc = \gmdate('Y-m-d\TH:i:s\Z');
        $this->activeStartSnapshot = ResourceSnapshot::capture($this->trackedDiskReadBytes, $this->trackedDiskWriteBytes);
    }

    private function finishStandaloneTrace(string $label, int $statusCode): void
    {
        $this->finalizeTrace('CLI', 'task://' . $label, $statusCode);
    }

    private function finalizeTrace(string $method, string $url, int $statusCode): void
    {
        if ($this->activeStartSnapshot === null) {
            return;
        }

        // Close any still-open spans deterministically
        while ($this->openSpanStack !== []) {
            $openId = \array_last($this->openSpanStack);
            $this->endSpan($openId);
        }

        $startSnap = $this->activeStartSnapshot;
        $endSnap = ResourceSnapshot::capture($this->trackedDiskReadBytes, $this->trackedDiskWriteBytes);
        $totalDurationNs = \max(1, $endSnap->hrTimeNs - $startSnap->hrTimeNs);

        // Sort spans in chronological start order for clean waterfall visualization
        $orderedSpans = $this->closedSpans;
        \usort($orderedSpans, static fn(ProfileSpan $a, ProfileSpan $b): int => $a->startOffsetNs <=> $b->startOffsetNs);

        $coreSelfNs = 0;
        $appSelfNs = 0;
        $dbNs = 0;
        $viewNs = 0;

        foreach ($orderedSpans as $s) {
            if ($s->originScope === 'client_app') {
                $appSelfNs += $s->selfDurationNs;
            } else {
                $coreSelfNs += $s->selfDurationNs;
            }

            if ($s->category === 'database') {
                $dbNs += $s->durationNs;
            } elseif ($s->category === 'view') {
                $viewNs += $s->durationNs;
            }
        }

        $bottlenecks = $this->analyzer->analyze($orderedSpans, $totalDurationNs);
        $rootDir = Atom::$ROOT_DIR !== '' ? Atom::$ROOT_DIR : __DIR__;

        $trace = new ProfileTrace(
            traceId: $this->activeTraceId,
            method: \strtoupper($method),
            url: $url,
            statusCode: $statusCode,
            startedAtUtc: $this->activeStartedUtc,
            timestampNs: $endSnap->wallTimestampNs,
            totalDurationNs: $totalDurationNs,
            bootDurationNs: $this->activeBootDurationNs,
            coreSelfDurationNs: $coreSelfNs,
            clientAppSelfDurationNs: $appSelfNs,
            databaseDurationNs: $dbNs,
            viewDurationNs: $viewNs,
            cpuUserUs: \max(0, $endSnap->cpuUserUs - $startSnap->cpuUserUs),
            cpuSystemUs: \max(0, $endSnap->cpuSystemUs - $startSnap->cpuSystemUs),
            memoryStartBytes: $startSnap->memoryUsedBytes,
            memoryEndBytes: $endSnap->memoryUsedBytes,
            memoryDeltaBytes: $endSnap->memoryUsedBytes - $startSnap->memoryUsedBytes,
            memoryPeakBytes: $endSnap->memoryPeakBytes,
            diskReadDeltaBytes: \max(0, $endSnap->diskReadBytes - $startSnap->diskReadBytes),
            diskWriteDeltaBytes: \max(0, $endSnap->diskWriteBytes - $startSnap->diskWriteBytes),
            includedFilesDelta: \max(0, $endSnap->includedFilesCount - $startSnap->includedFilesCount),
            diskVolume: ResourceSnapshot::sampleDiskVolume($rootDir),
            engineStatus: ResourceSnapshot::sampleEngineStatus(),
            spans: $orderedSpans,
            bottlenecks: $bottlenecks,
        );

        if (\count($this->completedTraces) >= $this->maxStoredTraces) {
            $oldest = \array_key_first($this->completedTraces);
            if ($oldest !== null) {
                unset($this->completedTraces[$oldest]);
            }
        }
        $this->completedTraces[$trace->traceId] = $trace;

        if ($this->mode === 'timonix_cloud' || $this->timonixCloudExporter !== null || $this->writeTimonixNdjson) {
            $cloudPacket = $trace->toTimonixCloudTelemetry($this->timonixProjectKey);
            if ($this->timonixCloudExporter !== null) {
                ($this->timonixCloudExporter)($cloudPacket);
            }
            if ($this->writeTimonixNdjson && $this->timonixNdjsonPath !== '') {
                $this->appendNdjsonTelemetry($cloudPacket);
            }
        }

        $this->activeStartSnapshot = null;
    }

    /**
     * @param array<string, mixed> $packet
     */
    private function appendNdjsonTelemetry(array $packet): void
    {
        $dir = \dirname($this->timonixNdjsonPath);
        if (!\is_dir($dir) && !\mkdir($dir, 0750, true) && !\is_dir($dir)) {
            return;
        }

        $line = \json_encode($packet, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (\is_string($line)) {
            \file_put_contents($this->timonixNdjsonPath, $line . "\n", \FILE_APPEND | \LOCK_EX);
        }
    }

    private function classifyOriginScope(string $category, ?string $file, string $name): string
    {
        if ($category === 'client_app') {
            return 'client_app';
        }

        if ($file !== null) {
            $normalized = \str_replace('\\', '/', $file);
            if (\str_contains($normalized, '/app/') || \str_contains($normalized, '/resources/Views/')) {
                return 'client_app';
            }
            if (!\str_contains($normalized, '/tests/') && \str_contains($normalized, '/Atom/')) {
                return 'core';
            }
        }

        if (\str_starts_with($name, 'app.') || \str_contains($name, 'App\\')) {
            return 'client_app';
        }

        return 'core';
    }

    private function resetActiveState(): void
    {
        $this->spanCounter = 0;
        $this->openSpanStack = [];
        $this->openSpans = [];
        $this->closedSpans = [];
    }
}
