<?php

namespace Atom\Exception;

use Atom\Atom;

class ForbiddenException extends \Exception
{
    protected $message = 'A temporary file could not be created.';
    protected $code = 403;
}
