<?php

declare(strict_types=1);

namespace Atom\Exception\IO;

use Exception;

final class FileNotFoundException extends Exception
{
    /** @var string */
    protected $message = 'A file could not be found.';

    /** @var int */
    protected $code = 404;
}
