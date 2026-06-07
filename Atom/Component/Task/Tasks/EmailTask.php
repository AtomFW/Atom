<?php
declare(strict_types=1);

namespace Atom\Component\Task\Tasks;

use Atom\Component\Task\TaskInterface;

/**
 * Simple email task DTO.
 *
 * PHPStan generics (illustration): @template-implements TaskInterface
 */
final class EmailTask implements TaskInterface
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $from = null,
    ) {}
}