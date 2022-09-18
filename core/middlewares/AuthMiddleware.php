<?php

namespace Atom\core\middlewares;

use Atom\core\Atom;
use Atom\core\exception\ForbiddenException;

class AuthMiddleware extends BaseMiddleware
{
    protected array $actions = [];

    public function __construct($actions = [])
    {
        $this->actions = $actions;
    }

    public function execute()
    {
        if (Atom::isGuest()) {
            if (empty($this->actions) || in_array(Atom::$app->controller->action, $this->actions)) {
                throw new ForbiddenException();
            }
        }
    }
}
