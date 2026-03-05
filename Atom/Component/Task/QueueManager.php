<?php

declare(strict_types=1);

namespace Atom\Component\Task;

use Atom\Component\Task\QueueConfig;
use Atom\Component\Task\Driver\MessageDriverInterface;
use Atom\Component\Task\Driver\RedisDriver;
use Atom\Component\Task\Driver\DoctrineDriver;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Main QueueManager.
 *
 * - Build bus from provided handlers (simple auto-register provided handlers).
 * - Manage drivers.
 * - Dispatch tasks (store to driver).
 * - runWorker() polls driver and dispatches to bus; handles retry with exponential backoff.
 */
final class QueueManager
{
    private QueueConfig $config;
    /** @var array<string, MessageDriverInterface> */
    private array $drivers = [];
    private MessageBusInterface $bus;
    private SerializerInterface $serializer;
    private EventDispatcher $dispatcher;

    /**
     * @param QueueConfig|array $cfg
     * @param array<string,callable> $handlers Map of message class => handler callable
     */
    public function __construct(QueueConfig|array $cfg, array $handlers = [])
    {
        $this->config = $cfg instanceof QueueConfig ? $cfg : new QueueConfig(...$cfg);
        // serializer setup (simple)
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        // create handlers locator
        $locator = new HandlersLocator($handlers);
        $this->bus = new MessageBus([new HandleMessageMiddleware($locator)]);
        $this->dispatcher = new EventDispatcher();

        // lazy driver creation from DSNs
        foreach ($this->config->drivers as $name => $info) {
            $this->drivers[$name] = $this->createDriverFromDsn($info['dsn'], $info['options'] ?? []);
        }
    }

    /**
     * Create driver based on DSN. Supported: redis://..., doctrine:// or mysql://
     */
    private function createDriverFromDsn(string $dsn, array $options = []): MessageDriverInterface
    {
        $lower = strtolower($dsn);
        if (str_starts_with($lower, 'redis://') || str_starts_with($lower, 'rediss://')) {
            // Expect: redis://host:6379/queue_name
            // User must provide a Redis client or ext-redis; we'll accept options['redis'] as instance
            if (!empty($options['redis']) && $options['redis'] instanceof \Redis) {
                $redis = $options['redis'];
            } else {
                // naive connect
                $parts = parse_url($dsn);
                $host = $parts['host'] ?? '127.0.0.1';
                $port = $parts['port'] ?? 6379;
                $redis = new \Redis();
                $redis->connect($host, (int)$port);
                if (isset($parts['pass'])) {
                    $redis->auth($parts['pass']);
                }
            }
            $queueName = trim($parts['path'] ?? '', '/');
            $queueName = $queueName ?: ($options['queue'] ?? 'default');
            return new RedisDriver($redis, $queueName);
        }

        if (str_starts_with($lower, 'doctrine://') || str_starts_with($lower, 'mysql://')) {
            // Expect DSN in PDO format: mysql:host=...;dbname=... and options['pdo'] or build from DSN
            if (!empty($options['pdo']) && $options['pdo'] instanceof \PDO) {
                $pdo = $options['pdo'];
            } else {
                // transform common DSN forms into PDO
                // if DSN starts with doctrine://, user must supply 'pdo' in options
                throw new \InvalidArgumentException('Doctrine driver requires a PDO instance passed in options["pdo"] when used outside of Symfony.');
            }
            return new DoctrineDriver($pdo, $options['table'] ?? 'queue_messages');
        }

        // fallback: in-memory driver (not implemented here) - throw
        throw new \InvalidArgumentException('Unsupported DSN: ' . $dsn);
    }

    public function getDriver(string $name): MessageDriverInterface
    {
        if (!isset($this->drivers[$name])) {
            throw new \RuntimeException("Driver {$name} not configured.");
        }
        return $this->drivers[$name];
    }

    /**
     * Dispatch a task into given driver (store it).
     */
    public function dispatch(TaskInterface $task, string $driver = 'default', bool $prepend = false): string
    {
        $driverObj = $this->getDriver($driver);

        $nowMs = (int) (microtime(true) * 1000);
        $envelope = [
            'id' => null,
            'class' => get_class($task),
            'body' => $this->serializer->normalize($task),
            'attempts' => 0,
            'available_at' => $nowMs,
            'created_at' => $nowMs,
            'meta' => [],
        ];
        return $driverObj->saveMessage($envelope, $prepend ? 'prepend' : 'append');
    }

