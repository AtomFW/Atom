<?php

declare(strict_types=1);

namespace Atom;

use Atom\Middlewares\BaseMiddleware;

class Controller
{
    public string $layout = 'main';
    public string $action = '';

    /**
     * @var list<BaseMiddleware>
     */
    protected array $middlewares = [];

    public function setLayout(string $layout): void
    {
        $this->layout = $layout;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function render(string $view, array $params = []): string
    {
        return Atom::$app->router->renderView($view, $params);
    }

    public function registerMiddleware(BaseMiddleware $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /**
     * @return list<BaseMiddleware>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }
}
