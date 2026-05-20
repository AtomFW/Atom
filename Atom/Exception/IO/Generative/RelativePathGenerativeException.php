<?php

declare(strict_types=1);

namespace Atom\Exception\IO\Generative;

use Atom\Exception\Interface\Generative\GenerativeExceptionInterface;
use Atom\Exception\Trait\Generative\GenerativeExceptionTrait;

/**
 * RelativePathGenerativeException
 *
 * @package Atom\Exception\IO\Generative
 */
class RelativePathGenerativeException extends \Exception implements
    GenerativeExceptionInterface,
    \Stringable,
    \Throwable
{
    use GenerativeExceptionTrait;

    protected $message = 'A I/O relative path error occurred.';
    protected $code = 621;

    public function __construct(string $format, ...$params)
    {
        $this->message = \sprintf($format, ...$params);
    }
}
