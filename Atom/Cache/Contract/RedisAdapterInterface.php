<?php

declare(strict_types=1);

namespace Atom\Cache\Contract;

/**
 * Unified, Swoole-safe Redis client contract used across `AtomCluster`, `AtomExchange`, and `OAuth`.
 * Supports native `ext-redis`, PHP 8.6 `\Io\Poll\Context` non-blocking RESP sockets, and in-memory adapters.
 */
interface RedisAdapterInterface
{
    /**
     * Checks whether the Redis backend is enabled and reachable.
     */
    public function isAvailable(): bool;

    /**
     * Returns the backend driver identifier (`'ext-redis'`, `'resp-socket'`, `'memory'`).
     */
    public function getDriverName(): string;

    public function get(string $key): ?string;

    public function set(string $key, string $value, ?int $ttlSeconds = null): bool;

    /**
     * Atomic SET if Not eXists with mandatory TTL (used for distributed Supervisor leases and locks).
     */
    public function setNx(string $key, string $value, int $ttlSeconds): bool;

    /**
     * Atomically fetches and deletes a key (used for single-use OAuth authorization codes and nonces).
     */
    public function getDel(string $key): ?string;

    public function del(string ...$keys): int;

    public function incr(string $key, ?int $ttlSeconds = null): int;

    public function rPush(string $key, string $value, int $maxLength = 0): int;

    public function lPop(string $key): ?string;

    /**
     * @return list<string>
     */
    public function lRange(string $key, int $start = 0, int $stop = -1): array;

    public function hSet(string $hashKey, string $field, string $value): bool;

    public function hGet(string $hashKey, string $field): ?string;

    /**
     * @return array<string, string>
     */
    public function hGetAll(string $hashKey): array;

    public function hDel(string $hashKey, string ...$fields): int;

    public function publish(string $channel, string $payload): int;
}
