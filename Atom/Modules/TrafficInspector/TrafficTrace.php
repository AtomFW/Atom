<?php

declare(strict_types=1);

namespace Atom\Modules\TrafficInspector;

/**
 * Immutable end-to-end forensic trace of a single HTTP request & response cycle:
 * - Inbound origin, network proxy chain, client type, inferred purpose ("po co"), and sanitized payloads
 * - Exact route resolution, execution stages, and traversed PHP/template files ("jaka trasa i pliki")
 * - Outbound status, causal explanation ("dlaczego"), code origin ("skąd"), headers, and body preview
 */
final readonly class TrafficTrace
{
    /**
     * @param array<string, mixed> $inbound
     * @param array<string, mixed> $routeAndFiles
     * @param array<string, mixed> $outbound
     */
    public function __construct(
        public string $traceId,
        public string $timestampUtc,
        public float $durationMs,
        public array $inbound,
        public array $routeAndFiles,
        public array $outbound,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'traceId' => $this->traceId,
            'timestampUtc' => $this->timestampUtc,
            'durationMs' => $this->durationMs,
            'inbound' => $this->inbound,
            'routeAndFiles' => $this->routeAndFiles,
            'outbound' => $this->outbound,
        ];
    }
}
