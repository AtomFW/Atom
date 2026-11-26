<?php

declare(strict_types=1);

namespace Atom\HttpFoundation;

use Atom\Filter\InputFilter;
use Atom\Filter\TypeCaster;
use Atom\Model;

/**
 * HTTP request abstraction supporting isolated DTO data (Swoole / FrankenPHP / testing)
 * and standard PHP superglobal fallbacks, with built-in safe input cleaning and typed model binding.
 */
class Request
{
    /** @var array<string, mixed> */
    private array $routeParams = [];

    /**
     * @param array<string, mixed>|null $query     Optional isolated GET parameters
     * @param array<string, mixed>|null $post      Optional isolated POST parameters
     * @param array<string, mixed>|null $server    Optional isolated server parameters
     * @param array<string, mixed>|null $cookies   Optional isolated cookies
     * @param string|null               $rawBody   Optional raw HTTP payload
     * @param list<string>              $rawFields Fields excluded from trimming (e.g. passwords)
     * @param array<string, mixed>|null $files     Optional isolated uploaded files bag
     */
    public function __construct(
        private readonly ?array $query = null,
        private readonly ?array $post = null,
        private readonly ?array $server = null,
        private readonly ?array $cookies = null,
        private readonly ?string $rawBody = null,
        private readonly array $rawFields = ['password', 'passwordConfirm', 'currentPassword', 'token', 'secret'],
        private readonly ?array $files = null,
    ) {}

    /**
     * Creates an isolated stateless Request instance from explicit arrays (ideal for Swoole HTTP Server).
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     */
    public static function create(
        array $query = [],
        array $post = [],
        array $server = [],
        array $cookies = [],
        ?string $rawBody = null,
        array $files = [],
    ): self {
        return new self($query, $post, $server, $cookies, $rawBody, files: $files);
    }

    public function getMethod(): string
    {
        $server = $this->server ?? $_SERVER;
        $method = (string) ($server['REQUEST_METHOD'] ?? 'GET');
        return \strtolower($method);
    }

    public function getUrl(): string
    {
        $server = $this->server ?? $_SERVER;
        $path = (string) ($server['REQUEST_URI'] ?? '/');
        $position = \strpos($path, '?');
        if ($position !== false) {
            $path = \substr($path, 0, $position);
        }

        return $path === '' ? '/' : $path;
    }

    public function isGet(): bool
    {
        return $this->getMethod() === 'get';
    }

    public function isPost(): bool
    {
        return $this->getMethod() === 'post';
    }

    public function isPut(): bool
    {
        return $this->getMethod() === 'put';
    }

    public function isDelete(): bool
    {
        return $this->getMethod() === 'delete';
    }

    public function isPatch(): bool
    {
        return $this->getMethod() === 'patch';
    }

    public function isOptions(): bool
    {
        return $this->getMethod() === 'options';
    }

    public function isHead(): bool
    {
        return $this->getMethod() === 'head';
    }

    public function isJson(): bool
    {
        $server = $this->server ?? $_SERVER;
        $contentType = (string) ($server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? '');
        return \stripos($contentType, 'application/json') !== false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        $source = $this->query ?? $_GET;
        return InputFilter::cleanArray($source, false, $this->rawFields);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFiles(): array
    {
        return $this->files ?? $_FILES;
    }

    public function getRawBody(): string
    {
        if ($this->rawBody !== null) {
            return $this->rawBody;
        }

        $input = \file_get_contents('php://input');
        return $input !== false ? $input : '';
    }

    /**
     * Returns cleaned request payload (GET query, POST form, or decoded JSON body) without corrupting passwords/types.
     *
     * @return array<string, mixed>
     */
    public function getBody(): array
    {
        if ($this->isGet()) {
            $source = $this->query ?? $_GET;
            return InputFilter::cleanArray($source, false, $this->rawFields);
        }

        if ($this->isJson()) {
            $raw = $this->getRawBody();
            if ($raw !== '') {
                $decoded = \json_decode($raw, true);
                if (\is_array($decoded)) {
                    return InputFilter::cleanArray($decoded, false, $this->rawFields);
                }
            }
        }

        if ($this->isPost()) {
            $source = $this->post ?? $_POST;
            return InputFilter::cleanArray($source, false, $this->rawFields);
        }

        if ($this->isPut() || $this->isPatch() || $this->isDelete()) {
            if ($this->post !== null && $this->post !== []) {
                return InputFilter::cleanArray($this->post, false, $this->rawFields);
            }
            $raw = $this->getRawBody();
            if ($raw !== '') {
                $parsed = [];
                \parse_str($raw, $parsed);
                return InputFilter::cleanArray($parsed, false, $this->rawFields);
            }
        }

        return [];
    }

    /**
     * Automatically filters and casts request data directly into a typed Model instance.
     *
     * @template T of Model
     * @param T $model
     * @return T
     */
    public function fillModel(Model $model): Model
    {
        TypeCaster::populateFromForm($model, $this->getBody());
        return $model;
    }

    public function getCookie(string $name, mixed $default = null): mixed
    {
        $cookies = $this->cookies ?? $_COOKIE;
        return $cookies[$name] ?? $default;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function setRouteParams(array $params): self
    {
        $this->routeParams = $params;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    public function getRouteParam(string $param, mixed $default = null): mixed
    {
        return $this->routeParams[$param] ?? $default;
    }
}
