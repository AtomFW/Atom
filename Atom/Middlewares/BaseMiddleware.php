<?php

declare(strict_types=1);

namespace Atom\Middlewares;

/**
 * Abstract base class for HTTP/Controller middleware.
 */
abstract class BaseMiddleware
{
    abstract public function execute(): void;
}
