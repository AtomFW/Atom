<?php

declare(strict_types=1);

namespace Atom\Exception\IO\Generative;

use Atom\Exception\Interface\Generative\GenerativeExceptionInterface;
use Atom\Exception\Trait\Generative\GenerativeExceptionTrait;

class InvalidArgumentGenerativeException extends \Exception implements
    GenerativeExceptionInterface,
    \Stringable,
    \Throwable
{
    use GenerativeExceptionTrait;

    protected $message = 'A I/O invalid argument error occurred.';
    protected $code = 601;

    public function __construct(string $format, ...$params)
    {
        $this->message = \sprintf($format, ...$params);
    }
}
