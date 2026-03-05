<?php
declare(strict_types=1);

namespace Atom\Component\Task\Driver;

use Redis;

/**
 * RedisDriver using phpredis extension.
 *
 * Requirements: ext-redis OR adapt to predis/predis client.
 *
 * Simplified implementation:
 * - queue list key: queue:{name}:list
 * - message hash: queue:{name}:msg:{id}
 *
 * NOTE: production implementations should handle atomic ops with LUA scripts
 * for concurrency safety and visibility/timeouts (visibility timeout, ack).
 */
final class RedisDriver implements MessageDriverInterface
{
    private Redis $redis;
    private string $queueName;

    public function __construct(Redis $redis, string $queueName = 'default')
    {
        $this->redis = $redis;
        $this->queueName = $queueName;
    }

    private function listKey(): string { return "queue:{$this->queueName}:list"; }
    private function msgKey(string $id): string { return "queue:{$this->queueName}:msg:{$id}"; }

    public function listKeys(): iterable
    {
        // Return IDs in order (left->right = oldest->newest if we use RPUSH)
        return $this->redis->lRange($this->listKey(), 0, -1) ?: [];
    }

    public function getMessage(string $key): ?array
    {
        $raw = $this->redis->get($this->msgKey($key));
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function saveMessage(array $envelope, string $position = 'append'): string
    {
        // generate id if not set
        $id = $envelope['id'] ?? bin2hex(random_bytes(8));
        $envelope['id'] = $id;
        $raw = json_encode($envelope);
        $this->redis->set($this->msgKey($id), $raw);
        if ($position === 'prepend') {
            $this->redis->lPush($this->listKey(), $id);
        } else {
            $this->redis->rPush($this->listKey(), $id);
        }
        return $id;
    }

    public function remove(string $key): bool
    {
        $this->redis->del([$this->msgKey($key)]);
        // remove from list (non-atomic in this naive implementation)
        $this->redis->lRem($this->listKey(), $key, 0);
        return true;
    }

    public function isOnline(): bool
    {
        try {
            return $this->redis->ping() === '+PONG' || $this->redis->ping() === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }
}