<?php

declare(strict_types=1);

/**
 * Email configuration template
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
     'default' => env('MAIL_DRIVER', 'log'),

    /**
     * log - log messages
     * smtp - send emails a provaider
     * local - local mail server
     * native - native php mail
     * 
     * available drivers: log, native
     * 
     * @var array
     */
    'choices' => [
        'log' => [
            'driver' => 'log',
        ],
        'smtp' => [
            'driver' => 'smtp',
            'scheme' => env('MAIL_SCHEME', 'tls'),
            'url' => env('MAIL_URL', 'smtp://localhost'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 25),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'smtpAuth' => env('MAIL_SMTP_AUTH', false),
            'charset' => env('MAIL_CHARSET', 'UTF-8'),
            'timeout' => null,
            'localDomain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'https://localhost'), PHP_URL_HOST)),
        ],
        'local' => [
            'driver' => 'local',
        ],
        'native' => [
            'driver' => 'native',
        ],
    ], 

    /**
     * Default from address
     * 
     * @var array
     */
    'from' => [
        /**
         * Name of the sender
         * 
         * @var string
         */
        'name' => env('MAIL_FROM_NAME', 'Example by Atom'),
        /**
         * Email address of the sender
         * 
         * @var string
         */
        'address' => env('MAIL_FROM_ADDRESS', 'notify@example.com'),
    ],
];
