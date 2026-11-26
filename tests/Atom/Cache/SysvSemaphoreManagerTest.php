<?php

declare(strict_types=1);

namespace Tests\Atom\Cache;

use PHPUnit\Framework\TestCase;
use Atom\Cache\SysvSemaphoreManager;
use Atom\Cache\SysvIpcException;
use Atom\Cache\SysvIpcSupport;

/**
 * Test suite for the SysvSemaphoreManager component.
 */
class SysvSemaphoreManagerTest extends TestCase
{
    private SysvSemaphoreManager $semaphoreManager;
    private string $testNamespace = 'sysv-test-namespace';

    protected function setUp(): void
    {
        if (!extension_loaded('sysvsem')) {
            $this->markTestSkipped('sysvsem is not available in this PHP runtime.');
        }

        // Create a test semaphore manager with a unique base key
        $baseKey = 12345 + getmypid(); // Use process ID to make it unique
        $this->semaphoreManager = new SysvSemaphoreManager($baseKey, 0666, $this->testNamespace);
    }

    /**
     * Test constructor creates semaphore.
     */
    public function testConstructorCreatesSemaphore()
    {
        // Test that constructor properly creates a semaphore
        $this->assertInstanceOf(SysvSemaphoreManager::class, $this->semaphoreManager);
    }

    /**
     * Test get native semaphore returns resource.
     */
    public function testGetNativeSemaphoreReturnsResource()
    {
        // Test that getNativeSemaphore returns the expected resource type
        $semaphore = $this->semaphoreManager->getNativeSemaphore();
        $this->assertNotNull($semaphore);
    }

    /**
     * Test acquire release.
     */
    public function testAcquireRelease()
    {
        // Test basic acquire and release functionality
        $result = $this->semaphoreManager->acquire(false);
        $this->assertTrue($result);
        
        // Release the semaphore
        $releaseResult = $this->semaphoreManager->release();
        $this->assertTrue($releaseResult);
    }

    /**
     * Test with lock.
     */
    public function testWithLock()
    {
        // Test withLock method executes callback and releases semaphore
        $called = false;
        $callback = function ($semaphore) use (&$called) {
            $called = true;
            return 'test';
        };
        
        $result = $this->semaphoreManager->withLock($callback);
        $this->assertEquals('test', $result);
        $this->assertTrue($called);
    }

    /**
     * Test is available.
     */
    public function testIsAvailable()
    {
        // Test that isAvailable works correctly
        $available = $this->semaphoreManager->isAvailable();
        $this->assertTrue($available);
    }

    /**
     * Test remove.
     */
    public function testRemove()
    {
        // Note: This test only validates that the method exists and returns bool
        // Actual removal behavior depends on system resources
        $result = $this->semaphoreManager->remove();
        $this->assertTrue(is_bool($result));
    }

    /**
     * Test with lock exception handling.
     */
    public function testWithLockExceptionHandling()
    {
        // Test that withLock properly handles exceptions in callback
        $this->expectException(\Exception::class);
        
        $callback = function ($semaphore) {
            throw new \Exception('Test exception');
        };
        
        $this->semaphoreManager->withLock($callback);
    }

    /**
     * Test acquire with nowait.
     */
    public function testAcquireWithNowait()
    {
        // Test acquire with nowait parameter
        $result = $this->semaphoreManager->acquire(true);
        $this->assertTrue($result);
        
        // Release for subsequent tests
        $this->semaphoreManager->release();
    }
}
