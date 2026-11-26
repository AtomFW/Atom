<?php

declare(strict_types=1);

namespace Atom\Component\Task\Tasks;

use Atom\Component\Task\TaskInterface;

/**
 * Immutable email dispatch task DTO.
 */
final readonly class EmailTask implements TaskInterface
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
        public ?string $from = null,
    ) {}
}
