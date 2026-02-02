<?php

namespace Atom\middlewares;

use Atom\Atom;
use Atom\Exception\ForbiddenException;

class AuthMiddleware extends BaseMiddleware
{
    protected array $actions = [];

    public function __construct($actions = [])
    {
        $this->actions = $actions;
    }

    public function execute(): void
    {
        if (Atom::isGuest()) {
            if (empty($this->actions) || in_array(Atom::$app->controller->action, $this->actions)) {
                throw new ForbiddenException();
            }
        }
    }
}
