<?php

declare(strict_types=1);

namespace Atom\Exception;

use Atom\Atom;

/**
 * ForbiddenException
 *
 * @package Atom\Exception
 */
class ForbiddenException extends \Exception
{
    protected $message = 'A temporary file could not be created.';
    protected $code = 403;
}
