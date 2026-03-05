<?php

namespace Atom\HttpFoundation;

class Response
{
    public function statusCode(int $code)
    {
        http_response_code($code);
    }

    public function redirect($url)
    {
        header("Location: $url");
    }

    public function exitWidhMessage($message = ''): never
    {
        self::statusCode(code: 403);

        echo $message;

        exit;
    }

    public function exitWithErrorMessage($message = ''): never
    {
        self::statusCode(code: 500);

        echo $message;

        exit;
    }

    public function exitWithStatusCode(int $code, ?string $message = null): never
    {
        self::statusCode(code: $code);

        if ($message !== null) {
            echo $message;
        }

        exit;
    }
}
