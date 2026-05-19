<?php

declare(strict_types=1);

namespace Tests\Atom\Log;

use PHPUnit\Framework\TestCase;
use Atom\Log\T4LOG;

class T4LOGTest extends TestCase
{
    private $logDir;
    private $testLogFile;

    protected function setUp(): void
    {
        // Setup test environment
        $this->logDir = __DIR__ . '/runtime/test_log/';
        $this->testLogFile = $this->logDir . 'test_' . date('d_m_Y') . '.log';
        
        // Create directory if it doesn't exist
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up log file after each test
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
    }

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

    public function testLogMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test info logging
        $t4log->info('Test info message');
        
        // Verify log file was created and has content
        $this->assertFileExists($this->testLogFile);
    }

    public function testLogTMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test logT method returns $this (fluent interface)
        $result = $t4log->logT(T4LOG::PRIORITY_INFO, 'Test message');
        
        $this->assertInstanceOf(T4LOG::class, $result);
    }

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

    public function testEmergencyMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->emergency('Emergency message');
        
        $this->assertFileExists($this->testLogFile);
    }

    public function testAlertMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->alert('Alert message');
        
        $this->assertFileExists($this->testLogFile);
    }

    public function testCriticalMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->critical('Critical message');
        
        $this->assertFileExists($this->testLogFile);
    }

    public function testErrorMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->error('Error message');
        
        $this->assertFileExists($this->testLogFile);
    }

    public function testWarningMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->warning('Warning message');
        
        $this->assertFileExists($this->testLogFile);
    }

    public function testNoticeMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->notice('Notice message');
        
        $this->assertFileExists($this->testLogFile);
    }

    public function testInfoMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->info('Info message');
        
        $this->assertFileExists($this->testLogFile);
    }

    public function testDebugMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->debug('Debug message');
        
        $this->assertFileExists($this->testLogFile);
    }

    public function testDevMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        $t4log->dev('Development message');
        
        $this->assertFileExists($this->testLogFile);
    }

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

    public function testSaveLogMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test saveLog directly
        $result = $t4log->saveLog(T4LOG::PRIORITY_INFO, 'Direct log message');
        
        $this->assertTrue($result);
    }

    public function testParamParseMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test paramParse returns a string
        $result = $t4log->paramParse('Test message', T4LOG::PRIORITY_INFO, 'Test');
        
        $this->assertIsString($result);
    }

    public function testPriorityParseMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test with valid priority
        $result = $t4log->priorityParse(T4LOG::PRIORITY_INFO);
        $this->assertEquals('INFO', $result);
        
        // Test with null priority (should use default)
        $result = $t4log->priorityParse(null);
        $this->assertIsString($result);
    }

    public function testValidatePriorityMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test valid priorities
        $this->assertTrue($t4log->validatePriority(T4LOG::PRIORITY_INFO));
        $this->assertTrue($t4log->validatePriority(T4LOG::PRIORITY_DEBUG));
        $this->assertTrue($t4log->validatePriority(T4LOG::PRIORITY_ERR));
        
        // Test invalid priority
        $this->assertFalse($t4log->validatePriority(999));
    }

    public function testSanitizeFileNameMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test file name sanitization
        $input = 'test"file*name?.txt';
        $expected = 'test-file-name-.txt';
        $result = $t4log->santanizeFileName($input);
        
        $this->assertEquals($expected, $result);
    }

    public function testFilterFileNameMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test filtering file names
        $input = "test/file\\name:with*illegal<characters";
        $result = $t4log->filterFillName($input);
        
        $this->assertIsString($result);
    }

    public function testSetParamsMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Test setting params
        $params = ['test' => 'value'];
        $result = $t4log->setParams($params);
        
        $this->assertInstanceOf(T4LOG::class, $result);
    }

    public function testGetParamsMethod()
    {
        $t4log = new T4LOG(['logRootDir' => $this->logDir]);
        
        // Initially should return empty array
        $params = $t4log->getParams();
        $this->assertIsArray($params);
        $this->assertEmpty($params);
    }

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
