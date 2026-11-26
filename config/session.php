<?php

declare(strict_types=1);

/**
 * Session configuration template
 */

return [
    /**
     * The default session driver.
     *
     * This is the session driver that will be used by default.
     *
     * Available drivers:
     * cookie - stores session data in cookies
     * database - stores session data in a database (comming soon)
     * file - stores session data in a file  (comming soon)
     * redis - stores session data in a Redis server (comming soon)
     *
     * @var string
     */
    'default' => env('SESSION_DRIVER', 'cookie'),

    /**
     * An array of available session drivers.
     *
     * The keys of this array should match the values of the
     * `SESSION_DRIVER` environment variable.
     *
     * @var array
     */
    'choices' => [
        'cookie' => [
            'driver' => 'cookie',
        ],
        'database' => [
            'driver' => 'database',
            'table' => 'sessions',
        ],
        'file' => [
            'driver' => 'file',
            'path' => env('SESSION_FILE_PATH', 'runtime/sessions'),
        ],
        'redis' => [
            'driver' => 'redis',
            'host' => env('SESSION_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
            'port' => (int) env('SESSION_REDIS_PORT', env('REDIS_PORT', 6379)),
            'username' => env('SESSION_REDIS_USERNAME', env('REDIS_USERNAME', null)),
            'password' => env('SESSION_REDIS_PASSWORD', env('REDIS_PASSWORD', null)),
            'database' => (int) env('SESSION_REDIS_DATABASE', 1),
            'prefix' => env('SESSION_REDIS_PREFIX', 'atom_sess:'),
            'timeout' => (float) env('SESSION_REDIS_TIMEOUT', 1.5),
        ],
    ],

    /**
     * Lifetime of the session in minutes.
     *
     * This is the amount of time that a session will be valid for.
     * The session will expire after this amount of time has passed.
     *
     * @var int
     */
    'lifetime' => env('SESSION_LIFETIME', 240), // Lifetime of the session in minutes. This is the amount of time that a session will be valid for. The session will expire after this amount of time has passed.

    /**
     * Whether the session should expire when the browser is closed.
     *
     * If true, the session will expire when the browser is closed.
     *
     * @var bool
     */
    'expireOnClose' => env('SESSION_EXPIRE_ON_CLOSE', false), // Whether the session should expire when the browser is closed. If true, the session will expire when the browser is closed.

    /**
     * Whether the session data should be encrypted.
     *
     * If true, the session data will be encrypted before being stored.
     *
     * @var bool
     */
    'encrypt' => env('SESSION_ENCRYPT', false), // Whether the session data should be encrypted. If true, the session data will be encrypted before being stored.

    /**
     * Whether a new session should be created when a user logs in.
     *
     * If true, a new session ID will be regenerated when a user logs in (prevents session fixation).
     *
     * @var bool
     */
    'newSessionWhenLogin' => (bool) env('SESSION_NEW_SESSION_WHEN_LOGIN', true),

    /**
     * Enforce strict session mode (aligned with PHP 8.6 hardened session defaults).
     *
     * Rejects uninitialized session IDs supplied by clients to prevent session fixation.
     *
     * @var bool
     */
    'useStrictMode' => (bool) env('SESSION_USE_STRICT_MODE', true),

    /**
     * Length of generated session IDs for high entropy in stateless token/Redis stores.
     *
     * @var int
     */
    'sidLength' => (int) env('SESSION_SID_LENGTH', 48),

    /**
     * Path of the session cookie.
     *
     * This is the path that will be used for the session cookie.
     *
     * @var string
     */
    'path' => env('SESSION_PATH', '/'),

    /**
     * Domain of the session cookie.
     *
     * This is the domain that will be used for the session cookie.
     *
     * If null, the cookie will be available to all subdomains.
     *
     * @var string|null
     */
    'domain' => env('SESSION_DOMAIN', null),

    /**
     * Whether the session cookie should be marked as secure.
     *
     * Supports both SESSION_SECURE and SESSION_SECURE_COOKIE environment variables.
     *
     * @var bool
     */
    'secure' => (bool) env('SESSION_SECURE', env('SESSION_SECURE_COOKIE', true)),

    /**
     * Whether the session cookie should be marked as HTTP only.
     *
     * If true, the cookie will be marked as HTTP only.
     *
     * @var bool
     */
    'httpOnly' => env('SESSION_HTTP_ONLY', true),

    /**
     * SameSite attribute of the session cookie.
     *
     * This is the SameSite attribute that will be used for the session cookie.
     *
     * Can be one of 'lax', 'strict', 'none'.
     *
     * @var string
     */
    'sameSite' => env('SESSION_SAME_SITE', 'lax'),

    /**
     * Name of the session cookie.
     *
     * This is the name that will be used for the session cookie.
     *
     * @var string
     */
    'sessionName' => strtoupper(env('SESSION_NAME', 'atom'))  . '-SESSID',
];
