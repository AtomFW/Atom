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
        $probe = isset(Atom::$app) ? Atom::$app->probe : null;

        if ($probe !== null) {
            $probe->recordTraversedFile(__FILE__, 'router', __LINE__);
        }

        $routeSpan = $probe !== null
            ? $probe->startSpan('router.match', 'core', __FILE__, __LINE__, ['method' => $method, 'url' => $url])
            : '';

        $matchedPattern = $url;
        $callback = $this->routeMap[$method][$url] ?? false;
        if ($callback === false) {
            $callback = $this->getCallback($req);
            if ($callback === false) {
                if ($probe !== null && $routeSpan !== '') {
                    $probe->endSpan($routeSpan, ['matched' => false]);
                }
                throw new NotFoundException();
            }
            $matchedPattern = 'dynamic:' . $url;
        }

        if ($probe !== null && $routeSpan !== '') {
            $probe->endSpan($routeSpan, ['matched' => true, 'pattern' => $matchedPattern]);
        }

        if (\is_string($callback)) {
            if ($probe !== null) {
                $probe->onRouteMatched($method, $url, $matchedPattern, 'view:' . $callback, __FILE__, __LINE__, $req->getRouteParams());
                $probe->onResponseStatusChanged($res->getStatusCode(), "Matched direct view route '{$callback}'", __FILE__, __LINE__, __METHOD__);
            }
            return $this->renderView($callback);
        }

        if (\is_array($callback)) {
            $controllerOrClass = $callback[0];
            /** @var Controller $controller */
            $controller = \is_string($controllerOrClass) ? new $controllerOrClass() : $controllerOrClass;
            $controller->action = (string) $callback[1];
            Atom::$app->controller = $controller;

            $controllerClass = $controller::class;
            $actionName = $controller->action;
            $ctrlFile = null;
            $ctrlLine = null;

            if ($probe !== null) {
                $refCtrl = new \ReflectionClass($controller);
                $ctrlFile = $refCtrl->getFileName() ?: null;
                if ($refCtrl->hasMethod($actionName)) {
                    $refAct = $refCtrl->getMethod($actionName);
                    $ctrlFile = $refAct->getFileName() ?: $ctrlFile;
                    $ctrlLine = $refAct->getStartLine() ?: null;
                }
                if ($ctrlFile !== null) {
                    $probe->recordTraversedFile($ctrlFile, 'controller', $ctrlLine);
                }
                $probe->onRouteMatched(
                    $method,
                    $url,
                    $matchedPattern,
                    $controllerClass . '::' . $actionName,
                    $ctrlFile,
                    $ctrlLine,
                    $req->getRouteParams(),
                );
            }

            foreach ($controller->getMiddlewares() as $middleware) {
                if ($probe === null) {
                    $middleware->execute();
                    continue;
                }

                $mwRef = new \ReflectionClass($middleware);
                $mwFile = $mwRef->getFileName() ?: null;
                $mwLine = $mwRef->hasMethod('execute') ? ($mwRef->getMethod('execute')->getStartLine() ?: null) : null;
                if ($mwFile !== null) {
                    $probe->recordTraversedFile($mwFile, 'middleware', $mwLine);
                }
                $mwCat = \str_starts_with($middleware::class, 'Atom\\') ? 'core' : 'client_app';
                $mwSpan = $probe->startSpan('middleware:' . $middleware::class, $mwCat, $mwFile, $mwLine);
                $mwErr = null;
                try {
                    $middleware->execute();
                } catch (\Throwable $e) {
                    $mwErr = $e;
                    throw $e;
                } finally {
                    $probe->endSpan($mwSpan, [], $mwErr);
                }
            }

            $callback[0] = $controller;

            if ($probe !== null) {
                $cat = \str_starts_with($controllerClass, 'Atom\\') ? 'core' : 'client_app';
                $actSpan = $probe->startSpan('controller:' . $controllerClass . '::' . $actionName, $cat, $ctrlFile, $ctrlLine);
                $actErr = null;
                try {
                    $result = $controller->{$actionName}($req, $res);
                    if ($res->getStatusReason() === 'Default 200 OK') {
                        $probe->onResponseStatusChanged(
                            $res->getStatusCode(),
                            "Returned from {$controllerClass}::{$actionName}()",
                            $ctrlFile,
                            $ctrlLine,
                            $controllerClass . '::' . $actionName,
                        );
                    }
                    return $result;
                } catch (\Throwable $e) {
                    $actErr = $e;
                    throw $e;
                } finally {
                    $probe->endSpan($actSpan, [], $actErr);
                }
            }
        }

        if ($probe !== null && $callback instanceof \Closure) {
            $refFn = new \ReflectionFunction($callback);
            $fnFile = $refFn->getFileName() ?: null;
            $fnLine = $refFn->getStartLine() ?: null;
            if ($fnFile !== null) {
                $probe->recordTraversedFile($fnFile, 'route_closure', $fnLine);
            }
            $ns = $refFn->getNamespaceName();
            $cat = \str_starts_with($ns, 'Atom') ? 'core' : 'client_app';
            $probe->onRouteMatched($method, $url, $matchedPattern, 'Closure@' . ($fnFile ?? 'runtime') . ':' . ($fnLine ?? 0), $fnFile, $fnLine, $req->getRouteParams());

            $clSpan = $probe->startSpan('route.closure', $cat, $fnFile, $fnLine);
            $clErr = null;
            try {
                $resBody = $callback($req, $res);
                if ($res->getStatusReason() === 'Default 200 OK') {
                    $probe->onResponseStatusChanged($res->getStatusCode(), 'Returned from route closure', $fnFile, $fnLine, 'Closure');
                }
                return $resBody;
            } catch (\Throwable $e) {
                $clErr = $e;
                throw $e;
            } finally {
                $probe->endSpan($clSpan, [], $clErr);
            }
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
