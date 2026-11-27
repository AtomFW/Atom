<?php

declare(strict_types=1);

namespace Atom\Modules\UserAuth\Middlewares;

use Atom\Atom;
use Atom\Exception\ForbiddenException;
use Atom\Middlewares\BaseMiddleware;
use Atom\Modules\UserAuth\Account;
use Override;

/**
 * Middleware enforcing user authentication for protected controller actions (`UserAuth` module).
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
        if (Account::isGuest()) {
            $controller = isset(Atom::$app) ? Atom::$app->controller : null;
            $currentAction = $controller !== null ? $controller->action : '';
            if ($this->actions === [] || \in_array($currentAction, $this->actions, true)) {
                throw new ForbiddenException();
            }
        }
    }
}
