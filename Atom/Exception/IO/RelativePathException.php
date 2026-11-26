<?php

declare(strict_types=1);

namespace Atom\Exception\IO;

use Exception;

final class RelativePathException extends Exception
{
    /** @var string */
    protected $message = 'An I/O relative path error occurred.';

    /** @var int */
    protected $code = 621;
}
