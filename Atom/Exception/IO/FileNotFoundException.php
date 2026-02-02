<?php

namespace Atom\Exception\IO;

final class FileNotFoundException extends \Exception implements \Stringable, \Throwable
{
    protected $message = 'A file could not be found.';
    protected $code = 404;
}
