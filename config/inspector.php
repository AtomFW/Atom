<?php

declare(strict_types=1);

/**
 * Visual HTTP Request & Response Traffic Inspector configuration template (`Atom\Modules\TrafficInspector`)
 */

return [
    /**
     * Master switch enabling deep visual request/response origin & file-route tracing.
     *
     * Disabled by default (`false`) on production so `Atom::$probe` remains completely untouched (`null`).
     *
     * @var bool
     */
    'on' => (bool) env('TRAFFIC_INSPECTOR_ON', false),

    /**
     * Default inspector storage/view driver.
     *
     * @var string
     */
    'default' => (string) env('TRAFFIC_INSPECTOR_DRIVER', 'memory'),

    /**
     * Storage driver choices.
     *
     * @var array<string, array<string, mixed>>
     */
    'choices' => [
        /**
         * Bounded in-memory ring buffer of captured request/response traces.
         *
         * @var array<string, mixed>
         */
        'memory' => [
            'driver' => 'memory',
            'maxTraces' => (int) env('TRAFFIC_INSPECTOR_MAX_TRACES', 50),
        ],

        /**
         * JSON trace snapshot storage inside `runtime/log/traffic/`.
         *
         * @var array<string, mixed>
         */
        'file' => [
            'driver' => 'file',
            'path' => (string) env('TRAFFIC_INSPECTOR_PATH', __DIR__ . '/../runtime/log/traffic'),
            'maxTraces' => (int) env('TRAFFIC_INSPECTOR_MAX_TRACES', 50),
        ],
    ],

    /**
     * Capture & security redaction rules.
     *
     * @var array<string, mixed>
     */
    'capture' => [
        /**
         * Track newly included PHP files (`get_included_files()` delta) along the request route.
         *
         * @var bool
         */
        'trackIncludedFiles' => (bool) env('TRAFFIC_INSPECTOR_TRACK_FILES', true),

        /**
         * Maximum response/request body preview length in bytes stored per trace.
         *
         * @var int
         */
        'maxBodyPreviewBytes' => (int) env('TRAFFIC_INSPECTOR_MAX_BODY_PREVIEW', 4096),

        /**
         * Expose interactive visual HTTP dashboard route (`/_atom/traffic`).
         *
         * @var bool
         */
        'exposeHttpDashboard' => (bool) env('TRAFFIC_INSPECTOR_DASHBOARD', true),

        /**
         * Route path for the visual Traffic Inspector UI.
         *
         * @var string
         */
        'dashboardPath' => (string) env('TRAFFIC_INSPECTOR_DASHBOARD_PATH', '/_atom/traffic'),

        /**
         * Sensitive key fragments redacted automatically from headers, cookies, query, and POST/JSON payloads.
         *
         * @var list<string>
         */
        'redactKeys' => [
            'password',
            'passwd',
            'secret',
            'token',
            'api_key',
            'apikey',
            'authorization',
            'cookie',
            'session',
            'sessid',
            'private_key',
            'card',
            'cvv',
        ],
    ],
];
