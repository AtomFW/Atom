<?php

declare(strict_types=1);

namespace Tests\Atom\Security;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Atom\Security\FileSafetyScanner;

final class FileSafetyScannerTest extends TestCase
{
    private $tempDir;
    private $testFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/file_safety_scanner_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTestFiles();
    }

    private function removeTestFiles()
    {
        if (is_dir($this->tempDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->tempDir);
        }
    }

    /**
     * Test constructor with defaults.
     */
    public function testConstructorWithDefaults(): void
    {
        $scanner = new FileSafetyScanner([], new NullLogger());
        
        $this->assertInstanceOf(FileSafetyScanner::class, $scanner);
    }

    /**
     * Test constructor with custom options.
     */
    public function testConstructorWithCustomOptions(): void
    {
        $options = [
            'chunkSize' => 4096,
            'maxBytesPerFile' => 1024,
            'includeExtensions' => ['php', 'js'],
            'excludeExtensions' => ['log', 'tmp'],
            'excludePaths' => ['/tmp', '/var'],
            'caseInsensitive' => false
        ];

        $logger = new NullLogger();
        $scanner = new FileSafetyScanner($options, $logger);
        
        // Just make sure it doesn't throw any exceptions
        $this->assertInstanceOf(FileSafetyScanner::class, $scanner);
    }

    /**
     * Test normalize extensions.
     */
    public function testNormalizeExtensions(): void
    {
        $scanner = new FileSafetyScanner([]);
        $reflector = new \ReflectionClass($scanner);
        $method = $reflector->getMethod('normalizeExtensions');

        $result = $method->invoke($scanner, ['.php', 'js', '', '.txt']);
        $this->assertEquals(['php', 'js', 'txt'], $result);
    }

    /**
     * Test default signatures.
     */
    public function testDefaultSignatures(): void
    {
        $scanner = new FileSafetyScanner([]);
        $reflector = new \ReflectionClass($scanner);
        $method = $reflector->getMethod('defaultSignatures');

        $signatures = $method->invoke($scanner);
        $this->assertIsArray($signatures);
        $this->assertGreaterThan(0, count($signatures));
        $this->assertContains('eval(', $signatures);
    }

    /**
     * Test compute max signature length.
     */
    public function testComputeMaxSignatureLength(): void
    {
        $scanner = new FileSafetyScanner([
            'signatures' => [
                'test',
                'very_long_signature_here'
            ]
        ]);

        // Just verify no exception is thrown
        $this->assertInstanceOf(FileSafetyScanner::class, $scanner);
    }

    /**
     * Test set finding callback.
     */
    public function testSetFindingCallback(): void
    {
        $scanner = new FileSafetyScanner([]);
        $callback = function() {};
        
        $scanner->setFindingCallback($callback);
        // Just ensure it doesn't throw an exception
        $this->assertInstanceOf(FileSafetyScanner::class, $scanner);
    }

    /**
     * Test get findings.
     */
    public function testGetFindings(): void
    {
        $scanner = new FileSafetyScanner([]);
        $findings = $scanner->getFindings();
        
        $this->assertIsArray($findings);
        $this->assertEmpty($findings);
    }

    /**
     * Test clear findings.
     */
    public function testClearFindings(): void
    {
        $scanner = new FileSafetyScanner([]);
        
        // Add a "finding" by directly manipulating the property
        $reflector = new \ReflectionClass($scanner);
        $property = $reflector->getProperty('findings');
        $property->setValue($scanner, [['test' => 'data']]);
        
        // Clear findings
        $scanner->clearFindings();
        $findings = $scanner->getFindings();
        
        $this->assertEmpty($findings);
    }

    /**
     * Test is path excluded with regex.
     */
    public function testIsPathExcludedWithRegex(): void
    {
        $scanner = new FileSafetyScanner([
            'excludePaths' => ['/tmp/', '/\/var\/log\/.*/']
        ]);
        
        $reflector = new \ReflectionClass($scanner);
        $method = $reflector->getMethod('isPathExcluded');
        
        // Test with regex pattern
        $this->assertTrue($method->invoke($scanner, '/var/log/app.log'));
        $this->assertFalse($method->invoke($scanner, '/var/cache/app.log'));
    }

    /**
     * Test is path excluded with string.
     */
    public function testIsPathExcludedWithString(): void
    {
        $scanner = new FileSafetyScanner([
            'excludePaths' => ['/tmp/', '/var/log/']
        ]);
        
        $reflector = new \ReflectionClass($scanner);
        $method = $reflector->getMethod('isPathExcluded');
        
        // Test with string patterns
        $this->assertTrue($method->invoke($scanner, '/tmp/test.txt'));
        $this->assertFalse($method->invoke($scanner, '/home/user/file.txt'));
    }

    /**
     * Test should scan by extension with include.
     */
    public function testShouldScanByExtensionWithInclude(): void
    {
        $scanner = new FileSafetyScanner([
            'includeExtensions' => ['php', 'js']
        ]);
        
        $reflector = new \ReflectionClass($scanner);
        $method = $reflector->getMethod('shouldScanByExtension');
        
        // Test with included extensions
        $this->assertTrue($method->invoke($scanner, '/path/to/test.php'));
        $this->assertTrue($method->invoke($scanner, '/path/to/test.JS'));
        $this->assertFalse($method->invoke($scanner, '/path/to/test.txt'));
    }

    /**
     * Test should scan by extension with exclude.
     */
    public function testShouldScanByExtensionWithExclude(): void
    {
        $scanner = new FileSafetyScanner([
            'excludeExtensions' => ['log', 'tmp']
        ]);
        
        $reflector = new \ReflectionClass($scanner);
        $method = $reflector->getMethod('shouldScanByExtension');
        
        // Test with excluded extensions
        $this->assertFalse($method->invoke($scanner, '/path/to/test.log'));
        $this->assertFalse($method->invoke($scanner, '/path/to/test.tmp'));
        $this->assertTrue($method->invoke($scanner, '/path/to/test.php'));
    }

    /**
     * Test should scan by extension no rules.
     */
    public function testShouldScanByExtensionNoRules(): void
    {
        $scanner = new FileSafetyScanner([]);
        
        $reflector = new \ReflectionClass($scanner);
        $method = $reflector->getMethod('shouldScanByExtension');
        
        // Without rules, should scan all files
        $this->assertTrue($method->invoke($scanner, '/path/to/test.php'));
        $this->assertTrue($method->invoke($scanner, '/path/to/test.txt'));
    }

    /**
     * Test is file safe with invalid path.
     */
    public function testIsFileSafeWithInvalidPath(): void
    {
        $scanner = new FileSafetyScanner([]);
        
        // This should not throw an exception
        $this->expectNotToPerformAssertions();
        $scanner->isFileSafe('/path/that/does/not/exist');
    }
    
    /**
     * Test is file safe with unreadable file.
     */
    public function testIsFileSafeWithUnreadableFile(): void
    {
        // Create a file that we can't read
        $file = $this->tempDir . '/test.txt';
        file_put_contents($file, 'test content');
        
        // Make it unreadable by changing permissions
        chmod($file, 0000);
        
        $scanner = new FileSafetyScanner([]);
        $scanner->isFileSafe($file);
        
        // Should not throw an exception
        $this->expectNotToPerformAssertions();
        
        // Cleanup
        chmod($file, 0777);
        unlink($file);
    }

    /**
     * Test is file safe with valid content.
     */
    public function testIsFileSafeWithValidContent(): void
    {
        // Create a test file with valid content (no threats)
        $content = '<?php echo "Hello World"; ?>';
        $file = $this->tempDir . '/test.php';
        file_put_contents($file, $content);
        
        $scanner = new FileSafetyScanner([]);
        $scanner->isFileSafe($file);
        
        // Should not throw an exception
        $this->expectNotToPerformAssertions();
        
        unlink($file);
    }

    /**
     * Test is file safe with threatening content.
     */
    public function testIsFileSafeWithThreateningContent(): void
    {
        // Create a test file with potentially threatening content
        $content = 'eval($_POST[\'x\']);';
        $file = $this->tempDir . '/test.php';
        file_put_contents($file, $content);
        
        $scanner = new FileSafetyScanner([]);
        $scanner->isFileSafe($file);
        
        // Should not throw an exception but may have findings
        $findings = $scanner->getFindings();
        $this->assertNotEmpty($findings, "Expected findings for threatening content.");
        
        // Cleanup
        unlink($file);
    }

    /**
     * Test search buffer for signatures with regex.
     */
    public function testSearchBufferForSignaturesWithRegex(): void
    {
        $scanner = new FileSafetyScanner([
            'signatures' => [
                ['regex' => '/eval\s*\(/i'],
            ]
        ]);
        
        // Test that it doesn't crash with regex pattern
        $reflector = new \ReflectionClass($scanner);
        $method = $reflector->getMethod('searchBufferForSignatures');
        
        try {
            $method->invoke($scanner, 'eval(', '/test.php', 0, 0, 1, false);
            $this->assertTrue(true); // If we get here, no exception was thrown
        } catch (\Exception $e) {
            $this->fail('Exception should not be thrown');
        }
    }

    /**
     * Test record finding from buffer.
     */
    public function testRecordFindingFromBuffer(): void
    {
        $scanner = new FileSafetyScanner([]);
        
        $reflector = new \ReflectionClass($scanner);
        $method = $reflector->getMethod('recordFindingFromBuffer');
        
        try {
            $method->invoke($scanner, '/test.php', 'eval(', 0, 'eval(abc)', 0, 0, 1, false);
            $this->assertTrue(true); // If we get here, no exception was thrown
        } catch (\Exception $e) {
            $this->fail('Exception should not be thrown');
        }
    }

    /**
     * Test is safe with non existent path.
     */
    public function testIsSafeWithNonExistentPath(): void
    {
        $scanner = new FileSafetyScanner([]);
        
        $this->expectException(\InvalidArgumentException::class);
        $scanner->isSafe('/path/that/does/not/exist');
    }
}
