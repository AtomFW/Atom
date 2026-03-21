<?php

namespace Atom\middlewares;

/**
 * Abstract base class for middleware.
 *
 * Middleware classes are responsible for performing actions (filtering, logging, etc.)
 * before or after the controller is called.
 *
 * @package Atom\middlewares
 *
 * @abstract
 */
abstract class BaseMiddleware
{
    abstract public function execute();
}
