<?php

declare(strict_types=1);

namespace Tests\Atom\Log;

use PHPUnit\Framework\TestCase;
use Atom\Log\T4LOG;

/**
 * Test suite for the T4LOG component.
 */
class T4LOGTest extends TestCase
{
    private $logDir;
    private $testLogFile;

    protected function setUp(): void
    {
        // Setup test environment
        $this->logDir = dirname(__DIR__, 3) . '/runtime/temp/t4log_test_' . uniqid() . '/';
        $this->testLogFile = $this->logDir . date('d_m_Y') . '_' . phpversion() . '.log';
        
        // Create directory if it doesn't exist
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up temporary log directory after each test
        if (is_dir($this->logDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->logDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $path = $file->getRealPath();
                if ($path !== false) {
                    is_dir($path) ? rmdir($path) : unlink($path);
                }
            }
            rmdir($this->logDir);
        }
    }

    private function invokeProtected($object, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod(get_class($object), $method);
        return $reflection->invokeArgs($object, $arguments);
    }

    /**
     * Test constructor with params.
     */
    public function testConstructorWithParams()
    {
        $params = [
            'priority' => T4LOG::PRIORITY_DEBUG,
            'logRootDir' => $this->logDir,
            'message' => 'Test Message'
        ];

        $t4log = new T4LOG($params);
        
        $this->assertInstanceOf(T4LOG::class, $t4log);
    }

    /**
     * Test log method.
     */
    public function testLogMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test info logging
        $t4log->info('Test info message');
        
