<?php

namespace Atom\Exception\IO\Generative;

use Atom\Exception\Interface\Generative\GenerativeExceptionInterface;
use Atom\Exception\Trait\Generative\GenerativeExceptionTrait;

class AbsolutePathGenerativeException extends \Exception implements
    GenerativeExceptionInterface,
    \Stringable,
    \Throwable
{
    use GenerativeExceptionTrait;

    protected $message = 'A I/O absolute path error occurred.';
    protected $code = 620;

    public function __construct(string $format, ...$params)
    {
        $this->message = \sprintf($format, ...$params);
    }
}
