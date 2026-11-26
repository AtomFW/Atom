<?php

declare(strict_types=1);

namespace Atom\Exception;

use Exception;

/**
 * Exception thrown when access to a protected resource is forbidden (HTTP 403).
 */
final class ForbiddenException extends Exception
{
    /** @var string */
    protected $message = 'You don\'t have permission to access this page';

    /** @var int */
    protected $code = 403;
}
