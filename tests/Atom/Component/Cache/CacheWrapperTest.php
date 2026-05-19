<?php

declare(strict_types=1);

namespace Tests\Atom\Component\Cache;

use PHPUnit\Framework\TestCase;
use Atom\Component\Cache\CacheWrapper;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Psr\Cache\CacheItemInterface;
use Throwable;

class CacheWrapperTest extends TestCase
{
    private CacheWrapper $cacheWrapper;
    private ArrayAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new ArrayAdapter();
        $this->cacheWrapper = new CacheWrapper();
        CacheWrapper::setAdapter($this->adapter);
    }

    public function testSetAndGetAdapter(): void
    {
        $this->assertInstanceOf(ArrayAdapter::class, CacheWrapper::getAdapter());
    }

    public function testGetItem(): void
    {
        $item = $this->cacheWrapper->getItem('test_key');
        $this->assertInstanceOf(CacheItemInterface::class, $item);
    }

    public function testGetItems(): void
    {
        $items = $this->cacheWrapper->getItems(['key1', 'key2']);
        $this->assertIsArray($items);
        $this->assertCount(2, $items);
    }

    public function testHasItem(): void
    {
        $this->assertFalse($this->cacheWrapper->hasItem('non_existent_key'));
        
        // Set a value and check again
        $this->cacheWrapper->set('test_key', 'test_value');
        $this->assertTrue($this->cacheWrapper->hasItem('test_key'));
    }

    public function testGet(): void
    {
        // Test with non-existent key
        $result = $this->cacheWrapper->get('non_existent_key', 'default_value');
        $this->assertEquals('default_value', $result);
        
        // Test with existing key
        $this->cacheWrapper->set('test_key', 'test_value');
        $result = $this->cacheWrapper->get('test_key');
        $this->assertEquals('test_value', $result);
    }

    public function testSet(): void
    {
        $result = $this->cacheWrapper->set('test_key', 'test_value');
        $this->assertTrue($result);
        
        // Verify the value was set correctly
        $value = $this->cacheWrapper->get('test_key');
        $this->assertEquals('test_value', $value);
    }

    public function testSetWithTtl(): void
    {
        $result = $this->cacheWrapper->set('test_key', 'test_value', 3600);
        $this->assertTrue($result);
        
        // Verify the value was set correctly
        $value = $this->cacheWrapper->get('test_key');
        $this->assertEquals('test_value', $value);
    }

    public function testRemember(): void
    {
        $result = $this->cacheWrapper->remember('test_key', function() {
            return 'computed_value';
        });
        
        $this->assertEquals('computed_value', $result);
        
        // Second call should return cached value
        $result2 = $this->cacheWrapper->remember('test_key', function() {
            return 'different_value';
        });
        
        $this->assertEquals('computed_value', $result2);
    }

    public function testGetMultiple(): void
    {
        $values = [
            'key1' => 'value1',
            'key2' => 'value2'
        ];
        
        foreach ($values as $key => $value) {
            $this->cacheWrapper->set($key, $value);
        }
        
        $result = $this->cacheWrapper->getMultiple(['key1', 'key2'], 'default');
        $this->assertEquals($values, $result);
    }

    public function testSetMultiple(): void
    {
        $values = [
            'key1' => 'value1',
            'key2' => 'value2'
        ];
        
        $result = $this->cacheWrapper->setMultiple($values);
        $this->assertTrue($result);
        
        // Verify values were set correctly
        $retrieved = $this->cacheWrapper->getMultiple(['key1', 'key2']);
        $this->assertEquals($values, $retrieved);
    }

    public function testDelete(): void
    {
        $this->cacheWrapper->set('test_key', 'test_value');
        $this->assertTrue($this->cacheWrapper->hasItem('test_key'));
        
        $result = $this->cacheWrapper->delete('test_key');
        $this->assertTrue($result);
        $this->assertFalse($this->cacheWrapper->hasItem('test_key'));
    }

    public function testDeleteMultiple(): void
    {
        $this->cacheWrapper->set('key1', 'value1');
        $this->cacheWrapper->set('key2', 'value2');
        
        $result = $this->cacheWrapper->deleteMultiple(['key1', 'key2']);
        $this->assertTrue($result);
        $this->assertFalse($this->cacheWrapper->hasItem('key1'));
        $this->assertFalse($this->cacheWrapper->hasItem('key2'));
    }

    public function testClear(): void
  {
      $this->cacheWrapper->set('test_key', 'test_value');
      $result = $this->cacheWrapper->clear();
      $this->assertTrue($result);
      
      // After clear, key should no longer exist
      $this->assertFalse($this->cacheWrapper->hasItem('test_key'));
  }

    public function testSupportsTags(): void
    {
        $supportsTags = $this->cacheWrapper->supportsTags();
        $this->assertFalse($supportsTags);
    }

    public function testIsTagAware(): void
    {
        $isTagAware = $this->cacheWrapper->isTagAware();
        $this->assertFalse($isTagAware);
    }

    public function testDebugState(): void
    {
        $state = $this->cacheWrapper->debugState();
        $this->assertArrayHasKey('adapter_set', $state);
        $this->assertArrayHasKey('adapter_class', $state);
        $this->assertArrayHasKey('supports_tags', $state);
    }

    public function testReset(): void
    {
        $this->cacheWrapper->reset();
        $this->assertNull(CacheWrapper::getAdapter());
    }
}
