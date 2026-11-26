<?php

declare(strict_types=1);

namespace Atom\Exception;

use Exception;

/**
 * General I/O exception in root exception namespace.
 */
class IOException extends Exception
{
    /** @var string */
    protected $message = 'An I/O operation error occurred.';

    /** @var int */
    protected $code = 500;
}
