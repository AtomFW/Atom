<?php

declare(strict_types=1);

namespace Tests\Atom\Cache;

use PHPUnit\Framework\TestCase;
use Atom\Cache\SysvSharedMemoryManager as SysvIpc;

class SysvIpcTest extends TestCase
{
    private SysvIpc $shm;

    protected function setUp(): void
    {
        $this->shm = new SysvIpc(1234, 1024);
    }

    protected function tearDown(): void
    {
        $this->shm->destroy();
    }

    public function testSetAndGet()
    {
        $key = 'test_key';
        $value = 'test_value';

        $this->shm->set($key, $value);
        $result = $this->shm->get($key);

        $this->assertEquals($value, $result);
    }

    public function testGetNonExistentKey()
    {
        $result = $this->shm->get('non_existent_key');
        $this->assertNull($result);
    }

    public function testGetWithDefaultValue()
    {
        $result = $this->shm->get('non_existent_key', 'default_value');
        $this->assertEquals('default_value', $result);
    }

    public function testExists()
    {
        $key = 'test_key';
        $this->shm->set($key, 'value');

        $this->assertTrue($this->shm->exists($key));
        $this->assertFalse($this->shm->exists('non_existent_key'));
    }

    public function testIncrement()
    {
        $key = 'counter';
        $this->shm->set($key, 5);
        
        $result = $this->shm->increment($key, 3);
        $this->assertEquals(8, $result);

        // Test with default increment by 1
        $result = $this->shm->increment($key);
        $this->assertEquals(9, $result);
    }

    public function testDecrement()
    {
        $key = 'counter';
        $this->shm->set($key, 10);
        
        $result = $this->shm->decrement($key, 3);
        $this->assertEquals(7, $result);

        // Test with default decrement by 1
        $result = $this->shm->decrement($key);
        $this->assertEquals(6, $result);
    }

    public function testPushAndPop()
    {
        $key = 'array_key';
        $this->shm->push($key, 'first');
        $this->shm->push($key, 'second');
        
        $popped = $this->shm->pop($key);
        $this->assertEquals('second', $popped);
        
        $popped = $this->shm->pop($key);
        $this->assertEquals('first', $popped);
        
        // Test popping from empty array
        $popped = $this->shm->pop($key);
        $this->assertNull($popped);
    }

    public function testUnshiftAndShift()
    {
        $key = 'array_key';
        $this->shm->unshift($key, 'last');
        $this->shm->unshift($key, 'first');
        
        $shifted = $this->shm->shift($key);
        $this->assertEquals('first', $shifted);
        
        $shifted = $this->shm->shift($key);
        $this->assertEquals('last', $shifted);
        
        // Test shifting from empty array
        $shifted = $this->shm->shift($key);
        $this->assertNull($shifted);
    }

    public function testRemember()
    {
        $key = 'cached_key';
        $called = false;
        
        $callback = function () use (&$called) {
            $called = true;
            return 'cached_value';
        };

        // First call should execute callback
        $result1 = $this->shm->remember($key, $callback);
        $this->assertEquals('cached_value', $result1);
        $this->assertTrue($called);

        // Reset callback flag
        $called = false;
        
        // Second call should return cached value without executing callback
        $result2 = $this->shm->remember($key, $callback);
        $this->assertEquals('cached_value', $result2);
        $this->assertFalse($called); // Callback should not be called again
    }

    public function testAll()
    {
        $this->shm->set('key1', 'value1');
        $this->shm->set('key2', 'value2');
        
        $all = $this->shm->all();
        $this->assertArrayHasKey('key1', $all);
        $this->assertArrayHasKey('key2', $all);
        $this->assertEquals('value1', $all['key1']);
        $this->assertEquals('value2', $all['key2']);
    }

    public function testKeys()
    {
        $this->shm->set('key1', 'value1');
        $this->shm->set('key2', 'value2');
        
        $keys = $this->shm->keys();
        sort($keys); // Sort for comparison
        
        $this->assertEquals(['key1', 'key2'], $keys);
    }

    public function testCount()
    {
        $this->shm->set('key1', 'value1');
        $this->shm->set('key2', 'value2');
        
        $this->assertEquals(2, $this->shm->count());
    }

    public function testClearExpired()
    {
        // This is a basic test - in practice, you'd want to set up
        // specific expiration scenarios to really test this functionality
        $result = $this->shm->clearExpired();
        $this->assertEquals(0, $result);
    }

    public function testClear()
    {
        $this->shm->set('key1', 'value1');
        $this->shm->set('key2', 'value2');
        
        $this->shm->clear();
        
        // After clearing, the storage should be empty
        $all = $this->shm->all();
        $this->assertEquals([], $all);
    }

    public function testDestroy()
    {
        $this->shm->set('key1', 'value1');
        $this->shm->destroy();
        
        // This should not throw an exception, but the actual implementation
        // may vary depending on how shared memory is handled
        $this->assertTrue(true);
    }

    public function testWithTtl()
    {
        $key = 'ttl_test';
        $this->shm->set($key, 'value', 1); // 1 second TTL
        
        sleep(2); // Wait for expiration
        
        // This should return null or default value since it's expired
        $result = $this->shm->get($key);
        $this->assertNull($result);
    }

    public function testArrayOperations()
    {
        $key = 'array_test';
        
        // Test push/pop with multiple elements
        $this->shm->push($key, 'first');
        $this->shm->push($key, 'second');
        $this->shm->push($key, 'third');
        
        $popped = $this->shm->pop($key);
        $this->assertEquals('third', $popped);
        
        $popped = $this->shm->pop($key);
        $this->assertEquals('second', $popped);
        
        $popped = $this->shm->pop($key);
        $this->assertEquals('first', $popped);
    }
}
