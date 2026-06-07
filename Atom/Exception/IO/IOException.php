<?php

declare(strict_types=1);

namespace Atom\Exception\IO;

/**
 * IOException
 *
 * @package Atom\Exception\IO
 */
class IOException extends \Exception implements \Stringable, \Throwable
{
    protected $message = 'A handler error occurred during I/O operation.';
    protected $code = 600;
}
