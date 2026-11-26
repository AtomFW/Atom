<?php

declare(strict_types=1);

namespace Atom\Component\Task\Driver;

use Closure;
use Redis;

/**
 * Redis queue driver supporting both injected \Redis instances and lazy factory closures
 * for non-blocking initialization in Swoole and unit tests.
 */
final class RedisDriver implements MessageDriverInterface
{
    private ?Redis $redis = null;
    private ?Closure $factory = null;

    /**
     * @param Redis|Closure(): Redis $redisOrFactory
     */
    public function __construct(
        Redis|Closure $redisOrFactory,
        private readonly string $queueName = 'default',
    ) {
        if ($redisOrFactory instanceof Redis) {
            $this->redis = $redisOrFactory;
        } else {
            $this->factory = $redisOrFactory;
        }
    }

    private function client(): Redis
    {
        if ($this->redis === null) {
            $factory = $this->factory;
            if ($factory === null) {
                throw new \RuntimeException('Redis connection factory is not configured.');
            }
            $this->redis = $factory();
        }

        return $this->redis;
    }

    private function listKey(): string
    {
        return "queue:{$this->queueName}:list";
    }

    private function msgKey(string $id): string
    {
        return "queue:{$this->queueName}:msg:{$id}";
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function listKeys(): iterable
    {
        $keys = $this->client()->lRange($this->listKey(), 0, -1);
        return \is_array($keys) ? \array_values(\array_map(\strval(...), $keys)) : [];
    }

    #[\Override]
    public function getMessage(string $key): ?array
    {
        $raw = $this->client()->get($this->msgKey($key));
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = \json_decode($raw, true);
        return \is_array($decoded) ? $decoded : null;
    }

    #[\Override]
    public function saveMessage(array $envelope, string $position = 'append'): string
    {
        $client = $this->client();
        $id = !empty($envelope['id']) ? (string) $envelope['id'] : \bin2hex(\random_bytes(8));
        $envelope['id'] = $id;
        $raw = \json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        $client->set($this->msgKey($id), $raw);
        if ($position === 'prepend') {
            $client->lPush($this->listKey(), $id);
        } else {
            $client->rPush($this->listKey(), $id);
        }

        return $id;
    }

    #[\Override]
    public function remove(string $key): bool
    {
        $client = $this->client();
        $client->del($this->msgKey($key));
        $client->lRem($this->listKey(), $key, 0);
        return true;
    }

    #[\Override]
    public function isOnline(): bool
    {
        try {
            $pong = $this->client()->ping();
            return $pong === true || $pong === '+PONG' || $pong === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }
}
