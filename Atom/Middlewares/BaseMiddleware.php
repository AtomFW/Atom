<?php

declare(strict_types=1);

namespace Atom\Middlewares;

/**
 * Abstract base class for middleware.
 *
 * Middleware classes are responsible for performing actions (filtering, logging, etc.)
 * before or after the controller is called.
 *
 * @package Atom\Middlewares
 *
 * @abstract
 */
abstract class BaseMiddleware
{
    abstract public function execute();
}
