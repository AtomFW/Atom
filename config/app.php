<?php

/**
 * Szablon konfiguracji aplikacji
 */

return [
    /**
     * Name of the application.
     *
     * @var string
     */
    'name' => env('APP_NAME', 'Atom'), // Name of the application, defaults to 'Atom'

    /**
     * Environment of the application.
     *
     * Can be one of 'production', 'staging', 'development' or 'test'.
     *
     * @var string
     */
    'env' => env('APP_ENV', 'production'), // Environment of the application, defaults to 'production'

    /**
     * Debug mode of the application.
     *
     * When set to true, the application will display detailed error messages.
     *
     * @var bool
     */
    'debug' => (bool) env('APP_DEBUG', false), // Debug mode of the application, defaults to false

    /**
     * Base URL of the application.
     *
     * This is the URL that will be used when generating links.
     *
     * @var string
     */
    'url' => env('APP_URL', 'http://localhost'), // Base URL of the application, defaults to 'http://localhost'

    /**
     * Timezone of the application.
     *
     * This is the timezone that will be used by the application.
     *
     * @var string
     */
    'timezone' => 'UTC', // Timezone of the application, defaults to 'UTC'

    /**
     * The timezone to which the application should convert dates.
     *
     * This is the timezone that will be used when converting dates from the default timezone.
     *
     * @var string
     */
    'toTimezone' => env('APP_TO_TIMEZONE', 'Europe/Warsaw'),

    /**
     * The format that the application should use when converting dates to strings.
     *
     * This is the format that will be used when converting dates to strings.
     *
     * @var string
     */
    'datetimeFormat' => env('APP_DATETIME_FORMAT', 'd.m.Y H:i'),


    /**
     * The locale that the application should use.
     *
     * This is the locale that will be used by the application.
     *
     * @var string
     */
    'locale' => env('APP_LOCALE', 'pl'), // The locale that the application should use, defaults to 'pl'

    /**
     * The fallback locale of the application.
     *
     * This is the locale that will be used by the application when the requested locale is not available.
     *
     * @var string
     */
    'fallbackLocale' => env('APP_FALLBACK_LOCALE', 'en'), // The fallback locale of the application, defaults to 'en'

    /**
     * The locale that the Faker library should use.
     *
     * This is the locale that will be used by the Faker library.
     *
     * @var string
     */
    'fakerLocale' => env('APP_FAKER_LOCALE', 'pl_PL'), // The locale that the Faker library should use, defaults to 'pl_PL'

    /**
     * Whether the application should force the use of HTTPS.
     *
     * When set to true, the application will always use HTTPS.
     *
     * @var bool
     */
    'forceHttps' => env('APP_FORCE_HTTPS', true), // Whether the application should force the use of HTTPS, defaults to true

    /**
    * The role of the application.
    *
    * website   - the application is a website that serves content to users
    * atpi      - the application is an API that serves data to other atom applications
    * storage   - the application is a storage that serves files to other atom applications
    * database  - the application is a database that serves data to other atom applications
    * livedata  - the application is a livedata that serves real-time data to other atom applications
    * core      - the application is a core that serves as the main application for other applications and controles to ather atom applications
    * node      - the application is a node that serves as a part of a distributed system and can be used for various purposes such as processing data,
    * serving content, update from core to oter atom applications and etc.
    *
    * supported: website
    *
    * @var string
    */
    'atomRole' => env('APP_ATOM_ROLE', 'website'),

    /**
     * Whether to use get_browser() for browser detection.
     * If get_browser is disabled in php.ini return null
     *
     * This requires a properly configured browscap.ini file and the get_browser function to be available.
     *
     * @var bool
     */
    'browserDetector' => env('APP_BROWSER_DETECTOR', true),

    /**
     * Connection information settings.
     *
     * @var array
     */
    'connectionInformation' => [
        /**
         * Whether to enable connection information collection.
         *
         * When set to true, the application will collect information about the user's connection.
         *
         * Defaults to false.
         *
         * @var bool
         */
        'on' => env('APP_CONNECTION_INFORMATION', false),

        /**
         * Whether to attempt to load CrawlerDetect.
         *
         * When set to true, the application will attempt to load CrawlerDetect via Composer
         * and keep an instance in a static property (only once).
         *
         * Defaults to false.
         *
         * @var bool
         */
        'extension' => env('APP_CONNECTION_INFORMATION_EXTENSION', false),
    ],

    /**
     * Bot detection settings.
     *
     * @var array
     */
    'botDetection' => [
        /**
         * Whether to enable bot detection.
         *
         * When set to true, the application will use bot detection.
         *
         * Defaults to false.
         *
         * @var bool
         */
        'on' => env('APP_BOT_DETECTION', false),

        /**
         * Whether to attempt to load CrawlerDetect.
         *
         * When set to true, the application will attempt to load CrawlerDetect via Composer
         * and keep an instance in a static property (only once).
         *
         * Defaults to false.
         *
         * @var bool
         */
        'extension' => env('APP_BOT_DETECTION_EXTENSION', false),
    ],

    /**
     * DOM settings.
     *
     * @var array
     */
    'dom' => [
        /**
         * Whether to enable DOM parsing.
         *
         * When set to true, the application will use the DOM parser to parse HTML.
         *
         * Defaults to false.
         *
         * @var bool
         */
        'on' => env('APP_DOM', false),
        /**
         * Whether to fix syntax errors in the parsed HTML.
         *
         * When set to true, the application will attempt to fix syntax errors in the parsed HTML.
         *
         * Defaults to false.
         *
         * @var bool
         */
        // 'fixSyntax' => env('APP_DOM_FIX_SYNTAX', false),
    ],
];
