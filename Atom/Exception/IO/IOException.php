<?php

namespace Atom\Exception\IO;

class IOException extends \Exception implements \Stringable, \Throwable
{
    protected $message = 'A handler error occurred during I/O operation.';
    protected $code = 600;
}
