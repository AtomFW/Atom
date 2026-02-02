<?php

namespace Atom\Exception\IO;

class AbsolutePathException extends \Exception implements
    \Stringable,
    \Throwable
{
    protected $message = 'A I/O absolute path error occurred.';
    protected $code = 620;
}
