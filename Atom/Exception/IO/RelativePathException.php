<?php

declare(strict_types=1);

namespace Atom\Exception\IO;

/**
 * RelativePathException
 *
 * @package Atom\Exception\IO
 */
class RelativePathException extends \Exception implements
    \Stringable,
    \Throwable
{
    protected $message = 'A I/O relative path error occurred.';
    protected $code = 621;
}
