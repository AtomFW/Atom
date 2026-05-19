<?php

declare(strict_types=1);

namespace Tests\Atom\Cache;

use PHPUnit\Framework\TestCase;
use Atom\Cache\SysvIpcManager;
use Atom\Cache\SysvQueueManager;
use Atom\Cache\SysvSemaphoreManager;
use Atom\Cache\SysvSharedMemoryManager;

class SysvIpcManagerTest extends TestCase
{
    public function testConstructor(): void
    {
        $manager = new SysvIpcManager(
            baseKey: 12345,
            permissions: 0644,
            shmSize: 2048,
            defaultTtl: 1800,
            namespace: 'test-namespace'
        );

        // Test that all properties are set correctly
        $this->assertInstanceOf(SysvIpcManager::class, $manager);
    }

    public function testQueue(): void
    {
        $manager = new SysvIpcManager(12345);
        $queueManager = $manager->queue();

        $this->assertInstanceOf(SysvQueueManager::class, $queueManager);
    }

    public function testSemaphore(): void
    {
        $manager = new SysvIpcManager(12345);
        $semaphoreManager = $manager->semaphore();

        $this->assertInstanceOf(SysvSemaphoreManager::class, $semaphoreManager);
    }

    public function testSharedMemory(): void
    {
        $manager = new SysvIpcManager(12345);
        $sharedMemoryManager = $manager->sharedMemory();

        $this->assertInstanceOf(SysvSharedMemoryManager::class, $sharedMemoryManager);
    }

    public function testConstructorWithDefaultParameters(): void
    {
        $manager = new SysvIpcManager(12345);

        // Test default values are set correctly
        $this->assertInstanceOf(SysvIpcManager::class, $manager);
    }

    public function testMultipleQueueInstances(): void
    {
        $manager = new SysvIpcManager(12345);
        $queue1 = $manager->queue();
        $queue2 = $manager->queue();

        // Both should be instances of the same class
        $this->assertInstanceOf(SysvQueueManager::class, $queue1);
        $this->assertInstanceOf(SysvQueueManager::class, $queue2);
        
        // They should be different instances
        $this->assertNotSame($queue1, $queue2);
    }

    public function testMultipleSemaphoreInstances(): void
    {
        $manager = new SysvIpcManager(12345);
        $semaphore1 = $manager->semaphore();
        $semaphore2 = $manager->semaphore();

        // Both should be instances of the same class
        $this->assertInstanceOf(SysvSemaphoreManager::class, $semaphore1);
        $this->assertInstanceOf(SysvSemaphoreManager::class, $semaphore2);
        
        // They should be different instances
        $this->assertNotSame($semaphore1, $semaphore2);
    }

    public function testMultipleSharedMemoryInstances(): void
    {
        $manager = new SysvIpcManager(12345);
        $shm1 = $manager->sharedMemory();
        $shm2 = $manager->sharedMemory();

        // Both should be instances of the same class
        $this->assertInstanceOf(SysvSharedMemoryManager::class, $shm1);
        $this->assertInstanceOf(SysvSharedMemoryManager::class, $shm2);
        
        // They should be different instances
        $this->assertNotSame($shm1, $shm2);
    }
}
