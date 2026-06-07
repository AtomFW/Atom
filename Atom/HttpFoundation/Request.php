<?php

declare(strict_types=1);

namespace Atom\HttpFoundation;

/**
 * Represents an HTTP request.
 *
 * The Request class provides methods for accessing information about the current
 * HTTP request such as headers, cookies, and input data.
 */
class Request
{
    /**
     * Array to store route parameters.
     */
    private array $routeParams = [];

    /**
     * Gets the HTTP method of the request.
     *
     * This method returns the HTTP method used for the current request,
     * ensuring the method is returned in lowercase format.
     *
     * @return string The HTTP method in lowercase
     */
    public function getMethod()
    {
        return strtolower($_SERVER['REQUEST_METHOD']);
    }

    /**
     * Gets the URL of the current request.
     *
     * This method returns the path component of the current request URI,
     * excluding any query string parameters. It processes the REQUEST_URI
     * server variable to remove the query string portion.
     *
     * @return string The URL path without query parameters
     */
    public function getUrl()
    {
        $path = $_SERVER['REQUEST_URI'];
        $position = strpos($path, '?');
        if ($position !== false) {
            $path = substr($path, 0, $position);
        }
        return $path;
    }

    /**
     * Checks if the current request is a GET request.
     *
     * This method checks whether the HTTP method of the current request
     * is 'get' (case-insensitive comparison).
     *
     * @return bool True if the request method is 'get', false otherwise
     */
    public function isGet()
    {
        return $this->getMethod() === 'get';
    }

    /**
     * Checks if the current request is a POST request.
     *
     * This method checks whether the HTTP method of the current request
     * is 'post' (case-insensitive comparison).
     *
     * @return bool True if the request method is 'post', false otherwise
     */
    public function isPost()
    {
        return $this->getMethod() === 'post';
    }

    /**
     * Checks if the current request is a PUT request.
     *
     * This method checks whether the HTTP method of the current request
     * is 'put' (case-insensitive comparison).
     *
     * @return bool True if the request method is 'put', false otherwise
     */
    public function isPut()
    {
        return $this->getMethod() === 'put';
    }

    /**
     * Checks if the current request is a DELETE request.
     *
     * This method checks whether the HTTP method of the current request
     * is 'delete' (case-insensitive comparison).
     *
     * @return bool True if the request method is 'delete', false otherwise
     */
    public function isDelete()
    {
        return $this->getMethod() === 'delete';
    }

    /**
     * Checks if the current request is a PATCH request.
     *
     * This method checks whether the HTTP method of the current request
     * is 'patch' (case-insensitive comparison).
     *
     * @return bool True if the request method is 'patch', false otherwise
     */
    public function isPatch()
    {
        return $this->getMethod() === 'patch';
    }

    /**
     * Checks if the current request is an OPTIONS request.
     *
     * This method checks whether the HTTP method of the current request
     * is 'options' (case-insensitive comparison).
     *
     * @return bool True if the request method is 'options', false otherwise
     */
    public function isOptions()
    {
        return $this->getMethod() === 'options';
    }

    /**
     * Checks if the current request is a HEAD request.
     *
     * This method checks whether the HTTP method of the current request
     * is 'head' (case-insensitive comparison).
     *
     * @return bool True if the request method is 'head', false otherwise
     */
    public function isHead()
    {
        return $this->getMethod() === 'head';
    }

    /**
     * @return array
     */
    public function getBody()
    {
        $data = [];
        if ($this->isGet()) {
            foreach ($_GET as $key => $value) {
                $data[$key] = filter_input(INPUT_GET, $key, FILTER_SANITIZE_SPECIAL_CHARS);
            }
        }
        if ($this->isPost()) {
            foreach ($_POST as $key => $value) {
                $data[$key] = filter_input(INPUT_POST, $key, FILTER_SANITIZE_SPECIAL_CHARS);
            }
        }
        return $data;
    }

    /**
     * @param $params
     * @return self
     */
    public function setRouteParams($params)
    {
        $this->routeParams = $params;
        return $this;
    }

    /**
     * @return array
     */
    public function getRouteParams()
    {
        return $this->routeParams;
    }

    /**
     * Get a route parameter value.
     *
     * @param  string  $param
     * @param  mixed  $default
     * @return mixed
     */
    public function getRouteParam($param, $default = null)
    {
        return $this->routeParams[$param] ?? $default;
    }
}
