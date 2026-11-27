<?php

declare(strict_types=1);

namespace Atom\Modules\OAuth;

use Atom\Cache\Contract\RedisAdapterInterface;
use SensitiveParameter;
use Time\Duration;

/**
 * Redis-compatible OAuth state, PKCE authorization code, access token, and refresh token store
 * with bounded local fallback when Redis is disabled or unreachable.
 */
final class OAuthStore
{
    private ?RedisAdapterInterface $redisAdapter;
    private bool $redisEnabled;
    private int $maxLocalEntries;

    /** @var array<string, array{payload: array<string, mixed>, expiresAt: int}> */
    private array $localFallback = [];

    public function __construct(
        ?RedisAdapterInterface $redisAdapter = null,
        bool $redisEnabled = true,
        int $maxLocalEntries = 2048,
    ) {
        $this->redisAdapter = $redisAdapter;
        $this->redisEnabled = $redisEnabled;
        $this->maxLocalEntries = \clamp($maxLocalEntries, 64, 100_000);
    }

    public function setRedisAdapter(?RedisAdapterInterface $adapter, bool $enabled = true): void
    {
        $this->redisAdapter = $adapter;
        $this->redisEnabled = $enabled;
    }

    public function isRedisActive(): bool
    {
        return $this->redisEnabled && $this->redisAdapter !== null && $this->redisAdapter->isAvailable();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function put(string $namespace, #[SensitiveParameter] string $rawTokenOrKey, array $payload, Duration $ttl): void
    {
        $ttlSec = \clamp($ttl->seconds, 1, 86_400 * 365);
        $storageKey = $this->storageKey($namespace, $rawTokenOrKey);
        $encoded = \json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        if ($this->isRedisActive() && $this->redisAdapter !== null) {
            $this->redisAdapter->set($storageKey, $encoded, $ttlSec);
            return;
        }

        if (\count($this->localFallback) >= $this->maxLocalEntries && !isset($this->localFallback[$storageKey])) {
            $oldest = \array_key_first($this->localFallback);
            if (\is_string($oldest)) {
                unset($this->localFallback[$oldest]);
            }
        }

        $this->localFallback[$storageKey] = [
            'payload' => $payload,
            'expiresAt' => \time() + $ttlSec,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $namespace, #[SensitiveParameter] string $rawTokenOrKey, ?int $nowTimestamp = null): ?array
    {
        $storageKey = $this->storageKey($namespace, $rawTokenOrKey);

        if ($this->isRedisActive() && $this->redisAdapter !== null) {
            $raw = $this->redisAdapter->get($storageKey);
            if (!\is_string($raw) || $raw === '') {
                return null;
            }
            $decoded = \json_decode($raw, true);
            return \is_array($decoded) ? $decoded : null;
        }

        if (!isset($this->localFallback[$storageKey])) {
            return null;
        }

        $now = $nowTimestamp ?? \time();
        $entry = $this->localFallback[$storageKey];
        if ($entry['expiresAt'] < $now) {
            unset($this->localFallback[$storageKey]);
            return null;
        }

        return $entry['payload'];
    }

    /**
     * Atomically fetches and deletes single-use artifacts (OAuth `state`, authorization `code`, rotated `refresh_token`).
     *
     * @return array<string, mixed>|null
     */
    public function consume(string $namespace, #[SensitiveParameter] string $rawTokenOrKey, ?int $nowTimestamp = null): ?array
    {
        $storageKey = $this->storageKey($namespace, $rawTokenOrKey);

        if ($this->isRedisActive() && $this->redisAdapter !== null) {
            $raw = $this->redisAdapter->getDel($storageKey);
            if (!\is_string($raw) || $raw === '') {
                return null;
            }
            $decoded = \json_decode($raw, true);
            return \is_array($decoded) ? $decoded : null;
        }

        $found = $this->get($namespace, $rawTokenOrKey, $nowTimestamp);
        unset($this->localFallback[$storageKey]);

        return $found;
    }

    public function delete(string $namespace, #[SensitiveParameter] string $rawTokenOrKey): bool
    {
        $storageKey = $this->storageKey($namespace, $rawTokenOrKey);

        if ($this->isRedisActive() && $this->redisAdapter !== null) {
            return $this->redisAdapter->del($storageKey) > 0;
        }

        $existed = isset($this->localFallback[$storageKey]);
        unset($this->localFallback[$storageKey]);

        return $existed;
    }

    /**
     * Hashes raw tokens with SHA-256 before using as Redis/memory keys so raw bearer secrets
     * are never exposed in Redis `KEYS` scans!
     */
    private function storageKey(string $namespace, #[SensitiveParameter] string $rawTokenOrKey): string
    {
        return 'oauth:' . $namespace . ':' . \hash('sha256', $rawTokenOrKey);
    }
}
