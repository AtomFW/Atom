<?php

namespace Atom\Exception;

use Atom\Atom;

class IOException extends \Exception
{
    protected $message = 'You don\'t have permission to access this page';
    protected $code = 403;
}
