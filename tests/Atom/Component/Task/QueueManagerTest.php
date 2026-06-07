<?php

declare(strict_types=1);

namespace Tests\Atom\Component\Task;

use Atom\Component\Task\QueueManager;
use Atom\Component\Task\TaskInterface;
use Atom\Component\Task\Driver\MessageDriverInterface;
use PHPUnit\Framework\TestCase;

final class QueueManagerTest extends TestCase
{
    private function makeConfig(array $overrides = []): array
    {
        $base = [
            'drivers' => [
                'default' => [
                    'dsn' => 'redis://localhost/queue', // not used; we inject fake afterwards
                    'options' => [],
                ],
                'b' => [
                    'dsn' => 'redis://localhost/b',
                    'options' => [],
                ],
            ],
            'retryPolicy' => [
                'max_retries' => 3,
                'delay_ms' => 10,
                'multiplier' => 2.0,
            ],
            'scheduler' => [],
        ];
        return array_replace_recursive($base, $overrides);
    }

    private function injectDriver(QueueManager $qm, string $name, MessageDriverInterface $driver): void
    {
        // Use reflection to replace private drivers for tests
        $ref = new \ReflectionClass($qm);
        $prop = $ref->getProperty('drivers');
        $prop->setAccessible(true);
        $drivers = $prop->getValue($qm);
        $drivers[$name] = $driver;
        $prop->setValue($qm, $drivers);
    }

    public function testDispatchStoresEnvelopeAndCountIncrements(): void
    {
        $qm = new QueueManager($this->makeConfig());
        $fake = new InMemoryDriver();
        $this->injectDriver($qm, 'default', $fake);

        $id = $qm->dispatch(new DummyTask('A'));
        $this->assertNotEmpty($id);
        $this->assertSame(1, $qm->count('default'));

        $env = $fake->getMessage($id);
        $this->assertIsArray($env);
        $this->assertSame(DummyTask::class, $env['class']);
        $this->assertSame(0, $env['attempts']);
    }

    public function testRunWorkerProcessesReadyMessageAndRemovesIt(): void
    {
        $handled = [];
        $handlers = [
            DummyTask::class => [function (DummyTask $t) use (&$handled) { $handled[] = $t->name; }],
        ];
        $qm = new QueueManager($this->makeConfig(), $handlers);
        $fake = new InMemoryDriver();
        $this->injectDriver($qm, 'default', $fake);

        $qm->dispatch(new DummyTask('B'));
        $this->assertSame(1, $qm->count());

        $qm->runWorker('default', ['sleep_ms' => 0, 'limit' => 1]);

        $this->assertSame(['B'], $handled);
        $this->assertSame(0, $qm->count());
    }

    public function testDelayedMessagesAreSkippedUntilAvailable(): void
    {
        $handled = [];
        $handlers = [
            DummyTask::class => [function (DummyTask $t) use (&$handled) { $handled[] = $t->name; }],
        ];
        $qm = new QueueManager($this->makeConfig(), $handlers);
        $fake = new InMemoryDriver();
        $this->injectDriver($qm, 'default', $fake);

        // Manually insert an envelope with future available_at
        $now = (int) (microtime(true) * 1000);
        $id = $fake->saveMessage([
            'id' => null,
            'class' => DummyTask::class,
            'body' => ['name' => 'C'],
            'attempts' => 0,
            'available_at' => $now + 100000, // far future
            'created_at' => $now,
            'meta' => [],
        ]);

        $qm->runWorker('default', ['sleep_ms' => 0, 'limit' => 1]);

        $this->assertSame([], $handled, 'Delayed message should not be processed');
        $this->assertNotNull($fake->getMessage($id), 'Message should remain in queue');
    }

