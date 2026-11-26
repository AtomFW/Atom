<?php

declare(strict_types=1);

namespace Atom\Cron;

use Time\Duration;

/**
 * Stateless task scheduler descriptor using PHP 8.6 `\Time\Duration` intervals.
 */
final readonly class Cron
{
    public const string VERSION = '1.0.0';

    /**
     * @param array<string, array{interval: Duration, callback: callable}> $jobs
     */
    public function __construct(
        private array $jobs = [],
    ) {}

    public function withJob(string $name, Duration $interval, callable $callback): self
    {
        $updated = $this->jobs;
        $updated[$name] = [
            'interval' => $interval,
            'callback' => $callback,
        ];

        return new self($updated);
    }

    /**
     * Executes jobs due at `$currentEpochSeconds` given `$lastRunMap` and returns updated timestamps.
     *
     * @param array<string, int> $lastRunMap
     * @return array<string, int>
     */
    public function runDue(int $currentEpochSeconds, array $lastRunMap = []): array
    {
        foreach ($this->jobs as $name => $job) {
            $lastRun = $lastRunMap[$name] ?? 0;
            if (($currentEpochSeconds - $lastRun) >= $job['interval']->seconds) {
                ($job['callback'])();
                $lastRunMap[$name] = $currentEpochSeconds;
            }
        }

        return $lastRunMap;
    }
}
