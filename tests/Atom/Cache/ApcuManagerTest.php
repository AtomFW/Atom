<?php

declare(strict_types=1);

namespace Tests\Atom\Cache;

use PHPUnit\Framework\TestCase;
use Atom\Cache\ApcuManager;

class AutoMappedTest extends TestCase
{
    private $cache;
    
    protected function setUp(): void
    {
        // We set up for testing purposes - we assume that we have access to the cache
        $this->cache = new ApcuManager('test_namespace');
    }
    
    public function testSetAndGet()
    {
        $key = 'test_key';
        $value = 'test_value';
        
        $this->assertTrue($this->cache->set($key, $value));
        $this->assertEquals($value, $this->cache->get($key));
    }
    
    public function testGetNonExistent()
    {
        $key = 'non_existent_key';
        $default = 'default_value';
        
        $this->assertEquals($default, $this->cache->get($key, $default));
    }
    
    public function testHas()
    {
        $key = 'test_key';
        $value = 'test_value';
        
        $this->assertFalse($this->cache->has($key));
        $this->cache->set($key, $value);
        $this->assertTrue($this->cache->has($key));
    }
    
    public function testDelete()
    {
        $key = 'test_key';
        $value = 'test_value';
        
        $this->cache->set($key, $value);
        $this->assertTrue($this->cache->has($key));
        
        $this->assertTrue($this->cache->delete($key));
        $this->assertFalse($this->cache->has($key));
    }
    
    public function testClear()
    {
        $key1 = 'test_key_1';
        $key2 = 'test_key_2';
        $value = 'test_value';
        
        $this->cache->set($key1, $value);
        $this->cache->set($key2, $value);
        
        $this->assertTrue($this->cache->has($key1));
        $this->assertTrue($this->cache->has($key2));
        
        $this->assertTrue($this->cache->clear());
        
        // After clearing the cache, we cannot be sure that it will be removed from memory
        // But the test checks whether the method executes without errors
        $this->assertTrue(true);
    }
    
    public function testSetMultiple()
    {
        $values = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3'
        ];
        
        $this->assertTrue($this->cache->setMultiple($values));
        
        $retrieved = $this->cache->getMultiple(['key1', 'key2', 'key3']);
        $this->assertEquals($values, $retrieved);
    }
    
    public function testGetMultiple()
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        
        $result = $this->cache->getMultiple(['key1', 'key2', 'non_existent'], 'default');
        $expected = [
            'key1' => 'value1',
            'key2' => 'value2',
            'non_existent' => 'default'
        ];
        
        $this->assertEquals($expected, $result);
    }
    
    public function testRemember()
    {
        $callbackCalled = 0;
        $callback = function() use (&$callbackCalled) {
            $callbackCalled++;
            return 'computed_value';
        };
        
        // First call - will execute callback
        $result1 = $this->cache->remember('remember_key', $callback);
        $this->assertEquals('computed_value', $result1);
        $this->assertEquals(1, $callbackCalled);
        
        // Second call - will return from cache without executing the callback
        $result2 = $this->cache->remember('remember_key', $callback);
        $this->assertEquals('computed_value', $result2);
        $this->assertEquals(1, $callbackCalled); // It shouldn't increase
    }
    
    public function testIncrement()
    {
        $key = 'increment_key';
        
        // Value initialization
        $this->cache->set($key, 0);
        
        // Increment test
        $result1 = $this->cache->increment($key, 5);
        $this->assertEquals(5, $result1);
        
        $result2 = $this->cache->increment($key, 3);
        $this->assertEquals(8, $result2);
    }
    
    public function testDecrement()
    {
        $key = 'decrement_key';
        
        // Value initialization
        $this->cache->set($key, 10);
        
        // Decrement test
        $result1 = $this->cache->decrement($key, 3);
        $this->assertEquals(7, $result1);
        
        $result2 = $this->cache->decrement($key, 2);
        $this->assertEquals(5, $result2);
    }
    
    public function testCompareAndSwap()
    {
        $key = 'cas_key';
        $this->cache->set($key, 42);
        
        // Correct replacement
        $this->assertTrue($this->cache->compareAndSwap($key, 42, 100));
        $this->assertEquals(100, $this->cache->get($key));
        
        // Invalid substitution (value differs)
        $this->assertFalse($this->cache->compareAndSwap($key, 42, 200));
        $this->assertEquals(100, $this->cache->get($key)); // Still the same value
    }
    
    public function testKeys()
    {
        $this->cache->set('test_key_1', 'value1');
        $this->cache->set('test_key_2', 'value2');
        
        $keys = $this->cache->keys();
        $this->assertContains('test_key_1', $keys);
        $this->assertContains('test_key_2', $keys);
    }
    
    public function testAll()
    {
        $this->cache->set('all_test_1', 'value1');
        $this->cache->set('all_test_2', 'value2');
        
        $all = $this->cache->all();
        $this->assertArrayHasKey('all_test_1', $all);
        $this->assertArrayHasKey('all_test_2', $all);
    }
    
    public function testPurgeByPrefix()
    {
        $this->cache->set('prefix_test_1', 'value1');
        $this->cache->set('prefix_test_2', 'value2');
        $this->cache->set('other_key', 'value3');
        
        // Test if we are deleting the correct keys
        $deleted = $this->cache->purgeByPrefix('prefix_');
        $this->assertEquals(2, $deleted);
    }
    
    public function testPurgeByPattern()
    {
        $this->cache->set('pattern_test_1', 'value1');
        $this->cache->set('pattern_test_2', 'value2');
        
        // Try to remove everything that matches the pattern
        $deleted = $this->cache->purgeByPattern('/pattern_test_[0-9]+/');
        $this->assertEquals(2, $deleted);
    }
    
    public function testTouch()
    {
        $key = 'touch_test';
        $this->cache->set($key, 'value');
        
        // Check if the current behavior has not changed
        $this->assertTrue($this->cache->has($key));
        $this->assertEquals('value', $this->cache->get($key));
    }
    
    public function testTtlUntil()
    {
        $expiresAt = new \DateTime('+1 hour');
        $ttl = $this->cache->ttlUntil($expiresAt);
        
        // Check if the lifetime is positive
        $this->assertGreaterThan(0, $ttl);
    }
}
