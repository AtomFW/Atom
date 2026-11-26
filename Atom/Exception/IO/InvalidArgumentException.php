<?php

declare(strict_types=1);

namespace Atom\Exception\IO;

use Exception;

final class InvalidArgumentException extends Exception
{
    /** @var string */
    protected $message = 'An I/O invalid argument error occurred.';

    /** @var int */
    protected $code = 601;
}