    public function testRetryPolicyAppliesExponentialBackoffAndEventuallyRemoves(): void
    {
        $attempts = 0;
        $handlers = [
            DummyTask::class => [function (DummyTask $t) use (&$attempts) { $attempts++; throw new \RuntimeException('fail'); }],
        ];
        $qm = new QueueManager($this->makeConfig([
            'retryPolicy' => [
                'max_retries' => 2,
                'delay_ms' => 1,
                'multiplier' => 2.0,
            ],
        ]), $handlers);
        $fake = new InMemoryDriver();
        $this->injectDriver($qm, 'default', $fake);

        $id = $qm->dispatch(new DummyTask('D'));
        // process attempt 1 -> requeued
        $qm->runWorker('default', ['sleep_ms' => 0, 'limit' => 1]);
        $env1 = current($fake->dumpEnvelopes());
        $this->assertGreaterThan((int)(microtime(true)*1000)-1, $env1['available_at']);
        $this->assertSame(1, $env1['attempts']);

        // force availability now, then process again (attempt 2 -> requeued or removed depending on policy)
        $env1['available_at'] = (int) (microtime(true) * 1000) - 1;
        $fake->clearAndReplace([$env1]);
        $qm->runWorker('default', ['sleep_ms' => 0, 'limit' => 1]);
        $env2 = current($fake->dumpEnvelopes());
        $this->assertSame(2, $env2['attempts']);

        // next run should exceed max_retries and remove permanently
        $env2['available_at'] = (int) (microtime(true) * 1000) - 1;
        $fake->clearAndReplace([$env2]);
        $qm->runWorker('default', ['sleep_ms' => 0, 'limit' => 1]);

        $this->assertSame(0, $qm->count(), 'Message should be removed after exceeding max retries');
        $this->assertSame(3, $attempts, 'Handler should be invoked for each attempt including the final one');
    }

    public function testTransferAllMovesMessagesBetweenDriversWithOptionalTransform(): void
    {
        $qm = new QueueManager($this->makeConfig());
        $a = new InMemoryDriver();
        $b = new InMemoryDriver();
        $this->injectDriver($qm, 'default', $a);
        $this->injectDriver($qm, 'b', $b);

        $id1 = $a->saveMessage([
            'id' => null,
            'class' => DummyTask::class,
            'body' => ['name' => 'X'],
            'attempts' => 0,
            'available_at' => (int)(microtime(true)*1000) - 1,
            'created_at' => (int)(microtime(true)*1000) - 1,
            'meta' => [],
        ]);

        $map = $qm->transferAll('default', 'b', false, function(array $env){ $env['body']['name'] = 'Y'; return $env; });
        $this->assertArrayHasKey($id1, $map);
        $copied = $b->getMessage($map[$id1]);
        $this->assertSame('Y', $copied['body']['name']);
    }
}

// ---------------------------------
// Helpers for tests
// ---------------------------------

final class InMemoryDriver implements MessageDriverInterface
{
    /** @var array<string,array> */
    private array $store = [];
    /** @var list<string> */
    private array $order = [];

    public function listKeys(): iterable { return $this->order; }

    public function getMessage(string $key): ?array { return $this->store[$key] ?? null; }

    public function saveMessage(array $envelope, string $position = 'append'): string
    {
        $id = $envelope['id'] ?? bin2hex(random_bytes(6));
        $envelope['id'] = $id;
        $this->store[$id] = $envelope;
        if ($position === 'prepend') { array_unshift($this->order, $id); }
        else { $this->order[] = $id; }
        return $id;
    }

    public function remove(string $key): bool
    {
        unset($this->store[$key]);
        $this->order = array_values(array_filter($this->order, fn($k) => $k !== $key));
        return true;
    }

    public function isOnline(): bool { return true; }

    /** @return list<array> */
    public function dumpEnvelopes(): array { return array_values($this->store); }

    /** @param list<array> $envs */
    public function clearAndReplace(array $envs): void
    {
        $this->store = [];
        $this->order = [];
        foreach ($envs as $e) { $this->saveMessage($e, 'append'); }
    }
}

final class DummyTask implements TaskInterface
{
    public string $name;
    public function __construct(string $name = 'n') { $this->name = $name; }
}
