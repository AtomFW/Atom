<?php

declare(strict_types=1);

namespace Atom\Exception\IO\Generative;

use Atom\Exception\Interface\Generative\GenerativeExceptionInterface;
use Atom\Exception\Trait\Generative\GenerativeExceptionTrait;

/**
 * FileNotFoundGenerativeException
 *
 * @package Atom\Exception\IO\Generative
 */
final class FileNotFoundGenerativeException extends \Exception implements
    GenerativeExceptionInterface,
    \Stringable,
    \Throwable
{
    use GenerativeExceptionTrait;

    protected $message = 'A file could not be found.';
    protected $code = 604;

    public function __construct(string $format, ...$params)
    {
        $this->message = \sprintf($format, ...$params);
    }
}
