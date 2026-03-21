<?php

/**
 * Szablon konfiguracji sesji
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
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
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
     * If true, a new session will be created when a user logs in.
     *
     * @var bool
     */
    'newSessionWhenLogin' => env('SESSION_NEW_SESSION_WHEN_LOGIN', false),


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
     * If true, the cookie will be marked as secure.
     *
     * @var bool
     */
    'secure' => env('SESSION_SECURE', false),

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
