<?php

declare(strict_types=1);

namespace Atom\HttpFoundation;

use Atom\Atom;

/**
 * HTTP response abstraction with CRLF header injection protection and Swoole/CLI compatibility.
 */
class Response
{
    private int $status = 200;
    private string $statusReason = 'Default 200 OK';
    private ?string $statusOriginFile = null;
    private ?int $statusOriginLine = null;

    /** @var array<string, string> */
    private array $headers = [];

    public function statusCode(int $code, string $reason = ''): void
    {
        $this->status = $code;
        if ($reason !== '') {
            $this->statusReason = $reason;
        } elseif ($code !== 200) {
            $this->statusReason = "Explicit statusCode({$code})";
        }

        $probe = isset(Atom::$app) ? Atom::$app->probe : null;
        if ($probe !== null) {
            $trace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $trace[0] ?? [];
            $callerFnFrame = $trace[1] ?? [];
            $this->statusOriginFile = isset($caller['file']) ? (string) $caller['file'] : null;
            $this->statusOriginLine = isset($caller['line']) ? (int) $caller['line'] : null;
            $fnName = isset($callerFnFrame['function'])
                ? ((isset($callerFnFrame['class']) ? $callerFnFrame['class'] . '::' : '') . $callerFnFrame['function'])
                : null;
            $probe->onResponseStatusChanged($code, $this->statusReason, $this->statusOriginFile, $this->statusOriginLine, $fnName);
        }

        if ($code >= 100 && $code <= 599 && !\headers_sent()) {
            \http_response_code($code);
        }
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getStatusReason(): string
    {
        return $this->statusReason;
    }

    public function getStatusOriginFile(): ?string
    {
        return $this->statusOriginFile;
    }

    public function getStatusOriginLine(): ?int
    {
        return $this->statusOriginLine;
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
        $this->statusReason = "Redirect ({$statusCode}) -> {$safeUrl}";
        $this->headers['Location'] = $safeUrl;

        $probe = isset(Atom::$app) ? Atom::$app->probe : null;
        if ($probe !== null) {
            $trace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $trace[0] ?? [];
            $callerFnFrame = $trace[1] ?? [];
            $this->statusOriginFile = isset($caller['file']) ? (string) $caller['file'] : null;
            $this->statusOriginLine = isset($caller['line']) ? (int) $caller['line'] : null;
            $fnName = isset($callerFnFrame['function'])
                ? ((isset($callerFnFrame['class']) ? $callerFnFrame['class'] . '::' : '') . $callerFnFrame['function'])
                : null;
            $probe->onResponseStatusChanged($statusCode, $this->statusReason, $this->statusOriginFile, $this->statusOriginLine, $fnName);
        }

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
