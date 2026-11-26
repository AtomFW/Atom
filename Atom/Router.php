<?php

declare(strict_types=1);

namespace Atom;

use Atom\Exception\NotFoundException;
use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;

class Router
{
    /** @var array<string, array<string, callable|array{0: class-string<Controller>|Controller, 1: string}|string>> */
    private array $routeMap = [];

    public function __construct(
        private Request $request,
        private Response $response,
    ) {}

    public function get(string $url, callable|array|string $callback): void
    {
        $this->routeMap['get'][$url] = $callback;
    }

    public function post(string $url, callable|array|string $callback): void
    {
        $this->routeMap['post'][$url] = $callback;
    }

    public function put(string $url, callable|array|string $callback): void
    {
        $this->routeMap['put'][$url] = $callback;
    }

    public function patch(string $url, callable|array|string $callback): void
    {
        $this->routeMap['patch'][$url] = $callback;
    }

    public function delete(string $url, callable|array|string $callback): void
    {
        $this->routeMap['delete'][$url] = $callback;
    }

    public function options(string $url, callable|array|string $callback): void
    {
        $this->routeMap['options'][$url] = $callback;
    }

    public function any(string $url, callable|array|string $callback): void
    {
        foreach (['get', 'post', 'put', 'patch', 'delete', 'options', 'head'] as $method) {
            $this->routeMap[$method][$url] = $callback;
        }
    }

    /**
     * @return array<string, callable|array{0: class-string<Controller>|Controller, 1: string}|string>
     */
    public function getRouteMap(string $method): array
    {
        return $this->routeMap[\strtolower($method)] ?? [];
    }

    public function getCallback(?Request $request = null): callable|array|string|false
    {
        $req = $request ?? $this->request;
        $method = $req->getMethod();
        $url = \trim($req->getUrl(), '/');
        $routes = $this->getRouteMap($method);

        foreach ($routes as $route => $callback) {
            $trimmedRoute = \trim($route, '/');
            if ($trimmedRoute === '') {
                continue;
            }

            $routeNames = [];
            if (\preg_match_all('/\{(\w+)(:[^}]+)?}/', $trimmedRoute, $matches)) {
                $routeNames = $matches[1];
            }

            $routeRegex = '@^' . \preg_replace_callback(
                '/\{\w+(:([^}]+))?}/',
                static fn(array $m): string => isset($m[2]) ? "({$m[2]})" : '(\w+)',
                $trimmedRoute
            ) . '$@';

            if (\preg_match_all($routeRegex, $url, $valueMatches)) {
                $values = [];
                $matchCount = \count($valueMatches);
                for ($i = 1; $i < $matchCount; $i++) {
                    $values[] = $valueMatches[$i][0];
                }
                $routeParams = \array_combine($routeNames, $values);
                $req->setRouteParams($routeParams);
                return $callback;
            }
        }

        return false;
    }

    public function resolve(?Request $request = null, ?Response $response = null): mixed
    {
        $req = $request ?? $this->request;
        $res = $response ?? $this->response;
        $method = $req->getMethod();
        $url = $req->getUrl();

        $callback = $this->routeMap[$method][$url] ?? false;
        if ($callback === false) {
            $callback = $this->getCallback($req);
            if ($callback === false) {
                throw new NotFoundException();
            }
        }

        if (\is_string($callback)) {
            return $this->renderView($callback);
        }

        if (\is_array($callback)) {
            $controllerOrClass = $callback[0];
            /** @var Controller $controller */
            $controller = \is_string($controllerOrClass) ? new $controllerOrClass() : $controllerOrClass;
            $controller->action = (string) $callback[1];
            Atom::$app->controller = $controller;

            foreach ($controller->getMiddlewares() as $middleware) {
                $middleware->execute();
            }

            $callback[0] = $controller;
        }

        /** @var callable $callback */
        return $callback($req, $res);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function renderView(string $view, array $params = []): string
    {
        return Atom::$app->view->renderView($view, $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function renderViewOnly(string $view, array $params = []): string
    {
        return Atom::$app->view->renderViewOnly($view, $params);
    }
}
