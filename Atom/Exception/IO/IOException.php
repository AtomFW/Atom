<?php

declare(strict_types=1);

namespace Atom\Exception\IO;

use Exception;

class IOException extends Exception
{
    /** @var string */
    protected $message = 'A handler error occurred during I/O operation.';

    /** @var int */
    protected $code = 600;
}
