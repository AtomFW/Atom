<?php

declare(strict_types=1);

namespace Atom\Cache;

use Atom\Cache\Contract\RedisAdapterInterface;
use Override;

/**
 * Bounded, TTL-aware in-memory `RedisAdapterInterface` implementation providing 100% Redis command
 * semantics (including `SETNX`, `GETDEL`, hashes, bounded lists, and pub/sub history) for deterministic
 * testing and zero-dependency single-node development.
 */
final class InMemoryRedisAdapter implements RedisAdapterInterface
{
    private const int DEFAULT_MAX_KEYS = 4096;

    /** @var array<string, array{value: string, expiresAt: ?float}> */
    private array $strings = [];

    /** @var array<string, list<string>> */
    private array $lists = [];

    /** @var array<string, array<string, string>> */
    private array $hashes = [];

    /** @var array<string, list<string>> */
    private array $published = [];

    private bool $online;
    private int $maxKeys;

    public function __construct(bool $online = true, int $maxKeys = self::DEFAULT_MAX_KEYS)
    {
        $this->online = $online;
        $this->maxKeys = \clamp($maxKeys, 32, 100_000);
    }

    public function setOnline(bool $online): void
    {
        $this->online = $online;
    }

    /**
     * @return list<string>
     */
    public function getPublishedMessages(string $channel): array
    {
        return $this->published[$channel] ?? [];
    }

    #[Override]
    public function isAvailable(): bool
    {
        return $this->online;
    }

    #[Override]
    public function getDriverName(): string
    {
        return 'memory';
    }

    #[Override]
    public function get(string $key): ?string
    {
        if (!$this->online || !isset($this->strings[$key])) {
            return null;
        }

        $entry = $this->strings[$key];
        if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= \microtime(true)) {
            unset($this->strings[$key]);
            return null;
        }

        return $entry['value'];
    }

    #[Override]
    public function set(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        if (!$this->online) {
            return false;
        }

        if (!isset($this->strings[$key]) && \count($this->strings) >= $this->maxKeys) {
            $oldest = \array_key_first($this->strings);
            if (\is_string($oldest)) {
                unset($this->strings[$oldest]);
            }
        }

        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            unset($this->strings[$key]);
            return true;
        }

        $this->strings[$key] = [
            'value' => $value,
            'expiresAt' => $ttlSeconds !== null ? (\microtime(true) + $ttlSeconds) : null,
        ];

        return true;
    }

    #[Override]
    public function setNx(string $key, string $value, int $ttlSeconds): bool
    {
        if (!$this->online) {
            return false;
        }

        if ($this->get($key) !== null) {
            return false;
        }

        return $this->set($key, $value, \clamp($ttlSeconds, 1, 86_400 * 365));
    }

    #[Override]
    public function getDel(string $key): ?string
    {
        if (!$this->online) {
            return null;
        }

        $val = $this->get($key);
        unset($this->strings[$key]);
        return $val;
    }

    #[Override]
    public function del(string ...$keys): int
    {
        if (!$this->online) {
            return 0;
        }

        $deleted = 0;
        foreach ($keys as $k) {
            if (isset($this->strings[$k]) || isset($this->lists[$k]) || isset($this->hashes[$k])) {
                unset($this->strings[$k], $this->lists[$k], $this->hashes[$k]);
                $deleted++;
            }
        }

        return $deleted;
    }

    #[Override]
    public function incr(string $key, ?int $ttlSeconds = null): int
    {
        if (!$this->online) {
            return 0;
        }

        $current = $this->get($key);
        $next = ($current !== null && \is_numeric($current) ? (int) $current : 0) + 1;
        $existingExp = $this->strings[$key]['expiresAt'] ?? null;

        if ($ttlSeconds !== null) {
            $this->set($key, (string) $next, $ttlSeconds);
        } else {
            $this->strings[$key] = [
                'value' => (string) $next,
                'expiresAt' => $existingExp,
            ];
        }

        return $next;
    }

    #[Override]
    public function rPush(string $key, string $value, int $maxLength = 0): int
    {
        if (!$this->online) {
            return 0;
        }

        $list = $this->lists[$key] ?? [];
        $list[] = $value;

        if ($maxLength > 0 && \count($list) > $maxLength) {
            $list = \array_slice($list, -$maxLength);
        }

        $this->lists[$key] = $list;
        return \count($this->lists[$key]);
    }

    #[Override]
    public function lPop(string $key): ?string
    {
        if (!$this->online || empty($this->lists[$key])) {
            return null;
        }

        $item = \array_shift($this->lists[$key]);
        if ($this->lists[$key] === []) {
            unset($this->lists[$key]);
        }

        return $item;
    }

    #[Override]
    public function lRange(string $key, int $start = 0, int $stop = -1): array
    {
        if (!$this->online || empty($this->lists[$key])) {
            return [];
        }

        $list = $this->lists[$key];
        $len = \count($list);
        $normStart = $start < 0 ? \max(0, $len + $start) : $start;
        $normStop = $stop < 0 ? ($len + $stop) : \min($len - 1, $stop);

        if ($normStart >= $len || $normStop < $normStart) {
            return [];
        }

        return \array_slice($list, $normStart, $normStop - $normStart + 1);
    }

    #[Override]
    public function hSet(string $hashKey, string $field, string $value): bool
    {
        if (!$this->online) {
            return false;
        }

        if (!isset($this->hashes[$hashKey]) && \count($this->hashes) >= $this->maxKeys) {
            $oldest = \array_key_first($this->hashes);
            if (\is_string($oldest)) {
                unset($this->hashes[$oldest]);
            }
        }

        $this->hashes[$hashKey][$field] = $value;
        return true;
    }

    #[Override]
    public function hGet(string $hashKey, string $field): ?string
    {
        if (!$this->online) {
            return null;
        }

        return $this->hashes[$hashKey][$field] ?? null;
    }

    #[Override]
    public function hGetAll(string $hashKey): array
    {
        if (!$this->online) {
            return [];
        }

        return $this->hashes[$hashKey] ?? [];
    }

    #[Override]
    public function hDel(string $hashKey, string ...$fields): int
    {
        if (!$this->online || !isset($this->hashes[$hashKey])) {
            return 0;
        }

        $removed = 0;
        foreach ($fields as $field) {
            if (isset($this->hashes[$hashKey][$field])) {
                unset($this->hashes[$hashKey][$field]);
                $removed++;
            }
        }

        return $removed;
    }

    #[Override]
    public function publish(string $channel, string $payload): int
    {
        if (!$this->online) {
            return 0;
        }

        $history = $this->published[$channel] ?? [];
        $history[] = $payload;
        if (\count($history) > 256) {
            $history = \array_slice($history, -256);
        }
        $this->published[$channel] = $history;

        return 1;
    }
}
