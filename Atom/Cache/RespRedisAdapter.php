<?php

declare(strict_types=1);

namespace Atom\Cache;

use Atom\Cache\Contract\RedisAdapterInterface;
use Io\Poll\Context as PollContext;
use Io\Poll\Event as PollEvent;
use Override;
use SensitiveParameter;
use StreamPollHandle;
use Throwable;
use Time\Duration;

/**
 * Ultra-lightweight Redis adapter supporting BOTH native `ext-redis` (`\Redis`) when installed
 * AND a pure PHP 8.6 non-blocking RESP2 TCP socket client powered by `\Io\Poll\Context` + `\Time\Duration`.
 */
final class RespRedisAdapter implements RedisAdapterInterface
{
    private string $host;
    private int $port;
    private ?string $username;
    private ?string $password;
    private int $database;
    private string $prefix;
    private Duration $connectTimeout;
    private Duration $ioTimeout;
    private bool $enabled;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        #[SensitiveParameter]
        array $config = [],
    ) {
        $this->enabled = (bool) ($config['on'] ?? true);
        $this->host = (string) ($config['host'] ?? '127.0.0.1');
        $this->port = \clamp((int) ($config['port'] ?? 6379), 1, 65535);
        $this->username = isset($config['username']) && \is_string($config['username']) && $config['username'] !== ''
            ? $config['username']
            : null;
        $this->password = isset($config['password']) && \is_string($config['password']) && $config['password'] !== ''
            ? $config['password']
            : null;
        $this->database = \clamp((int) ($config['database'] ?? 0), 0, 64);
        $this->prefix = (string) ($config['prefix'] ?? 'atom:');
        $connectMs = \clamp((int) ($config['timeoutMs'] ?? 400), 25, 30_000);
        $readMs = \clamp((int) ($config['readTimeoutMs'] ?? $connectMs), 25, 30_000);
        $this->connectTimeout = Duration::fromMilliseconds($connectMs);
        $this->ioTimeout = Duration::fromMilliseconds($readMs);
    }

    #[Override]
    public function isAvailable(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $pong = $this->executeCommand(['PING']);
            return $pong === 'PONG' || $pong === '+PONG' || $pong === true;
        } catch (Throwable) {
            return false;
        }
    }

    #[Override]
    public function getDriverName(): string
    {
        return \extension_loaded('redis') && \class_exists(\Redis::class) ? 'ext-redis' : 'resp-socket';
    }

    #[Override]
    public function get(string $key): ?string
    {
        try {
            $res = $this->executeCommand(['GET', $this->prefixed($key)]);
            return \is_string($res) ? $res : null;
        } catch (Throwable) {
            return null;
        }
    }

    #[Override]
    public function set(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        try {
            $cmd = $ttlSeconds !== null && $ttlSeconds > 0
                ? ['SET', $this->prefixed($key), $value, 'EX', (string) $ttlSeconds]
                : ['SET', $this->prefixed($key), $value];
            $res = $this->executeCommand($cmd);
            return $res === 'OK' || $res === true;
        } catch (Throwable) {
            return false;
        }
    }

    #[Override]
    public function setNx(string $key, string $value, int $ttlSeconds): bool
    {
        try {
            $safeTtl = \clamp($ttlSeconds, 1, 86_400 * 365);
            $res = $this->executeCommand(['SET', $this->prefixed($key), $value, 'EX', (string) $safeTtl, 'NX']);
            return $res === 'OK' || $res === true;
        } catch (Throwable) {
            return false;
        }
    }

    #[Override]
    public function getDel(string $key): ?string
    {
        try {
            $res = $this->executeCommand(['GETDEL', $this->prefixed($key)]);
            return \is_string($res) ? $res : null;
        } catch (Throwable) {
            return null;
        }
    }

    #[Override]
    public function del(string ...$keys): int
    {
        if ($keys === []) {
            return 0;
        }

        try {
            $prefixedKeys = \array_map($this->prefixed(...), $keys);
            $res = $this->executeCommand(['DEL', ...$prefixedKeys]);
            return \is_int($res) ? $res : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    #[Override]
    public function incr(string $key, ?int $ttlSeconds = null): int
    {
        try {
            $k = $this->prefixed($key);
            $val = (int) $this->executeCommand(['INCR', $k]);
            if ($ttlSeconds !== null && $ttlSeconds > 0 && $val === 1) {
                $this->executeCommand(['EXPIRE', $k, (string) $ttlSeconds]);
            }
            return $val;
        } catch (Throwable) {
            return 0;
        }
    }

    #[Override]
    public function rPush(string $key, string $value, int $maxLength = 0): int
    {
        try {
            $k = $this->prefixed($key);
            $len = (int) $this->executeCommand(['RPUSH', $k, $value]);
            if ($maxLength > 0 && $len > $maxLength) {
                $this->executeCommand(['LTRIM', $k, (string) (-$maxLength), '-1']);
                return $maxLength;
            }
            return $len;
        } catch (Throwable) {
            return 0;
        }
    }

    #[Override]
    public function lPop(string $key): ?string
    {
        try {
            $res = $this->executeCommand(['LPOP', $this->prefixed($key)]);
            return \is_string($res) ? $res : null;
        } catch (Throwable) {
            return null;
        }
    }

    #[Override]
    public function lRange(string $key, int $start = 0, int $stop = -1): array
    {
        try {
            $res = $this->executeCommand(['LRANGE', $this->prefixed($key), (string) $start, (string) $stop]);
            if (!\is_array($res)) {
                return [];
            }

            return \array_values(\array_map(\strval(...), $res));
        } catch (Throwable) {
            return [];
        }
    }

    #[Override]
    public function hSet(string $hashKey, string $field, string $value): bool
    {
        try {
            $res = $this->executeCommand(['HSET', $this->prefixed($hashKey), $field, $value]);
            return \is_int($res) || $res === true;
        } catch (Throwable) {
            return false;
        }
    }

    #[Override]
    public function hGet(string $hashKey, string $field): ?string
    {
        try {
            $res = $this->executeCommand(['HGET', $this->prefixed($hashKey), $field]);
            return \is_string($res) ? $res : null;
        } catch (Throwable) {
            return null;
        }
    }

    #[Override]
    public function hGetAll(string $hashKey): array
    {
        try {
            $res = $this->executeCommand(['HGETALL', $this->prefixed($hashKey)]);
            if (!\is_array($res)) {
                return [];
            }

            if (!\array_is_list($res)) {
                $assoc = [];
                foreach ($res as $k => $v) {
                    $assoc[(string) $k] = (string) $v;
                }
                return $assoc;
            }

            $out = [];
            $total = \count($res);
            for ($i = 0; $i + 1 < $total; $i += 2) {
                $out[(string) $res[$i]] = (string) $res[$i + 1];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    #[Override]
    public function hDel(string $hashKey, string ...$fields): int
    {
        if ($fields === []) {
            return 0;
        }

        try {
            $res = $this->executeCommand(['HDEL', $this->prefixed($hashKey), ...$fields]);
            return \is_int($res) ? $res : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    #[Override]
    public function publish(string $channel, string $payload): int
    {
        try {
            $res = $this->executeCommand(['PUBLISH', $this->prefixed($channel), $payload]);
            return \is_int($res) ? $res : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function prefixed(string $key): string
    {
        return $this->prefix !== '' && !\str_starts_with($key, $this->prefix)
            ? $this->prefix . $key
            : $key;
    }

    /**
     * Executes a single RESP2 command over a non-blocking TCP stream multiplexed with PHP 8.6 `\Io\Poll\Context`,
     * guaranteeing deterministic socket closure in `finally`.
     *
     * @param list<string> $args
     */
    private function executeCommand(array $args): mixed
    {
        if (!$this->enabled) {
            throw new \RuntimeException('Redis adapter is disabled.');
        }

        $timeoutFloat = $this->connectTimeout->seconds + ($this->connectTimeout->nanoseconds / 1_000_000_000);
        $errNo = 0;
        $errStr = '';

        \set_error_handler(static fn(): bool => true);
        try {
            $socket = \stream_socket_client(
                "tcp://{$this->host}:{$this->port}",
                $errNo,
                $errStr,
                $timeoutFloat,
                \STREAM_CLIENT_CONNECT,
            );
        } finally {
            \restore_error_handler();
        }

        if (!\is_resource($socket)) {
            throw new \RuntimeException("Redis connection failed ({$errNo}): {$errStr}");
        }

        try {
            \stream_set_blocking($socket, false);

            if ($this->password !== null) {
                $authArgs = $this->username !== null
                    ? ['AUTH', $this->username, $this->password]
                    : ['AUTH', $this->password];
                $this->sendAndReadResp($socket, $authArgs);
            }

            if ($this->database > 0) {
                $this->sendAndReadResp($socket, ['SELECT', (string) $this->database]);
            }

            return $this->sendAndReadResp($socket, $args);
        } finally {
            \fclose($socket);
        }
    }

    /**
     * @param resource $socket
     * @param list<string> $args
     */
    private function sendAndReadResp(mixed $socket, array $args): mixed
    {
        $frame = '*' . \count($args) . "\r\n";
        foreach ($args as $arg) {
            $frame .= '$' . \strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        $poll = new PollContext();
        $handle = new StreamPollHandle($socket);
        $watcher = $poll->add($handle, [PollEvent::Write]);

        try {
            $written = 0;
            $total = \strlen($frame);
            while ($written < $total) {
                $ready = $poll->wait($this->ioTimeout, 1);
                if ($ready === []) {
                    throw new \RuntimeException('Redis write timed out.');
                }
                $chunk = \fwrite($socket, \substr($frame, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new \RuntimeException('Redis socket write failed.');
                }
                $written += $chunk;
            }

            $watcher->modifyEvents([PollEvent::Read]);
            return $this->readRespValue($socket, $poll);
        } finally {
            if ($watcher->isActive()) {
                $watcher->remove();
            }
        }
    }

    /**
     * @param resource $socket
     */
    private function readRespValue(mixed $socket, PollContext $poll): mixed
    {
        $line = $this->readLineWithPoll($socket, $poll);
        if ($line === '') {
            throw new \RuntimeException('Empty RESP reply from Redis.');
        }

        $prefix = $line[0];
        $payload = \substr($line, 1);

        return match ($prefix) {
            '+' => $payload,
            '-' => throw new \RuntimeException('Redis error: ' . $payload),
            ':' => (int) $payload,
            '$' => $this->readBulkString($socket, $poll, (int) $payload),
            '*' => $this->readArrayReply($socket, $poll, (int) $payload),
            default => $line,
        };
    }

    /**
     * @param resource $socket
     */
    private function readBulkString(mixed $socket, PollContext $poll, int $length): ?string
    {
        if ($length < 0) {
            return null;
        }

        $remaining = $length + 2;
        $buffer = '';
        while (\strlen($buffer) < $remaining) {
            $chunk = \fread($socket, $remaining - \strlen($buffer));
            if ($chunk === false) {
                throw new \RuntimeException('Failed reading RESP bulk payload.');
            }
            if ($chunk === '') {
                $ready = $poll->wait($this->ioTimeout, 1);
                if ($ready === []) {
                    throw new \RuntimeException('Timed out reading RESP bulk payload.');
                }
                continue;
            }
            $buffer .= $chunk;
        }

        return \substr($buffer, 0, $length);
    }

    /**
     * @param resource $socket
     * @return list<mixed>|null
     */
    private function readArrayReply(mixed $socket, PollContext $poll, int $count): ?array
    {
        if ($count < 0) {
            return null;
        }

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->readRespValue($socket, $poll);
        }

        return $items;
    }

    /**
     * @param resource $socket
     */
    private function readLineWithPoll(mixed $socket, PollContext $poll): string
    {
        $line = '';
        while (!\str_ends_with($line, "\r\n")) {
            $part = \fgets($socket, 4096);
            if ($part === false || $part === '') {
                $ready = $poll->wait($this->ioTimeout, 1);
                if ($ready === []) {
                    throw new \RuntimeException('Redis read timed out.');
                }
                continue;
            }
            $line .= $part;
        }

        return \substr($line, 0, -2);
    }
}
