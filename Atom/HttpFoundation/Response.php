<?php

declare(strict_types=1);

namespace Atom\HttpFoundation;

/**
 * Represents an HTTP response
 *
 * This class models an HTTP response, providing methods to manipulate
 * headers, status codes, and content.
 */
class Response
{
    public function statusCode(int $code)
    {
        http_response_code($code);
    }

    /**
     * Redirects the user to a specified URL
     *
     * @param string $url The URL to redirect to
     * @return void
     */
    public function redirect($url)
    {
        header("Location: $url");
    }

    /**
     * @param string $message
     * @return never
     */
    public function exitWithMessage(string $message = ''): never
    {
        self::statusCode(code: 403);

        echo $message;

        exit;
    }

    /**
     * Exit with an error message and set HTTP status code to 500 (Internal Server Error)
     *
     * @param string $message The error message to display
     * @return never
     */
    public function exitWithErrorMessage($message = ''): never
    {
        self::statusCode(code: 500);

        echo $message;

        exit;
    }

    /**
     * Exits the script with a specified HTTP status code and optional message.
     *
     * This method sets the HTTP response status code and optionally outputs a message.
     * The script execution stops after calling this method.
     *
     * @param int $code The HTTP status code to send (e.g., 404, 500).
     * @param string|null $message Optional message to display before exiting.
     * @return never Returns control to the browser with the specified status code and message.
     */
    public function exitWithStatusCode(int $code, ?string $message = null): never
    {
        self::statusCode(code: $code);

        if ($message !== null) {
            echo $message;
        }

        exit;
    }
}
