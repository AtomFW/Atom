<?php

declare(strict_types=1);

/**
 * Performance & Bottleneck Profiler configuration template (`Atom\Modules\PerformanceProfiler`)
 */

return [
    /**
     * Master switch enabling runtime bottleneck profiling.
     *
     * Disabled by default (`false`) on production so `Atom::$probe` stays `null` and incurs
     * zero CPU/RAM overhead. Enable in development, staging, canary profiling, or Timonix Cloud telemetry mode.
     *
     * @var bool
     */
    'on' => (bool) env('PROFILER_ON', false),

    /**
     * Default telemetry output & export driver.
     *
     * Available modes/drivers:
     * - 'visual'        : Full interactive HTML dashboard, bottleneck analysis & agent JSON
     * - 'timonix_cloud' : Lightweight raw metrics-only telemetry stream (zero HTML overhead) for Timonix Cloud
     * - 'agent'         : Structured diagnostic report optimized for AI coding/performance agents
     *
     * @var string
     */
    'default' => (string) env('PROFILER_MODE', 'visual'),

    /**
     * Mode-specific configurations.
     *
     * @var array<string, array<string, mixed>>
     */
    'choices' => [
        /**
         * Interactive visual HTML dashboard + full diagnostics mode.
         *
         * @var array<string, mixed>
         */
        'visual' => [
            'driver' => 'visual',
            'injectFloatingBadge' => (bool) env('PROFILER_INJECT_BADGE', false),
            'exposeHttpDashboard' => (bool) env('PROFILER_HTTP_DASHBOARD', true),
            'dashboardPath' => (string) env('PROFILER_DASHBOARD_PATH', '/_atom/profiler'),
        ],

        /**
         * Raw metrics-only mode designed for Timonix Cloud automated ingestion and visualization.
         *
         * Skips HTML generation and heavy string formatting, emitting compact nanosecond span tuples
         * and host resource vectors (`schema: timonix.cloud.telemetry.v1`).
         *
         * @var array<string, mixed>
         */
        'timonix_cloud' => [
            'driver' => 'timonix_cloud',
            'schemaVersion' => 'timonix.cloud.telemetry.v1',
            'projectKey' => (string) env('TIMONIX_CLOUD_PROJECT_KEY', ''),
            'ingestEndpoint' => (string) env('TIMONIX_CLOUD_INGEST_URL', ''),
            'writeNdjsonLog' => (bool) env('TIMONIX_CLOUD_WRITE_NDJSON', false),
            'ndjsonPath' => (string) env('TIMONIX_CLOUD_NDJSON_PATH', __DIR__ . '/../runtime/log/timonix_telemetry.ndjson'),
        ],

        /**
         * AI agent structured diagnostic mode.
         *
         * @var array<string, mixed>
         */
        'agent' => [
            'driver' => 'agent',
            'includeOptimizationHints' => true,
        ],
    ],

    /**
     * Bottleneck detection thresholds (used to flag slow stages, RAM spikes, CPU saturation, and I/O stalls).
     *
     * @var array<string, mixed>
     */
    'thresholds' => [
        /**
         * Span self-time in milliseconds flagged as a warning bottleneck.
         *
         * @var float
         */
        'warningSpanMs' => (float) env('PROFILER_WARN_SPAN_MS', 15.0),

        /**
         * Span self-time in milliseconds flagged as a critical bottleneck.
         *
         * @var float
         */
        'criticalSpanMs' => (float) env('PROFILER_CRITICAL_SPAN_MS', 50.0),

        /**
         * Minimum percentage share (`0..100`) of total request duration (when request >= 5 ms) to highlight a hotspot.
         *
         * @var float
         */
        'hotspotSharePercent' => (float) env('PROFILER_HOTSPOT_SHARE_PCT', 30.0),

        /**
         * Single-span memory allocation delta in bytes flagged as a memory spike (defaults to 2 MiB).
         *
         * @var int
         */
        'memorySpikeBytes' => (int) env('PROFILER_MEMORY_SPIKE_BYTES', 2097152),

        /**
         * SQL query duration in milliseconds flagged as a slow query.
         *
         * @var float
         */
        'slowQueryMs' => (float) env('PROFILER_SLOW_QUERY_MS', 10.0),
    ],

    /**
     * Sampling & bounded memory retention limits (zero memory leaks under Swoole workers).
     *
     * @var array<string, mixed>
     */
    'limits' => [
        /**
         * Sampling rate (`0.0`..`1.0`) enabling low-overhead canary profiling (`1.0` = 100% of requests when enabled).
         *
         * @var float
         */
        'sampleRate' => (float) env('PROFILER_SAMPLE_RATE', 1.0),

        /**
         * Maximum spans recorded within a single request trace.
         *
         * @var int
         */
        'maxSpansPerTrace' => (int) env('PROFILER_MAX_SPANS', 512),

        /**
         * Maximum recent request traces kept in the bounded ring buffer.
         *
         * @var int
         */
        'maxStoredTraces' => (int) env('PROFILER_MAX_TRACES', 32),
    ],
];
