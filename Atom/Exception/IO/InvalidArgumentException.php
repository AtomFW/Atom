<?php

namespace Atom\Exception\IO;

class InvalidArgumentException extends \Exception implements
    \Stringable,
    \Throwable
{
    protected $message = 'A I/O invalid argument error occurred.';
    protected $code = 601;
}
