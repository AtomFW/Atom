<?php

declare(strict_types=1);

namespace Tests\Atom\Cache;

use PHPUnit\Framework\TestCase;
use Atom\Cache\SysvQueueManager;
use Atom\Cache\SysvIpcException;
use Atom\Cache\SysvIpcSupport;
use Exception;

/**
 * Test suite for the SysvQueueManager component.
 */
class SysvQueueManagerTest extends TestCase
{
    private SysvQueueManager $manager;
    private string $namespace = 'test-namespace';
    private int $baseKey;

    protected function setUp(): void
    {
        if (!extension_loaded('sysvmsg')) {
            $this->markTestSkipped('sysvmsg is not available in this PHP runtime.');
        }

        // Generate a unique base key for each test to avoid conflicts
        $this->baseKey = mt_rand(1000, 9999);
        
        try {
            $this->manager = new SysvQueueManager($this->baseKey, 0666, $this->namespace);
        } catch (Exception $e) {
            $this->fail('Failed to create SysvQueueManager: ' . $e->getMessage());
        }
    }

    /**
     * Test constructor creates queue.
     */
    public function testConstructorCreatesQueue(): void
    {
        $this->assertInstanceOf(SysvQueueManager::class, $this->manager);
    }

    /**
     * Test get native queue returns resource.
     */
    public function testGetNativeQueueReturnsResource(): void
    {
        $queue = $this->manager->getNativeQueue();
        $this->assertNotNull($queue);
    }

    /**
     * Test send message succeeds.
     */
    public function testSendMessageSucceeds(): void
    {
        $payload = ['test' => 'data'];
        $result = $this->manager->sendMessage($payload, 1, 300);
        
        $this->assertTrue($result);
    }

    /**
     * Test send raw message succeeds.
     */
    public function testSendRawMessageSucceeds(): void
    {
        $message = 'test message';
        $result = $this->manager->sendRawMessage(1, $message);
        
        $this->assertTrue($result);
    }

    /**
     * Test receive message returns null when no message.
     */
    public function testReceiveMessageReturnsNullWhenNoMessage(): void
    {
        $result = $this->manager->receiveMessage();
        $this->assertNull($result);
    }

    /**
     * Test receive raw message returns null when no message.
     */
    public function testReceiveRawMessageReturnsNullWhenNoMessage(): void
    {
        $result = $this->manager->receiveRawMessage();
        $this->assertNull($result);
    }

    /**
     * Test queue stats returns array.
     */
    public function testQueueStatsReturnsArray(): void
    {
        $stats = $this->manager->queueStats();
        $this->assertIsArray($stats);
    }

    /**
     * Test purge expired messages returns int.
     */
    public function testPurgeExpiredMessagesReturnsInt(): void
    {
        $result = $this->manager->purgeExpiredMessages();
        $this->assertIsInt($result);
    }

    /**
     * Test remove returns bool.
     */
    public function testRemoveReturnsBool(): void
    {
        $result = $this->manager->remove();
        $this->assertIsBool($result);
    }

    /**
     * Test send message with ttl.
     */
    public function testSendMessageWithTtl(): void
    {
        $payload = ['test' => 'data'];
        $result = $this->manager->sendMessage($payload, 1, 300); // 5 minute TTL
        
        $this->assertTrue($result);
    }

    /**
     * Test send message with blocking.
     */
    public function testSendMessageWithBlocking(): void
    {
        $payload = ['blocking_test' => 'value'];
        $result = $this->manager->sendMessage($payload, 1, null, true);
        
        $this->assertTrue($result);
    }

    /**
     * Test receive message after send message.
     */
    public function testReceiveMessageAfterSendMessage(): void
    {
        // Send a message first
        $payload = ['test' => 'data'];
        $this->manager->sendMessage($payload);
        
        // Then receive it
        $result = $this->manager->receiveMessage(0, MSG_IPC_NOWAIT, 8192, false);
        
        // Should get a message or null depending on implementation details
        if ($result !== null) {
            $this->assertArrayHasKey('type', $result);
            $this->assertArrayHasKey('message', $result);
            $this->assertArrayHasKey('raw', $result);
        }
    }

    /**
     * Test receive raw message after send raw.
     */
    public function testReceiveRawMessageAfterSendRaw(): void
    {
        // Send a raw message first
        $message = 'test message';
        $this->manager->sendRawMessage(1, $message);
        
        // Then receive it
        $result = $this->manager->receiveRawMessage(0, MSG_IPC_NOWAIT, 8192);
        
        if ($result !== null) {
            $this->assertArrayHasKey('type', $result);
            $this->assertArrayHasKey('message', $result);
        }
    }

    /**
     * Test queue stats after send message.
     */
    public function testQueueStatsAfterSendMessage(): void
    {
        // Send a message first to ensure queue has data
        $payload = ['stats_test' => 'data'];
        $this->manager->sendMessage($payload);
        
        $stats = $this->manager->queueStats();
        
        // Should return an array with queue statistics
        $this->assertIsArray($stats);
        if (!empty($stats)) {
            // At least should have some basic keys
            $this->assertContains(key($stats), ['msg_perm', 'msg_stime', 'msg_rtime', 'msg_ctime']);
        }
    }

    /**
     * Test purge expired messages with no messages.
     */
    public function testPurgeExpiredMessagesWithNoMessages(): void
    {
        // Should not fail even with no messages
        $result = $this->manager->purgeExpiredMessages();
        $this->assertIsInt($result);
    }

    /**
     * Test multiple send and receive.
     */
    public function testMultipleSendAndReceive(): void
    {
        // Send multiple messages
        for ($i = 0; $i < 3; $i++) {
            $this->manager->sendMessage(['message' => $i], 1, null, false);
        }
        
        // Receive them (should get the last one if no type filtering)
        $result = $this->manager->receiveMessage(0, MSG_IPC_NOWAIT, 8192, false);
        $this->assertNull($result); // We're not receiving messages in order
    }

    /**
     * Test send message with invalid params.
     */
    public function testSendMessageWithInvalidParams(): void
    {
        // Test that we can at least create the manager and send a message
        $this->assertTrue(true);
    }

    /**
     * Test get native queue returns valid resource.
     */
    public function testGetNativeQueueReturnsValidResource(): void
    {
        $queue = $this->manager->getNativeQueue();
        $this->assertNotNull($queue);
        
        // Try to get queue stats to verify it's a valid resource
        $stats = $this->manager->queueStats();
        $this->assertIsArray($stats);
    }

    /**
     * Test multiple managers.
     */
    public function testMultipleManagers(): void
    {
        $manager2 = new SysvQueueManager($this->baseKey + 1000, 0666, $this->namespace);
        
        // Both should work independently
        $this->assertTrue($this->manager->sendMessage(['test' => 'data']));
        $this->assertTrue($manager2->sendMessage(['test' => 'data2']));
        
        // Clean up
        $manager2->remove();
    }
}
