<?php

declare(strict_types=1);

namespace Atom\Support;

use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;
use Override;
use Throwable;

/**
 * Multiplexes kernel telemetry events across multiple active diagnostic probes
 * (e.g. `PerformanceProfiler` + `TrafficInspector`) without coupling them to each other.
 */
final class CompositeRuntimeProbe implements RuntimeProbeInterface
{
    /** @var list<RuntimeProbeInterface> */
    private array $probes = [];

    public function __construct(RuntimeProbeInterface ...$probes)
    {
        $this->probes = \array_values($probes);
    }

    public function addProbe(RuntimeProbeInterface $probe): self
    {
        if (!\in_array($probe, $this->probes, true) && \count($this->probes) < 16) {
            $this->probes[] = $probe;
        }

        return $this;
    }

    /**
     * @return list<RuntimeProbeInterface>
     */
    public function probes(): array
    {
        return $this->probes;
    }

    /**
     * Attaches `$probe` onto an existing `?RuntimeProbeInterface` slot cleanly.
     */
    public static function attach(?RuntimeProbeInterface $current, RuntimeProbeInterface $next): RuntimeProbeInterface
    {
        if ($current === null) {
            return $next;
        }

        if ($current instanceof self) {
            $current->addProbe($next);
            return $current;
        }

        if ($current === $next) {
            return $current;
        }

        return new self($current, $next);
    }

    #[Override]
    public function onRequestStart(Request $request, Response $response): void
    {
        foreach ($this->probes as $probe) {
            $probe->onRequestStart($request, $response);
        }
    }

    #[Override]
    public function startSpan(
        string $name,
        string $category = 'core',
        ?string $file = null,
        ?int $line = null,
        array $meta = [],
    ): string {
        $tokens = [];
        foreach ($this->probes as $idx => $probe) {
            $tokens[] = $idx . ':' . $probe->startSpan($name, $category, $file, $line, $meta);
        }

        return \implode('|', $tokens);
    }

    #[Override]
    public function endSpan(string $spanId, array $meta = [], ?Throwable $error = null): void
    {
        if ($spanId === '') {
            return;
        }

        foreach (\explode('|', $spanId) as $part) {
            $sep = \strpos($part, ':');
            if ($sep === false) {
                continue;
            }
            $idx = (int) \substr($part, 0, $sep);
            $innerId = \substr($part, $sep + 1);
            if (isset($this->probes[$idx])) {
                $this->probes[$idx]->endSpan($innerId, $meta, $error);
            }
        }
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
    ): void {
        foreach ($this->probes as $probe) {
            $probe->onRouteMatched($method, $url, $matchedPattern, $handlerSummary, $file, $line, $routeMeta);
        }
    }

    #[Override]
    public function recordTraversedFile(string $filePath, string $role = 'code', ?int $line = null): void
    {
        foreach ($this->probes as $probe) {
            $probe->recordTraversedFile($filePath, $role, $line);
        }
    }

    #[Override]
    public function onResponseStatusChanged(
        int $statusCode,
        string $reason,
        ?string $callerFile = null,
        ?int $callerLine = null,
        ?string $callerFunction = null,
    ): void {
        foreach ($this->probes as $probe) {
            $probe->onResponseStatusChanged($statusCode, $reason, $callerFile, $callerLine, $callerFunction);
        }
    }

    #[Override]
    public function onRequestEnd(
        Request $request,
        Response $response,
        string $responseBody,
        ?Throwable $uncaughtOrHandled = null,
    ): void {
        foreach ($this->probes as $probe) {
            $probe->onRequestEnd($request, $response, $responseBody, $uncaughtOrHandled);
        }
    }
}
