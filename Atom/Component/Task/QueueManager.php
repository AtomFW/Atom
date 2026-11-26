<?php

declare(strict_types=1);

namespace Atom\Component\Task;

use Atom\Component\Task\Driver\DoctrineDriver;
use Atom\Component\Task\Driver\MessageDriverInterface;
use Atom\Component\Task\Driver\RedisDriver;
use Closure;
use SensitiveParameter;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Uri\Rfc3986\Uri;

/**
 * Stateless-friendly task queue manager with lazy driver connections,
 * RFC 3986 DSN parsing, and exponential backoff retry handling.
 */
final class QueueManager
{
    private QueueConfig $config;

    /** @var array<string, MessageDriverInterface> */
    private array $drivers = [];

    /** @var array<string, list<callable>> */
    private array $handlers = [];

    private Serializer $serializer;

    /** @var (Closure(\Throwable): void)|null */
    private ?Closure $onFailure;

    /**
     * @param QueueConfig|array<string, mixed> $cfg
     * @param array<string, array<int, callable>|callable> $handlers
     * @param (callable(\Throwable): void)|null $onFailure
     */
    public function __construct(#[SensitiveParameter] QueueConfig|array $cfg, array $handlers = [], ?callable $onFailure = null)
    {
        $this->config = $cfg instanceof QueueConfig ? $cfg : new QueueConfig(...$cfg);
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
        $this->onFailure = $onFailure !== null ? $onFailure(...) : null;

        foreach ($handlers as $msgClass => $handlerList) {
            $this->handlers[$msgClass] = \is_array($handlerList) ? \array_values($handlerList) : [$handlerList];
        }

        foreach ($this->config->drivers as $name => $info) {
            $this->drivers[$name] = $this->createDriverFromDsn($info['dsn'], $info['options'] ?? []);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createDriverFromDsn(#[SensitiveParameter] string $dsn, #[SensitiveParameter] array $options = []): MessageDriverInterface
    {
        $lower = \strtolower($dsn);
        if (\str_starts_with($lower, 'redis://') || \str_starts_with($lower, 'rediss://')) {
            $uri = new Uri($dsn);
            $host = $uri->getHost() ?? '127.0.0.1';
            $port = $uri->getPort() ?? 6379;
            $path = \trim($uri->getPath(), '/');
            $userInfo = $uri->getUserInfo();
            $password = null;
            if (\is_string($userInfo) && $userInfo !== '') {
                $parts = \explode(':', $userInfo, 2);
                $password = $parts[1] ?? $parts[0];
            }

            $tls = \str_starts_with($lower, 'rediss://');
            $timeout = isset($options['timeout']) ? (float) $options['timeout'] : 2.5;
            $queueName = $path !== '' ? $path : (string) ($options['queue'] ?? 'default');

            if (!empty($options['redis']) && $options['redis'] instanceof \Redis) {
                return new RedisDriver($options['redis'], $queueName);
            }

            $factory = static function () use ($host, $port, $password, $tls, $timeout): \Redis {
                $redis = new \Redis();
                $targetHost = $tls ? 'tls://' . $host : $host;
                $redis->connect($targetHost, $port, $timeout);
                if ($password !== null && $password !== '') {
                    $redis->auth($password);
                }
                return $redis;
            };

            return new RedisDriver($factory, $queueName);
        }

        if (\str_starts_with($lower, 'doctrine://') || \str_starts_with($lower, 'mysql://')) {
            if (empty($options['pdo']) || !($options['pdo'] instanceof \PDO)) {
                throw new \InvalidArgumentException(
                    'Doctrine driver requires a PDO instance passed in options["pdo"] when used outside of Symfony.',
                );
            }

            return new DoctrineDriver($options['pdo'], (string) ($options['table'] ?? 'queue_messages'));
        }

        throw new \InvalidArgumentException('Unsupported DSN: ' . $dsn);
    }

    public function getDriver(string $name): MessageDriverInterface
    {
        if (!isset($this->drivers[$name])) {
            throw new \RuntimeException("Driver {$name} not configured.");
        }

        return $this->drivers[$name];
    }

    public function dispatch(TaskInterface $task, string $driver = 'default', bool $prepend = false): string
    {
        $driverObj = $this->getDriver($driver);
        $nowMs = (int) (\microtime(true) * 1000);

        $envelope = [
            'id' => null,
            'class' => $task::class,
            'body' => $this->serializer->normalize($task),
            'attempts' => 0,
            'available_at' => $nowMs,
            'created_at' => $nowMs,
            'meta' => [],
        ];

        return $driverObj->saveMessage($envelope, $prepend ? 'prepend' : 'append');
    }

    /**
     * @param (callable(array<string, mixed>): array<string, mixed>)|null $transform
     * @return array<string, string>
     */
    public function transferAll(
        string $from,
        string $to,
        bool $prepend = false,
        ?callable $transform = null,
    ): array {
        $src = $this->getDriver($from);
        $dst = $this->getDriver($to);
        $map = [];

        foreach ($src->listKeys() as $id) {
            $env = $src->getMessage($id);
            if ($env === null) {
                continue;
            }
            $newEnv = $transform !== null ? $transform($env) : $env;
            $newEnv['id'] = null;
            $newId = $dst->saveMessage($newEnv, $prepend ? 'prepend' : 'append');
            $src->remove($id);
            $map[$id] = $newId;
        }

        return $map;
    }

    public function count(string $driver = 'default'): int
    {
        $keys = $this->getDriver($driver)->listKeys();
        if (\is_array($keys)) {
            return \count($keys);
        }

        $n = 0;
        foreach ($keys as $_) {
            $n++;
        }

        return $n;
    }

    public function isDriverOnline(string $driver = 'default'): bool
    {
        return $this->getDriver($driver)->isOnline();
    }

    /**
     * @param array{sleep_ms?: int, limit?: int} $opts
     */
    public function runWorker(string $driver = 'default', array $opts = []): void
    {
        $sleepMs = \clamp((int) ($opts['sleep_ms'] ?? 200), 0, 60_000);
        $limit = isset($opts['limit']) ? (int) $opts['limit'] : null;
        $processed = 0;
        $driverObj = $this->getDriver($driver);

        while (true) {
            $inspectedInCycle = 0;

            foreach ($driverObj->listKeys() as $id) {
                $inspectedInCycle++;
                $env = $driverObj->getMessage($id);
                if ($env === null) {
                    continue;
                }

                $nowMs = (int) (\microtime(true) * 1000);
                if (($env['available_at'] ?? 0) > $nowMs) {
                    if ($limit !== null && $processed + $inspectedInCycle >= $limit) {
                        return;
                    }
                    continue;
                }

                $class = (string) $env['class'];
                $body = $env['body'] ?? [];
                $task = $this->serializer->denormalize($body, $class);

                try {
                    foreach ($this->handlers[$class] ?? [] as $handler) {
                        $handler($task);
                    }
                    $driverObj->remove($id);
                } catch (\Throwable $e) {
                    $env['attempts'] = (int) ($env['attempts'] ?? 0) + 1;
                    $rp = $this->config->retryPolicy;
                    if ($env['attempts'] > $rp['max_retries']) {
                        $driverObj->remove($id);
                        if ($this->onFailure !== null) {
                            ($this->onFailure)(
                                new \RuntimeException("Message {$id} failed permanently: " . $e->getMessage(), 0, $e),
                            );
                        }
                    } else {
                        $delayMs = (int) ($rp['delay_ms'] * ($rp['multiplier'] ** ($env['attempts'] - 1)));
                        $env['available_at'] = $nowMs + $delayMs;
                        $driverObj->remove($id);
                        $env['id'] = null;
                        $driverObj->saveMessage($env, 'append');
                    }
                }

                $processed++;
                if ($limit !== null && $processed >= $limit) {
                    return;
                }
            }

            $this->processScheduler($driver);

            if ($limit !== null && $inspectedInCycle === 0) {
                return;
            }

            if ($sleepMs > 0) {
                \usleep($sleepMs * 1000);
            }
        }
    }

    public function processScheduler(string $driver = 'default'): void
    {
        if ($this->config->scheduler === []) {
            return;
        }

        foreach ($this->config->scheduler as $entry) {
            $msgClass = $entry['message_class'];
            $expr = $entry['expression'];
            $args = $entry['options']['constructor_args'] ?? [];
            $message = new $msgClass(...$args);
            $recurringClass = \sprintf('%s\\%s', 'Symfony\\Component\\Scheduler', 'RecurringMessage');
            if (\class_exists($recurringClass) && \is_callable([$recurringClass, 'every'])) {
                ($recurringClass . '::every')($expr, $message);
            }
            if ($message instanceof TaskInterface) {
                $this->dispatch($message, $driver);
            }
        }
    }
}
