<?php

declare(strict_types=1);

namespace Atom\Support;

use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;
use Throwable;

/**
 * Zero-overhead observability contract attached to `Atom::$probe` (`null` on production by default).
 *
 * When `Atom::$probe === null`, the kernel executes a single branch-predicted null check (`< 1 ns`,
 * zero memory allocations, zero closure creation), preserving 100% of raw production throughput.
 * When diagnostic modules (`PerformanceProfiler`, `TrafficInspector`) are enabled, they attach
 * a probe to receive nanosecond-precision lifecycle, SQL, view, file, and response origin hooks.
 */
interface RuntimeProbeInterface
{
    /**
     * Invoked at the start of an HTTP/CLI request in `Atom::handle()`.
     */
    public function onRequestStart(Request $request, Response $response): void;

    /**
     * Opens a named execution span (`'router.resolve'`, `'middleware.execute'`, `'view.render'`, `'db.query'`, `'app.custom'`).
     *
     * @param array<string, mixed> $meta
     * @return string Span identifier passed to `endSpan()`
     */
    public function startSpan(
        string $name,
        string $category = 'core',
        ?string $file = null,
        ?int $line = null,
        array $meta = [],
    ): string;

    /**
     * Closes a previously opened execution span and records CPU, RAM, disk, and wall-clock deltas.
     *
     * @param array<string, mixed> $meta
     */
    public function endSpan(string $spanId, array $meta = [], ?Throwable $error = null): void;

    /**
     * Records route resolution metadata (matched pattern, target controller/view/closure, file & line).
     *
     * @param array<string, mixed> $routeMeta
     */
    public function onRouteMatched(
        string $method,
        string $url,
        string $matchedPattern,
        string $handlerSummary,
        ?string $file = null,
        ?int $line = null,
        array $routeMeta = [],
    ): void;

    /**
     * Records a traversed source/template file along the active request execution path.
     */
    public function recordTraversedFile(string $filePath, string $role = 'code', ?int $line = null): void;

    /**
     * Records why and from where an HTTP status code or redirect was triggered (`Response::statusCode` / `redirect`).
     */
    public function onResponseStatusChanged(
        int $statusCode,
        string $reason,
        ?string $callerFile = null,
        ?int $callerLine = null,
        ?string $callerFunction = null,
    ): void;

    /**
     * Invoked inside `Atom::handle()` `finally` block with the final rendered body or caught exception.
     */
    public function onRequestEnd(
        Request $request,
        Response $response,
        string $responseBody,
        ?Throwable $uncaughtOrHandled = null,
    ): void;
}
