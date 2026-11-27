<?php

declare(strict_types=1);

/**
 * Model Context Protocol (MCP) Server configuration template
 */

return [
    /**
     * Master switch enabling the Atom MCP Server (`Atom\Modules\McpServer`).
     *
     * Disabled by default in production (`false`) so AI agent management endpoints are exposed
     * strictly when explicitly enabled in configuration or environment (`MCP_SERVER_ON=true`).
     *
     * @var bool
     */
    'on' => (bool) env('MCP_SERVER_ON', false),

    /**
     * Default MCP transport driver ('http' for JSON-RPC HTTP endpoint, 'stdio' for CLI agent pipes).
     *
     * @var string
     */
    'default' => (string) env('MCP_TRANSPORT_DRIVER', 'http'),

    /**
     * Available MCP server transport configurations.
     *
     * @var array<string, array<string, mixed>>
     */
    'choices' => [
        /**
         * Streamable HTTP JSON-RPC 2.0 endpoint mounted on `Atom\Router`.
         *
         * @var array<string, mixed>
         */
        'http' => [
            'driver' => 'http',
            'path' => (string) env('MCP_HTTP_PATH', '/_atom/mcp'),
            'maxBatchSize' => (int) env('MCP_HTTP_MAX_BATCH', 32),
            'maxPayloadBytes' => (int) env('MCP_HTTP_MAX_BYTES', 1048576),
        ],

        /**
         * Standard I/O (`STDIN` / `STDOUT`) JSON-RPC transport via `php atom.php mcp:serve`.
         *
         * @var array<string, mixed>
         */
        'stdio' => [
            'driver' => 'stdio',
            'maxLineBytes' => (int) env('MCP_STDIO_MAX_LINE_BYTES', 1048576),
        ],
    ],

    /**
     * MCP protocol and server identification metadata returned during `initialize`.
     *
     * @var array<string, string>
     */
    'serverInfo' => [
        'name' => (string) env('MCP_SERVER_NAME', 'Atom MCP Server'),
        'version' => (string) env('MCP_SERVER_VERSION', '1.0.0'),
        'protocolVersion' => (string) env('MCP_PROTOCOL_VERSION', '2025-03-26'),
    ],

    /**
     * Agent authentication and network access controls.
     *
     * @var array<string, mixed>
     */
    'auth' => [
        /**
         * Require valid Bearer API key or OAuth token for HTTP MCP calls.
         *
         * @var bool
         */
        'on' => (bool) env('MCP_AUTH_ON', true),

        /**
         * Dedicated API key for AI agent authentication (`Authorization: Bearer <key>`).
         *
         * @var string
         */
        'apiKey' => (string) env('MCP_API_KEY', ''),

        /**
         * HTTP header carrying the MCP authorization credential.
         *
         * @var string
         */
        'header' => (string) env('MCP_AUTH_HEADER', 'Authorization'),

        /**
         * Allow valid `OAuth` module Bearer tokens with `mcp` scope to authenticate agents.
         *
         * @var bool
         */
        'allowOAuthBearer' => (bool) env('MCP_ALLOW_OAUTH_BEARER', true),

        /**
         * Comma-separated allowlist of client IPs (`*` allows any authenticated IP).
         *
         * @var string
         */
        'allowedIps' => (string) env('MCP_ALLOWED_IPS', '127.0.0.1,::1'),
    ],

    /**
     * Granular capability permissions governing what actions AI agents may execute inside Atom.
     *
     * @var array<string, mixed>
     */
    'permissions' => [
        /**
         * Allow read-only inspection tools and resources (`atom.status`, `atom.config.get`, `atom.module.list`, etc.).
         *
         * @var bool
         */
        'allowRead' => (bool) env('MCP_ALLOW_READ', true),

        /**
         * Allow state-mutating tools (`atom.data.set`, `atom.cluster.sync`, `atom.module.migrate`).
         *
         * @var bool
         */
        'allowWriteActions' => (bool) env('MCP_ALLOW_WRITE_ACTIONS', false),

        /**
         * Allow agents to execute registered `Atom\Terminal\Terminal` CLI commands.
         *
         * @var bool
         */
        'allowTerminalCommands' => (bool) env('MCP_ALLOW_TERMINAL', false),

        /**
         * Allow agents to invoke public methods on whitelisted `Atom\*` / `App\*` classes.
         *
         * @var bool
         */
        'allowClassInvocation' => (bool) env('MCP_ALLOW_CLASS_INVOCATION', false),

        /**
         * Namespace prefixes allowed when `allowClassInvocation` is enabled.
         *
         * @var list<string>
         */
        'allowedClassPrefixes' => [
            'Atom\\',
            'App\\',
        ],

        /**
         * Sensitive classes strictly blocked from agent invocation regardless of prefix.
         *
         * @var list<string>
         */
        'blockedClasses' => [
            'Atom\\Config\\LoadEnvironmentVariables',
            'Atom\\Config\\EnvironmentVariables',
            'PDO',
        ],
    ],

    /**
     * Sensitive key substrings automatically redacted when agents read configuration or state.
     *
     * @var list<string>
     */
    'redactKeys' => [
        'password',
        'secret',
        'key',
        'token',
        'dsn',
        'private',
        'authorization',
    ],
];