        // Verify log file was created and has content
        $this->assertFileExists($this->testLogFile);
    }

    /**
     * Test log t method.
     */
    public function testLogTMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test logT method returns $this (fluent interface)
        $result = $t4log->logT(T4LOG::PRIORITY_INFO, 'Test message');
        
        $this->assertInstanceOf(T4LOG::class, $result);
    }

    /**
     * Test on method.
     */
    public function testOnMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test on method with different priority levels
        $t4log->on(T4LOG::PRIORITY_INFO, 'Test message');
        $t4log->on(T4LOG::PRIORITY_DEBUG, 'Debug message');
        $t4log->on(T4LOG::PRIORITY_ERR, 'Error message');
        
        // Should have created log file
        $this->assertFileExists($this->testLogFile);
    }

    /**
     * Test emergency method.
     */
    public function testEmergencyMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->emergency('Emergency message');
        
        $this->assertFileExists($this->testLogFile);
    }

    /**
     * Test alert method.
     */
    public function testAlertMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->alert('Alert message');
        
        $this->assertFileExists($this->testLogFile);
    }

    /**
     * Test critical method.
     */
    public function testCriticalMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->critical('Critical message');
        
        $this->assertFileExists($this->testLogFile);
    }

    /**
     * Test error method.
     */
    public function testErrorMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->error('Error message');
        
        $this->assertFileExists($this->testLogFile);
    }

    /**
     * Test warning method.
     */
    public function testWarningMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->warning('Warning message');
        
        $this->assertFileExists($this->testLogFile);
    }

    /**
     * Test notice method.
     */
    public function testNoticeMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->notice('Notice message');
        
        $this->assertFileExists($this->testLogFile);
    }

    /**
     * Test info method.
     */
    public function testInfoMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->info('Info message');
        
        $this->assertFileExists($this->testLogFile);
    }

    /**
     * Test debug method.
     */
    public function testDebugMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->debug('Debug message');
        
        $this->assertFileExists($this->testLogFile);
    }

    /**
     * Test dev method.
     */
    public function testDevMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->dev('Development message');
        
        $this->assertFileExists($this->testLogFile);
    }

    /**
     * Test sys method without system log.
     */
    public function testSysMethodWithoutSystemLog()
    {
        $t4log = new T4LOG([
            'logRootDir' => $this->logDir,
            'systemLog' => false
        ]);
        
        // This should not throw an exception
        $t4log->sys(LOG_USER, 'Test system message');
        
        $this->assertTrue(true); // Just ensure no exception was thrown
    }

    /**
     * Test save log method.
     */
    public function testSaveLogMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test saveLog using reflection
        $result = $this->invokeProtected($t4log, 'saveLog', [T4LOG::PRIORITY_INFO, 'Direct log message']);
        
        $this->assertTrue($result);
    }

    /**
     * Test param parse method.
     */
    public function testParamParseMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test paramParse returns a string
        $result = $this->invokeProtected($t4log, 'paramParse', ['Test message', T4LOG::PRIORITY_INFO, 'Test']);
        
        $this->assertIsString($result);
    }

    /**
     * Test priority parse method.
     */
    public function testPriorityParseMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test with valid priority
        $result = $this->invokeProtected($t4log, 'priorityParse', [T4LOG::PRIORITY_INFO]);
        $this->assertEquals('INFO', $result);
        
        // Test with null priority (should use default)
        $result = $this->invokeProtected($t4log, 'priorityParse', [null]);
        $this->assertIsString($result);
    }

    /**
     * Test validate priority method.
     */
    public function testValidatePriorityMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test valid priorities
        $this->assertTrue($this->invokeProtected($t4log, 'validatePriority', [T4LOG::PRIORITY_INFO]));
        $this->assertTrue($this->invokeProtected($t4log, 'validatePriority', [T4LOG::PRIORITY_DEBUG]));
        $this->assertTrue($this->invokeProtected($t4log, 'validatePriority', [T4LOG::PRIORITY_ERR]));
        
        // Test invalid priority
        $this->assertFalse($this->invokeProtected($t4log, 'validatePriority', [999]));
    }

    /**
     * Test sanitize file name method.
     */
    public function testSanitizeFileNameMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test file name sanitization
        $input = 'test"file*name?.txt';
        $expected = 'test-file-name-.txt';
        $result = $this->invokeProtected($t4log, 'santanizeFileName', [$input]);
        
        $this->assertEquals($expected, $result);
    }

    /**
     * Test filter file name method.
     */
    public function testFilterFileNameMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test filtering file names
        $input = "test/file\\name:with*illegal<characters";
        $result = $this->invokeProtected($t4log, 'filterFillName', [$input]);
        
        $this->assertIsString($result);
    }

    /**
     * Test set params method.
     */
    public function testSetParamsMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test setting params
        $params = ['test' => 'value'];
        $result = $t4log->setParams($params);
        
        $this->assertInstanceOf(T4LOG::class, $result);
    }

    /**
     * Test get params method.
     */
    public function testGetParamsMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Initially should return empty array
        $params = $t4log->getParams();
        $this->assertIsArray($params);
        $this->assertEmpty($params);
    }

    /**
     * Test get and set datetime.
     */
    public function testGetAndSetDatetime()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test getting default datetime
        $datetime = $t4log->getDatetime();
        $this->assertIsString($datetime);
        
        // Test setting datetime
        $t4log->setDatetime('Y-m-d');
        $newDatetime = $t4log->getDatetime();
        $this->assertEquals('Y-m-d', $newDatetime);
    }

    /**
     * Test get and set message.
     */
    public function testGetAndSetMessage()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test getting default message
        $message = $t4log->getMessage();
        $this->assertIsString($message);
        
        // Test setting message
        $t4log->setMessage('New test message');
        $newMessage = $t4log->getMessage();
        $this->assertEquals('New test message', $newMessage);
    }

    /**
     * Test get and set log scheme.
     */
    public function testGetAndSetLogScheme()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test getting default scheme
        $scheme = $t4log->getLogScheme();
        $this->assertIsString($scheme);
        
        // Test setting scheme
        $newScheme = 'New scheme: {{message}}';
        $result = $t4log->setLogScheme($newScheme);
        
        $this->assertInstanceOf(T4LOG::class, $result);
    }

    /**
     * Test set start and stop scheme.
     */
    public function testSetStartAndStopScheme()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test setting start scheme
        $startScheme = 'Start: {{message}}';
        $result1 = $t4log->setStartScheme($startScheme);
        $this->assertInstanceOf(T4LOG::class, $result1);
        
        // Test setting stop scheme
        $stopScheme = 'Stop: {{message}}';
        $result2 = $t4log->setStopScheme($stopScheme);
        $this->assertInstanceOf(T4LOG::class, $result2);
    }
}
