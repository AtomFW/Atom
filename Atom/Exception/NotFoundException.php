<?php

declare(strict_types=1);

namespace Atom\Exception;

/**
 * Exception thrown when a requested resource is not found.
 *
 * This exception is thrown when a resource (such as a file, database record,
 * or API endpoint) cannot be located or accessed.
 */
class NotFoundException extends \Exception
{
    protected $message = 'Page not found';
    protected $code = 404;
}
