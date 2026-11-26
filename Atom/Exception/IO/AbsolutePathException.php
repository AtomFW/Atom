<?php

declare(strict_types=1);

namespace Atom\Exception\IO;

use Exception;

final class AbsolutePathException extends Exception
{
    /** @var string */
    protected $message = 'An I/O absolute path error occurred.';

    /** @var int */
    protected $code = 620;
}
