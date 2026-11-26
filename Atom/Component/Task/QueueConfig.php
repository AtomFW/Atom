<?php

declare(strict_types=1);

namespace Atom\Component\Task;

use Time\Duration;

/**
 * Immutable configuration DTO for QueueManager.
 */
final readonly class QueueConfig
{
    /** @var array<string, array{dsn: string, options?: array<string, mixed>}> */
    public array $drivers;

    /** @var array<string, array{message_class: string, expression: string, options?: array<string, mixed>}> */
    public array $scheduler;

    /** @var array{max_retries: int, delay_ms: int, multiplier: float} */
    public array $retryPolicy;

    /**
     * @param array<string, array{dsn: string, options?: array<string, mixed>}> $drivers
     * @param array<string, array{message_class: string, expression: string, options?: array<string, mixed>}> $scheduler
     * @param array{max_retries?: int, delay_ms?: int, multiplier?: float} $retryPolicy
     */
    public function __construct(
        array $drivers = [],
        array $scheduler = [],
        array $retryPolicy = ['max_retries' => 5, 'delay_ms' => 500, 'multiplier' => 2.0],
    ) {
        $this->drivers = $drivers;
        $this->scheduler = $scheduler;
        $this->retryPolicy = [
            'max_retries' => \clamp((int) ($retryPolicy['max_retries'] ?? 5), 0, 100),
            'delay_ms' => \clamp((int) ($retryPolicy['delay_ms'] ?? 500), 0, 3_600_000),
            'multiplier' => \max(1.0, (float) ($retryPolicy['multiplier'] ?? 2.0)),
        ];
    }

    public function baseRetryDelay(): Duration
    {
        return Duration::fromMilliseconds($this->retryPolicy['delay_ms']);
    }
}
