<?php

/**
 * Szablon konfiguracji autoryzacji
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
    'passwordTimeout' => env('AUTH_PASSWORD_TIMEOUT', 14400), // 4 hours in seconds
];
