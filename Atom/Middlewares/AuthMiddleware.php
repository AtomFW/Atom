<?php

declare(strict_types=1);

namespace Atom\middlewares;

use Atom\Atom;
use Atom\Exception\ForbiddenException;

/**
 * AuthMiddleware
 *
 * This middleware is responsible for checking if a user is authenticated.
 * If the user is not authenticated, it will throw a ForbiddenException.
 *
 * @package Atom\middlewares
 */
final class AuthMiddleware extends BaseMiddleware
{
    protected array $actions = [];

    /**
     * AuthMiddleware constructor
     *
     * @param array $actions List of actions that do not require authentication
     */
    public function __construct($actions = [])
    {
        $this->actions = $actions;
    }

    /**
     * Executes the middleware.
     *
     * This method will check if the user is authenticated. If the user is not authenticated,
     * it will throw a ForbiddenException.
     *
     * @throws ForbiddenException If the user is not authenticated.
     */
    public function execute(): void
    {
        if (Atom::$app->account->isGuest()) {
            if (empty($this->actions) || \in_array(Atom::$app->controller->action, $this->actions)) {
                throw new ForbiddenException();
            }
        }
    }
}
