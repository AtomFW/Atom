<?php

declare(strict_types=1);

namespace Atom\Async\Exception;

use Throwable;

/**
 * Thrown by `Async::any()` when every candidate task fails.
 */
final class AggregateAsyncException extends AsyncException
{
    /**
     * @param array<int|string, Throwable> $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<int|string, Throwable>
     */
    public function getExceptions(): array
    {
        return $this->errors;
    }
}
