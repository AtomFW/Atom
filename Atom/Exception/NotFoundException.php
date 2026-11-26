<?php

declare(strict_types=1);

namespace Atom\Exception;

use Exception;

/**
 * Exception thrown when a requested resource or route is not found (HTTP 404).
 */
final class NotFoundException extends Exception
{
    /** @var string */
    protected $message = 'Page not found';

    /** @var int */
    protected $code = 404;
}
