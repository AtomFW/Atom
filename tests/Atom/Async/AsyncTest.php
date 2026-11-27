<?php

declare(strict_types=1);

namespace Tests\Atom\Async;

use ArrayObject;
use Atom\Async\Async;
use Atom\Async\AsyncDriver;
use Atom\Async\Exception\AggregateAsyncException;
use Atom\Async\Exception\AsyncCancelledException;
use Atom\Async\Exception\AsyncException;
use Atom\Async\Exception\AsyncTimeoutException;
use Atom\Async\Future;
use Atom\Cache\InMemoryRedisAdapter;
use Atom\Modules\AtomCluster\AtomCluster;
use Atom\Modules\AtomCluster\NodeTelemetry;
use Atom\Modules\McpServer\McpServer;
use Atom\Support\DataRegistry;
use Atom\Terminal\Terminal;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Time\Duration;

final class AsyncTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \dirname(__DIR__, 3) . '/runtime/temp/async_test_' . \uniqid('', true);
        if (!\is_dir($this->tempDir)) {
            \mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tempDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $item) {
                $path = $item->getPathname();
                $item->isDir() ? \rmdir($path) : \unlink($path);
            }
            \rmdir($this->tempDir);
        }
    }

    public function testDiagnosticsDriverResolutionSwooleCompatibilityAndConfigFactory(): void
    {
        $cfg = require \dirname(__DIR__, 3) . '/config/async.php';
        self::assertIsArray($cfg);
        self::assertSame('auto', $cfg['default']);
        self::assertArrayHasKey('swoole', $cfg['choices']);
        self::assertArrayHasKey('fiber', $cfg['choices']);

        $async = Async::fromConfig([
            'on' => true,
            'default' => 'auto',
            'driver' => $cfg['choices']['auto'],
            'limits' => $cfg['limits'],
        ]);

        self::assertTrue($async->isEnabled());
        $expectedDriver = Async::isSwooleAvailable() ? AsyncDriver::Swoole : AsyncDriver::Fiber;
        self::assertSame($expectedDriver, $async->activeDriver());

        $forcedFiber = new Async(driver: AsyncDriver::Fiber);
        self::assertSame(AsyncDriver::Fiber, $forcedFiber->activeDriver());

        $swooleConfigured = new Async(driver: 'swoole');
        self::assertSame($expectedDriver, $swooleConfigured->activeDriver());

        $diag = $async->diagnostics();
        self::assertTrue($diag['enabled']);
        self::assertSame('auto', $diag['configuredDriver']);
        self::assertSame($expectedDriver->value, $diag['effectiveDriver']);
        self::assertSame(Async::isSwooleAvailable(), $diag['swooleAvailable']);
        self::assertNotEmpty($diag['ioPollBackend']);
        self::assertSame(32, $diag['defaultConcurrency']);
    }

    public function testConcurrentFibersAccelerateExecutionComparedToSequential(): void
    {
        $async = new Async(driver: AsyncDriver::Fiber, pollTickMs: 2);
        $startNs = \hrtime(true);

        // 5 tasks each sleeping 35ms would take >= 175ms sequentially; with Fibers they run concurrently!
        $results = $async->all([
            'alpha' => static function () use ($async): string {
                $async->sleep(Duration::fromMilliseconds(35));
                return 'A:' . (Async::isInsideFiber() ? 'fiber' : 'sync');
            },
            'beta' => static function () use ($async): string {
                $async->sleep(Duration::fromMilliseconds(35));
                return 'B:' . (Async::isInAsyncContext() ? 'async' : 'sync');
            },
            'gamma' => static function () use ($async): int {
                $async->sleep(Duration::fromMilliseconds(35));
                return 42;
            },
            'delta' => static function () use ($async): string {
                $async->sleep(Duration::fromMilliseconds(35));
                return 'D';
            },
            'epsilon' => static function () use ($async): string {
                $async->sleep(Duration::fromMilliseconds(35));
                return 'E';
            },
        ]);

        $elapsedMs = (\hrtime(true) - $startNs) / 1_000_000;

        self::assertSame([
            'alpha' => 'A:fiber',
            'beta' => 'B:async',
            'gamma' => 42,
            'delta' => 'D',
            'epsilon' => 'E',
        ], $results);
        self::assertLessThan(150.0, $elapsedMs, "Expected parallel fiber execution (< 150ms), took {$elapsedMs}ms");
    }

    public function testGlobalDeveloperHelpersSpawnAwaitAndAsyncAll(): void
    {
        $engine = \atom_async();
        self::assertInstanceOf(Async::class, $engine);

        $sum = $engine->run(static function (): int {
            $f1 = \async_spawn(static fn(int $a, int $b): int => $a + $b, 10, 15);
            $f2 = \async_spawn(static fn(int $x): int => $x * 2, 20);

            return (int) \async_await($f1) + (int) \async_await($f2);
        });
        self::assertSame(65, $sum);

        $mapped = \async_all([
            'first' => static fn(): string => 'atom',
            'second' => static fn(): string => 'async',
        ]);
        self::assertSame(['first' => 'atom', 'second' => 'async'], $mapped);
    }

    public function testFutureChainingThenCatchFinallyAndCancellation(): void
    {
        $async = new Async(driver: AsyncDriver::Fiber);
        $flags = new ArrayObject(['finallyCalled' => false]);

        $value = $async->run(static function (Async $rt) use ($flags): string {
            $chained = $rt->spawn(static function () use ($rt): int {
                $rt->sleep(Duration::fromMilliseconds(5));
                return 21;
            })
                ->then(static fn(int $v): int => $v * 2)
                ->then(static fn(int $v): string => "Result={$v}")
                ->finally(static function () use ($flags): void {
                    $flags['finallyCalled'] = true;
                });

            return $rt->await($chained);
        });

        self::assertSame('Result=42', $value);
        self::assertTrue($flags['finallyCalled']);

        // Test catch() recovery
        $recovered = $async->run(static function (Async $rt): string {
            $failing = $rt->spawn(static function (): string {
                throw new RuntimeException('boom');
            })->catch(static fn(\Throwable $e): string => 'recovered:' . $e->getMessage());

            return (string) $rt->await($failing);
        });
        self::assertSame('recovered:boom', $recovered);

        // Test explicit Future cancellation
        $cancelled = $async->spawn(static function () use ($async): string {
            $async->sleep(Duration::fromMilliseconds(200));
            return 'never';
        });
        self::assertTrue($cancelled->cancel('User aborted task.'));
        self::assertTrue($cancelled->isCancelled());

        $this->expectException(AsyncCancelledException::class);
        $cancelled->await();
    }

    public function testAllSettledRaceAndAnyCombinators(): void
    {
        $async = new Async(driver: AsyncDriver::Fiber, pollTickMs: 2);

        $settled = $async->allSettled([
            'ok' => static fn(): int => 100,
            'fail' => static function (): never {
                throw new RuntimeException('expected failure');
            },
        ]);

        self::assertSame('fulfilled', $settled['ok']['status']);
        self::assertSame(100, $settled['ok']['value']);
        self::assertSame('rejected', $settled['fail']['status']);
        self::assertInstanceOf(RuntimeException::class, $settled['fail']['reason']);

        // race() returns fastest candidate and cancels slower ones
        $winner = $async->race([
            'slow' => static function () use ($async): string {
                $async->sleep(Duration::fromMilliseconds(120));
                return 'slow-node';
            },
            'fast' => static function () use ($async): string {
                $async->sleep(Duration::fromMilliseconds(8));
                return 'fast-node';
            },
        ]);
        self::assertSame('fast-node', $winner);

        // any() ignores fast failures and resolves with the first fulfilled candidate
        $firstHealthy = $async->any([
            'broken_1' => static function (): never {
                throw new RuntimeException('replica 1 down');
            },
            'healthy_2' => static function () use ($async): string {
                $async->sleep(Duration::fromMilliseconds(10));
                return 'replica-2-payload';
            },
            'slow_3' => static function () use ($async): string {
                $async->sleep(Duration::fromMilliseconds(90));
                return 'replica-3-payload';
            },
        ]);
        self::assertSame('replica-2-payload', $firstHealthy);

        // any() throws AggregateAsyncException when every candidate fails
        try {
            $async->any([
                'a' => static fn(): never => throw new RuntimeException('err A'),
                'b' => static fn(): never => throw new RuntimeException('err B'),
            ]);
            self::fail('Expected AggregateAsyncException when all candidates fail.');
        } catch (AggregateAsyncException $agg) {
            self::assertCount(2, $agg->getExceptions());
            self::assertArrayHasKey('a', $agg->getExceptions());
            self::assertArrayHasKey('b', $agg->getExceptions());
        }
    }

    public function testBoundedConcurrencyMapFilterBatchAndWaterfall(): void
    {
        $async = new Async(driver: AsyncDriver::Fiber, pollTickMs: 2);
        $metrics = new ArrayObject(['active' => 0, 'peak' => 0]);

        $items = ['k1' => 1, 'k2' => 2, 'k3' => 3, 'k4' => 4, 'k5' => 5, 'k6' => 6];

        $squared = $async->map(
            $items,
            static function (int $n, string $key) use ($async, $metrics): string {
                $metrics['active'] = (int) $metrics['active'] + 1;
                if ((int) $metrics['active'] > (int) $metrics['peak']) {
                    $metrics['peak'] = (int) $metrics['active'];
                }
                $async->sleep(Duration::fromMilliseconds(10));
                $metrics['active'] = (int) $metrics['active'] - 1;

                return $key . ':' . ($n * $n);
            },
            concurrency: 2,
        );

        self::assertLessThanOrEqual(2, $metrics['peak']);
        self::assertSame([
            'k1' => 'k1:1',
            'k2' => 'k2:4',
            'k3' => 'k3:9',
            'k4' => 'k4:16',
            'k5' => 'k5:25',
            'k6' => 'k6:36',
        ], $squared);

        $evens = $async->filter(
            $items,
            static function (int $n) use ($async): bool {
                $async->yieldNow();
                return $n % 2 === 0;
            },
            concurrency: 3,
        );
        self::assertSame(['k2' => 2, 'k4' => 4, 'k6' => 6], $evens);

        $batches = $async->batch(
            [1, 2, 3, 4, 5],
            batchSize: 2,
            batchProcessor: static fn(array $chunk, int $idx): int => $idx * 100 + \array_sum($chunk),
            concurrency: 2,
        );
        self::assertSame([3, 107, 205], $batches);

        $pipelineOut = $async->waterfall([
            static fn(int $x): int => $x + 5,
            static fn(int $x): Future => Future::resolved($x * 3),
            static fn(int $x): string => "total={$x}",
        ], 10);
        self::assertSame('total=45', $pipelineOut);
    }

    public function testTimeoutDeadlineAndExponentialRetry(): void
    {
        $async = new Async(driver: AsyncDriver::Fiber, pollTickMs: 2);
        $counter = new ArrayObject(['tries' => 0]);

        $res = $async->retry(
            static function (int $attempt) use ($counter): string {
                $counter['tries'] = $attempt;
                if ($attempt < 3) {
                    throw new RuntimeException("Transient network glitch #{$attempt}");
                }
                return "succeeded-on-{$attempt}";
            },
            maxAttempts: 4,
            baseDelay: Duration::fromMilliseconds(5),
            backoffMultiplier: 1.5,
        );

        self::assertSame('succeeded-on-3', $res);
        self::assertSame(3, $counter['tries']);

        $this->expectException(AsyncTimeoutException::class);
        $async->timeout(static function () use ($async): string {
            $async->sleep(Duration::fromMilliseconds(250));
            return 'too-late';
        }, Duration::fromMilliseconds(20));
    }

    public function testChannelBackpressureMutexLockAndIsolatedFiberContext(): void
    {
        $async = new Async(driver: AsyncDriver::Fiber, pollTickMs: 2);

        $log = new ArrayObject();
        $received = $async->run(static function (Async $rt) use ($log): array {
            $chan = $rt->channel(capacity: 2);

            $producer = $rt->spawn(static function () use ($chan, $log): void {
                for ($i = 1; $i <= 5; $i++) {
                    $chan->push("msg-{$i}");
                    $log[] = "pushed:{$i}";
                }
                $chan->close();
            });

            $consumer = $rt->spawn(static function () use ($rt, $chan, $log): array {
                $rt->sleep(Duration::fromMilliseconds(8));
                $out = [];
                while (($item = $chan->pop()) !== null) {
                    $log[] = "popped:{$item}";
                    $out[] = $item;
                }
                return $out;
            });

            $rt->awaitAll([$producer, $consumer]);
            return $consumer->await();
        });

        self::assertSame(['msg-1', 'msg-2', 'msg-3', 'msg-4', 'msg-5'], $received);

        // Named lock prevents interleaved read-modify-write race condition across concurrent fibers
        $sharedAccount = new ArrayObject(['balance' => 0]);
        $async->all([
            static fn(): int => $async->withLock('wallet:1', static function () use ($async, $sharedAccount): int {
                $cur = (int) $sharedAccount['balance'];
                $async->sleep(Duration::fromMilliseconds(8));
                $sharedAccount['balance'] = $cur + 50;
                return (int) $sharedAccount['balance'];
            }),
            static fn(): int => $async->withLock('wallet:1', static function () use ($async, $sharedAccount): int {
                $cur = (int) $sharedAccount['balance'];
                $async->sleep(Duration::fromMilliseconds(4));
                $sharedAccount['balance'] = $cur + 70;
                return (int) $sharedAccount['balance'];
            }),
        ]);
        self::assertSame(120, $sharedAccount['balance']);

        // Isolated per-Fiber WeakMap context with child inheritance & sibling isolation
        $ctxCheck = $async->run(static function (Async $rt): array {
            $rt->contextSet('tenant', 'root-tenant');

            $branchA = $rt->spawn(static function () use ($rt): array {
                $inherited = $rt->contextGet('tenant');
                $rt->contextSet('tenant', 'tenant-A');
                $rt->sleep(Duration::fromMilliseconds(6));
                $child = $rt->spawn(static fn(): mixed => $rt->contextGet('tenant'));
                return ['inherited' => $inherited, 'own' => $rt->contextGet('tenant'), 'child' => $child->await()];
            });

            $branchB = $rt->spawn(static function () use ($rt): string {
                $rt->contextSet('tenant', 'tenant-B');
                $rt->sleep(Duration::fromMilliseconds(6));
                return (string) $rt->contextGet('tenant');
            });

            return [
                'a' => $branchA->await(),
                'b' => $branchB->await(),
                'rootAfter' => $rt->contextGet('tenant'),
            ];
        });

        self::assertSame('root-tenant', $ctxCheck['a']['inherited']);
        self::assertSame('tenant-A', $ctxCheck['a']['own']);
        self::assertSame('tenant-A', $ctxCheck['a']['child']);
        self::assertSame('tenant-B', $ctxCheck['b']);
        self::assertSame('root-tenant', $ctxCheck['rootAfter']);
    }

    public function testIoPollNonBlockingSocketStreamsAndChunkedAsyncFileOperations(): void
    {
        $async = new Async(driver: AsyncDriver::Fiber, pollTickMs: 2, streamChunkBytes: 2048);

        $domain = \DIRECTORY_SEPARATOR === '\\' ? \STREAM_PF_INET : \STREAM_PF_UNIX;
        $pair = \stream_socket_pair($domain, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        try {
            $socketReply = $async->run(static function (Async $rt) use ($pair): string {
                $writer = $rt->spawn(static function () use ($rt, $pair): int {
                    $rt->sleep(Duration::fromMilliseconds(10));
                    return $rt->writeStreamAsync($pair[0], 'ATOM_IO_POLL_PING', Duration::fromSeconds(2));
                });

                $reader = $rt->spawn(static function () use ($rt, $pair): string {
                    return $rt->readStreamAsync($pair[1], 64, Duration::fromSeconds(2));
                });

                $rt->await($writer);
                return $rt->await($reader);
            });

            self::assertSame('ATOM_IO_POLL_PING', $socketReply);
        } finally {
            \fclose($pair[0]);
            \fclose($pair[1]);
        }

        // Async atomic file writes, concurrent multi-file reads, and O(1) Generator chunk streaming
        $fileA = $this->tempDir . '/alpha.txt';
        $fileB = $this->tempDir . '/beta.txt';
        $payloadA = \str_repeat('Atom86Fiber-', 450); // ~5.4 KB (multiple 2 KB chunks)
        $payloadB = 'Concurrent file payload B';

        $async->all([
            static fn(): int => $async->writeFileAsync($fileA, $payloadA, atomic: true),
            static fn(): int => $async->writeFileAsync($fileB, $payloadB, atomic: true),
        ]);

        $loaded = $async->readFilesAsync([$fileA, $fileB], concurrency: 2);
        self::assertSame($payloadA, $loaded[$fileA]);
        self::assertSame($payloadB, $loaded[$fileB]);

        $chunkCount = 0;
        $reassembled = '';
        foreach ($async->streamFileChunks($fileA, 1024) as $chunk) {
            $chunkCount++;
            $reassembled .= $chunk;
        }
        self::assertGreaterThanOrEqual(5, $chunkCount);
        self::assertSame($payloadA, $reassembled);
    }

    public function testEcosystemIntegrationWithAtomClusterTerminalAndMcpServer(): void
    {
        $async = new Async(driver: AsyncDriver::Fiber, pollTickMs: 2);

        // 1. AtomCluster concurrent state sync & balanced batch dispatch
        $redis = new InMemoryRedisAdapter(true);
        $registry = new DataRegistry();
        $cluster = new AtomCluster([
            'clusterId' => 'async-cluster',
            'default' => 'redis',
            'choices' => ['redis' => ['driver' => 'redis', 'on' => true, 'routeAllTrafficViaRedis' => true]],
            'node' => ['id' => 'node-async-1', 'role' => 'worker', 'endpoint' => 'https://n1.internal', 'weight' => 100],
            'encryption' => ['on' => true, 'sharedKey' => 'async-cluster-secret-key-2026', 'keyId' => 'k1'],
        ], $redis);

        $cluster->heartbeat(new NodeTelemetry(
            nodeId: 'node-async-1',
            endpoint: 'https://n1.internal',
            role: 'worker',
            cpuLoad: 0.15,
            usedRamMb: 128.0,
            totalRamMb: 2048.0,
            activeConnections: 4,
            rttMs: 0.4,
            timestampNs: (int) (\microtime(true) * 1_000_000_000),
        ));

        $cluster->syncStatesAsync([
            'shop.catalog.version' => '2026.11',
            'shop.currency.default' => 'PLN',
            'shop.flags.fastCheckout' => true,
        ], $registry, concurrency: 3, async: $async);

        self::assertSame('2026.11', $cluster->getSharedState('shop.catalog.version'));
        self::assertSame('PLN', $registry->get('shop.currency.default'));
        self::assertTrue($cluster->getSharedState('shop.flags.fastCheckout'));

        $batchResponses = $cluster->dispatchBatchAsync(
            [
                'order_1' => ['id' => 101],
                'order_2' => ['id' => 102],
            ],
            static fn(array $req): string => 'processed:' . $req['id'],
            concurrency: 2,
            async: $async,
        );
        self::assertSame('processed:101', $batchResponses['order_1']['result']);
        self::assertSame('processed:102', $batchResponses['order_2']['result']);

        // 2. Terminal CLI `async:status` command & `config:show async` autocomplete
        $terminal = new Terminal(colorsEnabled: false);
        $code = $terminal->run(['atom.php', 'async:status', '--quiet']);
        self::assertSame(0, $code);
        $termOut = $terminal->getOutputBuffer();
        self::assertStringContainsString('Effective Driver', $termOut);
        self::assertStringContainsString('Io\\Poll Backend', $termOut);
        self::assertContains('async', $terminal->complete('config:show as'));

        // 3. McpServer `atom.async.status` tool
        $mcp = new McpServer(['on' => true]);
        $rpcReply = $mcp->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 99,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atom.async.status',
                'arguments' => [],
            ],
        ]);

        self::assertIsArray($rpcReply);
        self::assertFalse($rpcReply['result']['isError']);
        self::assertArrayHasKey('effectiveDriver', $rpcReply['result']['structuredContent']);
        self::assertArrayHasKey('ioPollBackend', $rpcReply['result']['structuredContent']);
    }
}
