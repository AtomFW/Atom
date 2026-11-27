<?php

declare(strict_types=1);

/**
 * OAuth 2.0 / OpenID Connect Authorization & Redis Token Store configuration template
 */

return [
    /**
     * Master switch enabling the OAuth 2.0 authorization module (`Atom\Modules\OAuth`).
     *
     * @var bool
     */
    'on' => (bool) env('OAUTH_ON', true),

    /**
     * Default backing store for OAuth states, PKCE challenges, authorization codes, and tokens.
     *
     * When `'redis'` is active and reachable, all ephemeral codes, PKCE states, and stateless
     * access/refresh tokens are persisted in Redis with strict atomic TTL expiration.
     *
     * Available drivers: 'redis', 'database', 'memory'
     *
     * @var string
     */
    'default' => (string) env('OAUTH_STORE_DRIVER', 'redis'),

    /**
     * Token & state storage driver choices.
     *
     * @var array<string, array<string, mixed>>
     */
    'choices' => [
        /**
         * Distributed Redis OAuth token & PKCE state store (recommended for clusters and Swoole).
         *
         * @var array<string, mixed>
         */
        'redis' => [
            'driver' => 'redis',
            'on' => (bool) env('OAUTH_REDIS_ON', true),
            'host' => (string) env('OAUTH_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
            'port' => (int) env('OAUTH_REDIS_PORT', env('REDIS_PORT', 6379)),
            'username' => env('OAUTH_REDIS_USERNAME', env('REDIS_USERNAME', null)),
            'password' => env('OAUTH_REDIS_PASSWORD', env('REDIS_PASSWORD', null)),
            'database' => (int) env('OAUTH_REDIS_DATABASE', env('REDIS_DATABASE', 0)),
            'prefix' => (string) env('OAUTH_REDIS_PREFIX', 'atom_oauth:'),
            'timeoutMs' => (int) env('OAUTH_REDIS_TIMEOUT_MS', 500),
        ],

        /**
         * SQL database OAuth token store (`oa0001`..`oa0003` tables).
         *
         * @var array<string, mixed>
         */
        'database' => [
            'driver' => 'database',
            'tokensTable' => 'oauth_tokens',
            'identitiesTable' => 'oauth_identities',
            'clientsTable' => 'oauth_clients',
        ],

        /**
         * Bounded in-memory OAuth token store (for unit tests and single-worker CLI sandboxes).
         *
         * @var array<string, mixed>
         */
        'memory' => [
            'driver' => 'memory',
            'maxTokens' => (int) env('OAUTH_MEMORY_MAX_TOKENS', 2048),
        ],
    ],

    /**
     * Integration settings with `Atom\Modules\UserAuth` (`Account` & `UserModel`).
     *
     * @var array<string, mixed>
     */
    'userAuth' => [
        /**
         * Synchronize OAuth authentication into `Account::login()` / `$app->session`.
         *
         * @var bool
         */
        'syncSession' => (bool) env('OAUTH_SYNC_SESSION', true),

        /**
         * Automatically hydrate `$app->user` from incoming HTTP `Authorization: Bearer <token>` requests.
         *
         * @var bool
         */
        'hydrateBearerOnRequest' => (bool) env('OAUTH_HYDRATE_BEARER', true),

        /**
         * Allow automatic user account provisioning from verified external OAuth profiles.
         *
         * @var bool
         */
        'autoProvisionUser' => (bool) env('OAUTH_AUTO_PROVISION_USER', false),

        /**
         * HTTP header inspected for Bearer access tokens.
         *
         * @var string
         */
        'authorizationHeader' => (string) env('OAUTH_AUTH_HEADER', 'Authorization'),
    ],

    /**
     * Proof Key for Code Exchange (PKCE, RFC 7636) & CSRF state security settings.
     *
     * @var array<string, mixed>
     */
    'pkce' => [
        'required' => (bool) env('OAUTH_PKCE_REQUIRED', true),
        'allowPlainMethod' => (bool) env('OAUTH_PKCE_ALLOW_PLAIN', false),
        'stateTtlSeconds' => (int) env('OAUTH_STATE_TTL', 600),
        'authCodeTtlSeconds' => (int) env('OAUTH_CODE_TTL', 300),
    ],

    /**
     * Access and refresh token lifetimes and rotation policy.
     *
     * @var array<string, mixed>
     */
    'tokens' => [
        'issuer' => (string) env('OAUTH_ISSUER', env('APP_URL', 'https://localhost')),
        'accessTtlSeconds' => (int) env('OAUTH_ACCESS_TTL', 3600),
        'refreshTtlSeconds' => (int) env('OAUTH_REFRESH_TTL', 2592000),
        'rotateRefreshTokens' => (bool) env('OAUTH_ROTATE_REFRESH', true),
        'signingSecret' => (string) env('OAUTH_SIGNING_SECRET', ''),
    ],

    /**
     * External OAuth2 / OIDC identity providers (GitHub, Google, Discord, Microsoft, Atom SSO).
     *
     * @var array<string, array<string, mixed>>
     */
    'providers' => [
        'github' => [
            'on' => (bool) env('OAUTH_GITHUB_ON', false),
            'clientId' => (string) env('OAUTH_GITHUB_CLIENT_ID', ''),
            'clientSecret' => (string) env('OAUTH_GITHUB_CLIENT_SECRET', ''),
            'authorizeUrl' => 'https://github.com/login/oauth/authorize',
            'tokenUrl' => 'https://github.com/login/oauth/access_token',
            'userInfoUrl' => 'https://api.github.com/user',
            'redirectUri' => (string) env('OAUTH_GITHUB_REDIRECT_URI', ''),
            'scopes' => ['read:user', 'user:email'],
        ],
        'google' => [
            'on' => (bool) env('OAUTH_GOOGLE_ON', false),
            'clientId' => (string) env('OAUTH_GOOGLE_CLIENT_ID', ''),
            'clientSecret' => (string) env('OAUTH_GOOGLE_CLIENT_SECRET', ''),
            'authorizeUrl' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'tokenUrl' => 'https://oauth2.googleapis.com/token',
            'userInfoUrl' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'redirectUri' => (string) env('OAUTH_GOOGLE_REDIRECT_URI', ''),
            'scopes' => ['openid', 'profile', 'email'],
        ],
        'discord' => [
            'on' => (bool) env('OAUTH_DISCORD_ON', false),
            'clientId' => (string) env('OAUTH_DISCORD_CLIENT_ID', ''),
            'clientSecret' => (string) env('OAUTH_DISCORD_CLIENT_SECRET', ''),
            'authorizeUrl' => 'https://discord.com/api/oauth2/authorize',
            'tokenUrl' => 'https://discord.com/api/oauth2/token',
            'userInfoUrl' => 'https://discord.com/api/users/@me',
            'redirectUri' => (string) env('OAUTH_DISCORD_REDIRECT_URI', ''),
            'scopes' => ['identify', 'email'],
        ],
    ],
];
