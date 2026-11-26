<?php

declare(strict_types=1);

namespace Atom\Middlewares;

use Atom\Atom;
use Atom\Exception\ForbiddenException;
use Override;

/**
 * Middleware enforcing user authentication for protected controller actions.
 */
final class AuthMiddleware extends BaseMiddleware
{
    /**
     * @param list<string> $actions Protected actions requiring authentication (empty = all actions protected).
     */
    public function __construct(
        protected array $actions = [],
    ) {}

    /**
     * @throws ForbiddenException If the user is not authenticated.
     */
    #[Override]
    public function execute(): void
    {
        if (Atom::$app->account::isGuest()) {
            $controller = Atom::$app->controller;
            $currentAction = $controller !== null ? $controller->action : '';
            if ($this->actions === [] || \in_array($currentAction, $this->actions, true)) {
                throw new ForbiddenException();
            }
        }
    }
}
