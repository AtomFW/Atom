<?php

declare(strict_types=1);

/**
 * Ultra-Fast Encrypted Inter-Atom Data Exchange configuration template
 */

return [
    /**
     * Master switch enabling the encrypted inter-Atom data exchange module (`Atom\Modules\AtomExchange`).
     *
     * @var bool
     */
    'on' => (bool) env('EXCHANGE_ON', true),

    /**
     * Default transport driver for ultra-fast Atom-to-Atom data exchange.
     *
     * Available drivers: 'redis', 'socket', 'http', 'memory'
     *
     * @var string
     */
    'default' => (string) env('EXCHANGE_DRIVER', 'redis'),

    /**
     * Transport driver configurations.
     *
     * @var array<string, array<string, mixed>>
     */
    'choices' => [
        /**
         * Ultra-fast Redis list/pub-sub binary frame bus.
         *
         * @var array<string, mixed>
         */
        'redis' => [
            'driver' => 'redis',
            'host' => (string) env('EXCHANGE_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
            'port' => (int) env('EXCHANGE_REDIS_PORT', env('REDIS_PORT', 6379)),
            'username' => env('EXCHANGE_REDIS_USERNAME', env('REDIS_USERNAME', null)),
            'password' => env('EXCHANGE_REDIS_PASSWORD', env('REDIS_PASSWORD', null)),
            'database' => (int) env('EXCHANGE_REDIS_DATABASE', env('REDIS_DATABASE', 0)),
            'prefix' => (string) env('EXCHANGE_REDIS_PREFIX', 'atom_xchg:'),
            'timeoutMs' => (int) env('EXCHANGE_REDIS_TIMEOUT_MS', 400),
            'maxInboxSize' => (int) env('EXCHANGE_REDIS_MAX_INBOX', 2048),
        ],

        /**
         * Non-blocking TCP/TLS stream socket transport powered by PHP 8.6 `\Io\Poll\Context`.
         *
         * @var array<string, mixed>
         */
        'socket' => [
            'driver' => 'socket',
            'connectTimeoutMs' => (int) env('EXCHANGE_SOCKET_CONNECT_TIMEOUT_MS', 300),
            'ioTimeoutMs' => (int) env('EXCHANGE_SOCKET_IO_TIMEOUT_MS', 1000),
            'tlsVerifyPeer' => (bool) env('EXCHANGE_SOCKET_TLS_VERIFY', true),
        ],

        /**
         * Stateless HTTP binary envelope transport across Atom HTTP endpoints.
         *
         * @var array<string, mixed>
         */
        'http' => [
            'driver' => 'http',
            'path' => (string) env('EXCHANGE_HTTP_PATH', '/_atom/exchange'),
            'timeoutMs' => (int) env('EXCHANGE_HTTP_TIMEOUT_MS', 1200),
        ],

        /**
         * Bounded in-memory bus for zero-network local & unit test verification.
         *
         * @var array<string, mixed>
         */
        'memory' => [
            'driver' => 'memory',
            'maxMessagesPerNode' => (int) env('EXCHANGE_MEMORY_MAX_MESSAGES', 512),
        ],
    ],

    /**
     * Cryptographic AEAD configuration for inter-Atom binary frames (`ATMX`).
     *
     * @var array<string, mixed>
     */
    'encryption' => [
        /**
         * Enforce authenticated encryption (XChaCha20-Poly1305 IETF with OpenSSL AES-256-GCM fallback).
         *
         * @var bool
         */
        'on' => (bool) env('EXCHANGE_ENCRYPTION_ON', true),

        /**
         * Pre-shared secret key material (derived via HKDF-SHA256).
         *
         * @var string
         */
        'sharedSecret' => (string) env('EXCHANGE_SHARED_SECRET', ''),

        /**
         * Active cryptographic key identifier for seamless rotation.
         *
         * @var string
         */
        'keyId' => (string) env('EXCHANGE_KEY_ID', 'atmx-k1'),

        /**
         * Replay protection window in seconds.
         *
         * @var int
         */
        'replayWindowSeconds' => (int) env('EXCHANGE_REPLAY_WINDOW', 30),

        /**
         * Maximum bounded nonce cache size in replay guard.
         *
         * @var int
         */
        'maxReplayNonces' => (int) env('EXCHANGE_MAX_REPLAY_NONCES', 4096),
    ],

    /**
     * Wire framing, compression, and size limits.
     *
     * @var array<string, mixed>
     */
    'framing' => [
        /**
         * Automatically compress payloads exceeding `compressThresholdBytes` using raw DEFLATE.
         *
         * @var bool
         */
        'compress' => (bool) env('EXCHANGE_COMPRESS', true),

        /**
         * Minimum payload size in bytes before compression is applied.
         *
         * @var int
         */
        'compressThresholdBytes' => (int) env('EXCHANGE_COMPRESS_THRESHOLD', 1024),

        /**
         * Maximum allowed frame size in bytes (defaults to 4 MiB).
         *
         * @var int
         */
        'maxFrameBytes' => (int) env('EXCHANGE_MAX_FRAME_BYTES', 4194304),
    ],

    /**
     * Optional built-in HTTP ingress route registration in `Atom\Router`.
     *
     * @var array<string, mixed>
     */
    'endpoint' => [
        'on' => (bool) env('EXCHANGE_ENDPOINT_ON', false),
        'path' => (string) env('EXCHANGE_HTTP_PATH', '/_atom/exchange'),
    ],
];
