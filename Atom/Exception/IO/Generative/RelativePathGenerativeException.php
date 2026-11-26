<?php

declare(strict_types=1);

namespace Atom\Exception\IO\Generative;

use Atom\Exception\Interface\Generative\GenerativeExceptionInterface;
use Atom\Exception\Trait\Generative\GenerativeExceptionTrait;
use Exception;

final class RelativePathGenerativeException extends Exception implements GenerativeExceptionInterface
{
    use GenerativeExceptionTrait;

    /** @var string */
    protected $message = 'An I/O relative path error occurred.';

    /** @var int */
    protected $code = 621;
}
