<?php

declare(strict_types=1);

namespace Atom\Cache;

/**
 * SysvIpcException
 */
final class SysvIpcException extends \RuntimeException
{
    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
