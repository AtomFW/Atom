<?php

declare(strict_types=1);

namespace Atom\HttpFoundation;

/**
 * HTTP response abstraction with CRLF header injection protection and Swoole/CLI compatibility.
 */
class Response
{
    private int $status = 200;

    /** @var array<string, string> */
    private array $headers = [];

    public function statusCode(int $code): void
    {
        $this->status = $code;
        if ($code >= 100 && $code <= 599 && !\headers_sent()) {
            \http_response_code($code);
        }
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setHeader(string $name, string $value): void
    {
        $safeName = \str_replace(["\r", "\n", "\0"], '', $name);
        $safeValue = \str_replace(["\r", "\n", "\0"], '', $value);
        $this->headers[$safeName] = $safeValue;

        if (!\headers_sent()) {
            \header("{$safeName}: {$safeValue}");
        }
    }

    /**
     * Redirects the client to a sanitized URL (protected against CRLF header splitting).
     */
    public function redirect(string $url, int $statusCode = 302): void
    {
        $safeUrl = \str_replace(["\r", "\n", "\0"], '', $url);
        $this->status = $statusCode;
        $this->headers['Location'] = $safeUrl;

        if (!\headers_sent()) {
            \header("Location: {$safeUrl}", true, $statusCode);
        }
    }

    public function exitWithMessage(string $message = ''): never
    {
        $this->statusCode(403);
        echo $message;
        $this->terminate();
    }

    public function exitWithErrorMessage(string $message = ''): never
    {
        $this->statusCode(500);
        echo $message;
        $this->terminate();
    }

    public function exitWithStatusCode(int $code, ?string $message = null): never
    {
        $this->statusCode($code);

        if ($message !== null) {
            echo $message;
        }

        $this->terminate();
    }

    protected function terminate(): never
    {
        exit;
    }
}
