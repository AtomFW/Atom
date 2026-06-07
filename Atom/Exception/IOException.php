<?php

declare(strict_types=1);

namespace Atom\Exception;

use Atom\Atom;

// Exception class for I/O related errors
class IOException extends \Exception
{
    protected $message = 'You don\'t have permission to access this page';
    protected $code = 403;
}