    /**
     * Transfer all messages between drivers (with optional transform callback).
     *
     * @param string $from
     * @param string $to
     * @param bool $prepend
     * @param callable|null $transform (array $envelope): array
     * @return array<string,string> map oldId => newId
     */
    public function transferAll(string $from, string $to, bool $prepend = false, ?callable $transform = null): array
    {
        $src = $this->getDriver($from);
        $dst = $this->getDriver($to);
        $map = [];
        foreach ($src->listKeys() as $id) {
            $env = $src->getMessage($id);
            if (!$env) continue;
            $newEnv = $transform ? $transform($env) : $env;
            $newId = $dst->saveMessage($newEnv, $prepend ? 'prepend' : 'append');
            $map[$id] = $newId;
        }
        return $map;
    }

    /**
     * Count messages in driver
     */
    public function count(string $driver = 'default'): int
    {
        $n = 0;
        foreach ($this->getDriver($driver)->listKeys() as $_) $n++;
        return $n;
    }

    public function isDriverOnline(string $driver = 'default'): bool
    {
        return $this->getDriver($driver)->isOnline();
    }

    /**
     * Run worker loop which:
     * - polls the given driver
     * - skips messages whose available_at > now (delayed)
     * - dispatches to the MessageBus
     * - on exception applies retry policy and requeues with exponential backoff
     *
     * This is a simple single-process worker. Use Supervisor/systemd to run many processes.
     *
     * @param string $driver
     * @param array{sleep_ms?:int, limit?:int} $opts
     */
    public function runWorker(string $driver = 'default', array $opts = []): void
    {
        $sleepMs = $opts['sleep_ms'] ?? 200;
        $limit = $opts['limit'] ?? null;
        $processed = 0;
        $driverObj = $this->getDriver($driver);

        while (true) {
            foreach ($driverObj->listKeys() as $id) {
                $env = $driverObj->getMessage($id);
                if (!$env) {
                    // message removed concurrently
                    continue;
                }

                $nowMs = (int) (microtime(true) * 1000);
                if (($env['available_at'] ?? 0) > $nowMs) {
                    // message not yet available (delayed)
                    continue;
                }

                $class = $env['class'];
                $body = $env['body'] ?? [];
                // rehydrate object (naive)
                $task = $this->serializer->denormalize($body, $class);
                try {
                    // dispatch to local bus (synchronous handling)
                    $this->bus->dispatch($task);
                    // on success remove from queue
                    $driverObj->remove($id);
                } catch (\Throwable $e) {
                    // handle retry
                    $env['attempts'] = ($env['attempts'] ?? 0) + 1;
                    $rp = $this->config->retryPolicy;
                    if ($env['attempts'] > $rp['max_retries']) {
                        // give up: move to dead-letter or remove (here: remove)
                        $driverObj->remove($id);
                        // log it (user can attach event listener to dispatcher)
                        $this->dispatcher->dispatch(new \RuntimeException("Message {$id} failed permanently: ".$e->getMessage()));
                    } else {
                        // compute delay: base * multiplier^(attempts-1)
                        $delayMs = (int) ($rp['delay_ms'] * ($rp['multiplier'] ** ($env['attempts'] - 1)));
                        $env['available_at'] = $nowMs + $delayMs;
                        $driverObj->saveMessage($env, 'append');
                        // remove old copy (id might have changed if driver regenerates)
                        $driverObj->remove($id);
                    }
                }

                $processed++;
                if ($limit !== null && $processed >= $limit) {
                    return;
                }
            }

            // Scheduler: enqueue any recurring messages that are due
            $this->processScheduler($driver);

            // sleep a bit
            usleep($sleepMs * 1000);
        }
    }

    /**
     * Register a ScheduleProviderInterface (Symfony Scheduler) programmatically.
     * Accept any ScheduleProviderInterface implementation (or add providers via config).
     */
    public function processScheduler(string $driver = 'default'): void
    {
        if (empty($this->config->scheduler)) {
            return;
        }

        // For each configured scheduler entry, create RecurringMessage and check triggers.
        // This is a naive approach: in production, rely on the Scheduler component's console consumer.
        foreach ($this->config->scheduler as $name => $entry) {
            $msgClass = $entry['message_class'];
            $expr = $entry['expression']; // e.g. 'every 1 minute' or cron
            // instantiate message (no args). If needs args, use options.
            $message = new $msgClass(...($entry['options']['constructor_args'] ?? []));
            // create recurring message - use Symfony API
            // RecurringMessage::every accepts a string like '1 minute'
            $recurring = RecurringMessage::every($expr, $message);
            // we ask recurring->nextRun() or similar, but RecurringMessage implements MessageProviderInterface - for standalone we simulate
            // For simplicity: we always dispatch scheduled messages here (naive).
            $this->dispatch($message, $driver);
        }
    }
}