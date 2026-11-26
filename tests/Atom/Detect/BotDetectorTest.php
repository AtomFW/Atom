<?php

declare(strict_types=1);

namespace Tests\Atom\Detect;

use PHPUnit\Framework\TestCase;
use Atom\Detect\BotDetector;
use PHPUnit\Framework\MockObject\MockObject;
use Atom\Log\T4LOG;

/**
 * Tests for BotDetector class
 */
final class BotDetectorTest extends TestCase
{
    private T4LOG $logger;
    private string $testUserAgent = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    
    protected function setUp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $tempDir = sys_get_temp_dir() . '/t4log_test_' . uniqid() . '/';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        $this->logger = new T4LOG(['logRootDir' => $tempDir]);
    }
    
    protected function tearDown(): void
    {
        T4LOG::$LOG_ROOT_DIR = __DIR__ . "/../../runtime/log/";
        T4LOG::$LOG_ROOT_DEV_DIR = "dev/";
    }
    
    /**
     * Test constructor initializes correctly.
     */
    public function test_constructor_initializes_correctly(): void
    {
        $detector = new BotDetector($this->logger, $this->testUserAgent, true, null);
        
        $this->assertEquals($this->testUserAgent, $detector->getUserAgent());
        $this->assertTrue($detector->isBot());
    }
    
    /**
     * Test constructor loads is bot file.
     */
    public function test_constructor_loads_is_bot_file(): void
    {
        // Test that constructor doesn't fail when is_bot.php is not found
        $detector = new BotDetector($this->logger, null, false, __DIR__ . '/fixtures/does_not_exist.php');
        
        // Should not throw - just not load anything
        $this->assertInstanceOf(BotDetector::class, $detector);
    }
    
    /**
     * Test constructor with heuristic bot.
     */
    public function test_constructor_with_heuristic_bot(): void
    {
        $detector = new BotDetector($this->logger, 'curl/7.0', false);
        $this->assertTrue($detector->isBot());
    }
    
    /**
     * Test is bot file loaded.
     */
    public function test_is_bot_file_loaded(): void
    {
        $this->assertFalse(BotDetector::isIsBotFileLoaded());
        
        // This should still work even if we don't have a real is_bot.php
        $this->assertFalse(BotDetector::isIsBotFileLoaded());
    }
    
    /**
     * Test is crawler detect available.
     */
    public function test_is_crawler_detect_available(): void
    {
        $this->assertTrue(BotDetector::isCrawlerDetectAvailable());
    }
    
    /**
     * Test heuristic detection.
     */
    public function test_heuristic_detection(): void
    {
        $detector = new BotDetector($this->logger, $this->testUserAgent);
        
        $method = new \ReflectionMethod(BotDetector::class, 'heuristicIsBot');
        
        // Test bot keywords
        $this->assertTrue($method->invoke($detector, 'Googlebot'));
        $this->assertTrue($method->invoke($detector, 'crawler'));
        $this->assertFalse($method->invoke($detector, 'Mozilla/5.0'));
    }
    
    /**
     * Test user agent methods.
     */
    public function test_user_agent_methods(): void
    {
        $detector = new BotDetector($this->logger, 'Test UA');
        
        $this->assertEquals('Test UA', $detector->getUserAgent());
        
        $detector->setUserAgent('New UA');
        $this->assertEquals('New UA', $detector->getUserAgent());
    }
    
    /**
     * Test preferred detector with file.
     */
    public function test_preferred_detector_with_file(): void
    {
        // This test requires a real is_bot.php file to be loaded first
        $detector = new BotDetector($this->logger, $this->testUserAgent);
        $this->assertEquals('heuristic', $detector->preferredDetector());
    }
    
    /**
     * Test debug state.
     */
    public function test_debug_state(): void
    {
        $detector = new BotDetector($this->logger, $this->testUserAgent);
        $debug = $detector->debugState();
        
        $this->assertArrayHasKey('ua', $debug);
        $this->assertEquals($this->testUserAgent, $debug['ua']);
        $this->assertFalse($debug['is_bot_file_loaded']);
        $this->assertTrue($debug['crawler_detect_available']);
    }
    
    /**
     * Test human detection.
     */
    public function test_human_detection(): void
    {
        $detector = new BotDetector($this->logger, 'Some human browser');
        $this->assertTrue($detector->isHuman());
        
        $detector = new BotDetector($this->logger, 'Googlebot');
        $this->assertFalse($detector->isHuman());
    }
    
    /**
     * Test detect bot name with heuristic.
     */
    public function test_detect_bot_name_with_heuristic(): void
    {
        $detector = new BotDetector($this->logger, 'curl/7.0');
        $name = $detector->detectBotName();
        
        $this->assertSame('curl', $name);
    }
    
    /**
     * Test detect bot name with known bot.
     */
    public function test_detect_bot_name_with_known_bot(): void
    {
        $detector = new BotDetector($this->logger, 'Googlebot/2.1');
        $name = $detector->detectBotName();
        
        // This will return null as Googlebot is not in the known list
        $this->assertSame('Googlebot', $name);
    }
    
    /**
     * Test detect bot name with known bot case insensitive.
     */
    public function test_detect_bot_name_with_known_bot_case_insensitive(): void
    {
        $detector = new BotDetector($this->logger, 'facebookexternalhit/1.0');
        $name = $detector->detectBotName();
        
        // This should match FacebookExternalHit but our heuristic is case sensitive
        // and doesn't have exact matching for this string in the list
        $this->assertSame('FacebookExternalHit', $name);
    }
    
    /**
     * Test is bot with empty ua.
     */
    public function test_is_bot_with_empty_ua(): void
    {
        $detector = new BotDetector($this->logger, '');
        $this->assertFalse($detector->isBot());
    }
    
    /**
     * Test refresh loads.
     */
    public function test_refresh_loads(): void
    {
        $detector = new BotDetector($this->logger);
        $detector->refreshLoads(null, true);
        
        // Should not crash
        $this->assertInstanceOf(BotDetector::class, $detector);
    }
}
