<?php

declare(strict_types=1);

/**
 * Log system configuration template
 */

return [
    /**
     * The default mail driver
     * 
     * Available drivers:
     * log - log messages
     * smtp - send emails a provaider
     * local - local mail server
     * native - native php mail
     * 
     * @var string
     */
     'default' => env('LOG_DRIVER', 'log'),

    /**
     * log - log messages
     * native - log messages to the native system log
     * null - do not log messages only emergency logs
     * 
     * available drivers: log, native, null
     * 
     * @var array
     */
    'choices' => [
        'log' => [
            'driver' => 'log',
            'path' => realpath(env('LOG_PATH', __DIR__ . '/../runtime/log/')) . DIRECTORY_SEPARATOR,
            // 'path' => realpath(env('LOG_PATH', env('APP_URL', 'http://localhost') . '/runtime/log')),
            'level' => env('LOG_LEVEL', 'debug'), // log level: debug, info, notice, warning, error, critical, alert, emergency
            'maxFileSizeBytes' => (int) env('LOG_MAX_FILE_SIZE_BYTES', 10485760), // 10 MB per log file before rotation
            'maxFiles' => (int) env('LOG_MAX_FILES', 14), // Maximum rotated log files retained
        ],
        'stdout' => [
            'driver' => 'stdout',
            'level' => env('LOG_LEVEL', 'info'), // Stateless container / Swoole / Kubernetes JSON stdout logger
            'json' => (bool) env('LOG_STDOUT_JSON', true),
        ],
        'native' => [
            'driver' => 'native',
            'level' => env('LOG_LEVEL', 'debug'), // log level: debug, info, notice, warning, error, critical, alert, emergency
        ],
        'null' => [
            'driver' => 'null',
        ],
        'emergency' => [
            'driver' => 'emergency',
           'path' => realpath(env('LOG_EMERGENCY_PATH', __DIR__ . '/../runtime/log/')) . DIRECTORY_SEPARATOR,
        ],
    ],

    /**
     * Automatically redact sensitive keys (passwords, tokens, secrets) from log context.
     *
     * @var array
     */
    'redactKeys' => [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'private_key',
        'authorization',
        'cookie',
    ],
];
