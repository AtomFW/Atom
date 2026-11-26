<?php

declare(strict_types=1);

/**
 * Authorization configuration template
 */

return [
    /**
     * The default authentication configuration.
     *
     * This array contains the default configuration for the
     * authentication.
     *
     * @var array
     */
    'default' => [
        'provider' => env('AUTH_PROVIDER', 'user'),
        'password' => env('AUTH_PASSWORD_BROKER', 'user'),
    ],

    /**
     * Configuration for the authentication provider.
     *
     * This array contains the configuration for the authentication
     * provider.
     *
     * @var array
     */
    'provider' => [
        'user' => [
            'driver' => 'native',
            'model' => env('AUTH_MODEL', App\Models\User::class),
        ]
    ],

    /**
     * Configuration for password resetting.
     *
     * This array contains the configuration for the password resetting
     * feature.
     *
     * @var array
     */
    'password' => [
        /**
         * Configuration for the user model.
         *
         * This array contains the configuration for the user model.
         *
         * @var array
         */
        'user' => [
            'class' => 'user',
            'expire' => 60,
            'throttle' => 60,
            'table' => env('AUTH_PASSWORD_RESET_TABLE', 'password_resets_token'),
            'resettingThrottle' => env('AUTH_PASSWORD_RESET_THROTTLE', 60),
        ]
    ],

    /**
     * Number of seconds that a password reset token is valid for.
     * After this time, the token will be invalid and the user will need to request a new one.
     *
     * @var int
     */
    'passwordTimeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 14400), // 4 hours in seconds

    /**
     * Password hashing configuration (secure Argon2id by default).
     *
     * @var array
     */
    'hashing' => [
        /**
         * Default password hashing algorithm ('argon2id' or 'bcrypt').
         *
         * @var string
         */
        'driver' => env('AUTH_HASH_DRIVER', 'argon2id'),

        /**
         * Argon2id memory cost in kibibytes (defaults to 64 MiB).
         *
         * @var int
         */
        'memoryCost' => (int) env('AUTH_HASH_MEMORY_COST', 65536),

        /**
         * Argon2id time cost (iterations).
         *
         * @var int
         */
        'timeCost' => (int) env('AUTH_HASH_TIME_COST', 4),

        /**
         * Bcrypt cost fallback when bcrypt driver is selected.
         *
         * @var int
         */
        'bcryptCost' => (int) env('AUTH_HASH_BCRYPT_COST', 12),
    ],

    /**
     * Login brute-force lockout protection settings.
     *
     * @var array
     */
    'lockout' => [
        /**
         * Enable login throttling / lockout.
         *
         * @var bool
         */
        'on' => (bool) env('AUTH_LOCKOUT_ON', true),

        /**
         * Maximum failed login attempts before temporary lockout.
         *
         * @var int
         */
        'maxAttempts' => (int) env('AUTH_LOCKOUT_MAX_ATTEMPTS', 5),

        /**
         * Lockout duration in seconds (defaults to 15 minutes).
         *
         * @var int
         */
        'decaySeconds' => (int) env('AUTH_LOCKOUT_DECAY_SECONDS', 900),
    ],

    /**
     * Stateless API / microservice token authentication settings.
     *
     * @var array
     */
    'token' => [
        /**
         * Enable stateless Bearer token authentication for microservices and APIs.
         *
         * @var bool
         */
        'on' => (bool) env('AUTH_TOKEN_ON', true),

        /**
         * HTTP header carrying the authentication token.
         *
         * @var string
         */
        'header' => env('AUTH_TOKEN_HEADER', 'Authorization'),

        /**
         * Access token lifetime in seconds (defaults to 1 hour).
         *
         * @var int
         */
        'accessTtlSeconds' => (int) env('AUTH_TOKEN_ACCESS_TTL', 3600),

        /**
         * Refresh token lifetime in seconds (defaults to 30 days).
         *
         * @var int
         */
        'refreshTtlSeconds' => (int) env('AUTH_TOKEN_REFRESH_TTL', 2592000),
    ],
];
