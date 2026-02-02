<?php

namespace Atom\Exception\IO;

class RelativePathException extends \Exception implements
    \Stringable,
    \Throwable
{
    protected $message = 'A I/O relative path error occurred.';
    protected $code = 621;
}
