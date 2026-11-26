<?php

declare(strict_types=1);

namespace Atom\Exception\IO\Generative;

use Atom\Exception\Interface\Generative\GenerativeExceptionInterface;
use Atom\Exception\Trait\Generative\GenerativeExceptionTrait;
use Exception;

final class IOGenerativeException extends Exception implements GenerativeExceptionInterface
{
    use GenerativeExceptionTrait;

    /** @var string */
    protected $message = 'A handler error occurred during I/O operation.';

    /** @var int */
    protected $code = 600;
}
