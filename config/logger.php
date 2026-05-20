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
];
