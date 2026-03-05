<?php

declare(strict_types=1);

namespace Atom\Component\Task;

use Attribute;

/**
 * Application configuration DTO for QueueManager
 *
 * English comments inside.
 */
final readonly class QueueConfig
{
    /** @var array<string, array{dsn:string, options?:array}> */
    public array $drivers;

    /** @var array<string, array{message_class:string, expression:string, options?:array}> */
    public array $scheduler = [];

    /** @var array{max_retries:int, delay_ms:int, multiplier:float} */
    public array $retryPolicy;

    /**
     * @param array<string, array{dsn:string, options?:array}> $drivers
     * @param array<string, array{message_class:string, expression:string, options?:array}> $scheduler
     * @param array{max_retries:int, delay_ms:int, multiplier:float} $retryPolicy
     */
    public function __construct(
        array $drivers,
        array $scheduler = [],
        array $retryPolicy = ['max_retries' => 5, 'delay_ms' => 500, 'multiplier' => 2.0],
    ) {
        $this->drivers = $drivers;
        $this->scheduler = $scheduler;
        $this->retryPolicy = $retryPolicy;
    }
}