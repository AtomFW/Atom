<?php

declare(strict_types=1);

namespace Tests\Atom\Cache;

use Atom\Cache\MemoryCacheManager;
use Atom\Cache\RamCacheDriver;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Time\Duration;

final class MemoryCacheManagerTest extends TestCase
{
    private MemoryCacheManager $cache;

    protected function setUp(): void
    {
        $this->cache = new MemoryCacheManager(
            namespace: 'test_ram_' . \uniqid(),
            defaultTtl: 60,
            driver: RamCacheDriver::Memory,
            maxItems: 32,
            autoPruneInterval: 4,
        );
    }

    public function testAutoDriverSelectsSupportedBackend(): void
    {
        $autoCache = new MemoryCacheManager('auto_ns_' . \uniqid(), 120, RamCacheDriver::Auto);
        $available = MemoryCacheManager::availableDrivers();

        self::assertContains('memory', $available);
        self::assertContains($autoCache->getDriverName(), $available);
        $autoCache->clear();
    }

    public function testFromConfigInitializesCleanly(): void
    {
        $manager = MemoryCacheManager::fromConfig([
            'prefix' => 'cfg_test',
            'defaultTtl' => 300,
            'driver' => [
                'backend' => 'memory',
                'maxItems' => 64,
                'autoPruneInterval' => 8,
            ],
        ]);

        self::assertSame(RamCacheDriver::Memory, $manager->getDriver());
        self::assertSame(300, $manager->getDefaultTtl());
        self::assertTrue($manager->set('alpha', 123));
        self::assertSame(123, $manager->get('alpha'));
    }

    public function testCrudAddReplacePullAndForever(): void
    {
        self::assertTrue($this->cache->missing('item'));
        self::assertTrue($this->cache->add('item', 'first'));
        self::assertFalse($this->cache->add('item', 'second'));
        self::assertSame('first', $this->cache->get('item'));

        self::assertTrue($this->cache->replace('item', 'updated'));
        self::assertSame('updated', $this->cache->pull('item'));
        self::assertFalse($this->cache->has('item'));
        self::assertFalse($this->cache->replace('item', 'nope'));

        self::assertTrue($this->cache->forever('perm', ['ok' => true]));
        self::assertNull($this->cache->ttl('perm'));
        self::assertSame(['ok' => true], $this->cache->get('perm'));
    }

    public function testTtlAutoRemovesExpiredItemsWithDuration(): void
    {
        $this->cache->set('short_lived', 'ephemeral', Duration::fromMilliseconds(20));
        self::assertTrue($this->cache->has('short_lived'));

        \usleep(35_000);

        self::assertFalse($this->cache->has('short_lived'));
        self::assertSame('fallback', $this->cache->get('short_lived', 'fallback'));
    }

    public function testNegativeTtlImmediatelyDeletesKey(): void
    {
        $this->cache->set('temp', 'val', 60);
        self::assertTrue($this->cache->has('temp'));

        $this->cache->set('temp', 'gone', -5);
        self::assertFalse($this->cache->has('temp'));
    }

    public function testTouchPersistExpireAndPruneExpired(): void
    {
        $this->cache->set('session_token', 'abc', 10);
        self::assertNotNull($this->cache->ttl('session_token'));

        self::assertTrue($this->cache->persist('session_token'));
        self::assertNull($this->cache->ttl('session_token'));

        self::assertTrue($this->cache->expire('session_token', Duration::fromMilliseconds(15)));
        \usleep(25_000);

        $removed = $this->cache->pruneExpired();
        self::assertSame(1, $removed);
        self::assertFalse($this->cache->has('session_token'));
    }

    public function testBoundedMaxItemsEvictsOldestEntriesWithoutMemoryLeak(): void
    {
        $small = new MemoryCacheManager('bounded_ns', 300, RamCacheDriver::Memory, maxItems: 16);

        for ($i = 1; $i <= 25; $i++) {
            $small->set("k{$i}", $i);
        }

        self::assertLessThanOrEqual(16, $small->count());
        self::assertFalse($small->has('k1'));
        self::assertTrue($small->has('k25'));
    }

    public function testRememberAndBatchOperations(): void
    {
        $calls = 0;
        $compute = static function () use (&$calls): string {
            $calls++;
            return 'computed_value';
        };

        self::assertSame('computed_value', $this->cache->remember('lazy', $compute, 60));
        self::assertSame('computed_value', $this->cache->remember('lazy', $compute, 60));
        self::assertSame(1, $calls);

        $expiresAt = (new DateTimeImmutable())->modify('+60 seconds');
        self::assertSame('until_val', $this->cache->rememberUntil('until_k', static fn(): string => 'until_val', $expiresAt));

        $this->cache->setMultiple(['a' => 1, 'b' => 2]);
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 'none'], $this->cache->getMultiple(['a', 'b', 'c'], 'none'));

        $this->cache->deleteMultiple(['a', 'b']);
        self::assertFalse($this->cache->has('a'));
    }

    public function testIncrementDecrementAndCompareAndSwap(): void
    {
        self::assertSame(5, $this->cache->increment('counter', 5, 60, 0));
        self::assertSame(8, $this->cache->increment('counter', 3));
        self::assertSame(6, $this->cache->decrement('counter', 2));

        self::assertFalse($this->cache->compareAndSwap('counter', 999, 10));
        self::assertTrue($this->cache->compareAndSwap('counter', 6, 10));
        self::assertSame(10, $this->cache->get('counter'));
    }

    public function testListsSetsTagsAndPatternPurging(): void
    {
        self::assertSame(2, $this->cache->push('jobs', 'j1', 'j2'));
        self::assertSame(3, $this->cache->unshift('jobs', 'j0'));
        self::assertSame('j0', $this->cache->shift('jobs'));
        self::assertSame('j2', $this->cache->pop('jobs'));

        self::assertSame(2, $this->cache->addToSet('roles', 'admin', 'editor', 'admin'));
        self::assertSame(['admin', 'editor'], $this->cache->setMembers('roles'));
        self::assertSame(1, $this->cache->removeFromSet('roles', 'admin'));
        self::assertSame(['editor'], $this->cache->setMembers('roles'));

        $this->cache->setTagged(['users', 'team_a'], 'user:1', 'Alice');
        $this->cache->setTagged(['users', 'team_b'], 'user:2', 'Bob');
        self::assertSame(1, $this->cache->invalidateTags('team_a'));
        self::assertFalse($this->cache->has('user:1'));
        self::assertTrue($this->cache->has('user:2'));

        $this->cache->set('order:100', true);
        $this->cache->set('order:101', true);
        self::assertSame(2, $this->cache->purgeByPrefix('order:'));

        $this->cache->set('log_2026_01', 1);
        $this->cache->set('log_2026_02', 2);
        self::assertSame(2, $this->cache->purgeByPattern('/^log_2026_/'));
    }

    public function testCrossPlatformLockAndTtlAwareQueue(): void
    {
        $result = $this->cache->withLock(static fn(): string => 'locked_ok');
        self::assertSame('locked_ok', $result);

        self::assertTrue($this->cache->enqueue(['task' => 'expired'], channel: 2, ttl: Duration::fromMilliseconds(10)));
        self::assertTrue($this->cache->enqueue(['task' => 'active'], channel: 2, ttl: 60));

        \usleep(20_000);

        self::assertSame(['task' => 'active'], $this->cache->dequeue(2));
        self::assertNull($this->cache->dequeue(2));

        $stats = $this->cache->stats();
        self::assertSame('memory', $stats['driver']);
    }

    public function testEmptyKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->get('   ');
    }
}
