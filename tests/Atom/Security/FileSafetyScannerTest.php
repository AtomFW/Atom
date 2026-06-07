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
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
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

    public function testConstructorWithDefaults(): void
    {
        $scanner = new FileSafetyScanner([], new NullLogger());
        
        $this->assertInstanceOf(FileSafetyScanner::class, $scanner);
    }

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

    public function testNormalizeExtensions(): void
    {
        $scanner = new FileSafetyScanner([]);
        $reflector = new ReflectionClass($scanner);
        $method = $reflector->getMethod('normalizeExtensions');
        $method->setAccessible(true);

        $result = $method->invoke($scanner, ['.php', 'js', '', '.txt']);
        $this->assertEquals(['php', 'js', 'txt'], $result);
    }

    public function testDefaultSignatures(): void
    {
        $scanner = new FileSafetyScanner([]);
        $reflector = new ReflectionClass($scanner);
        $method = $reflector->getMethod('defaultSignatures');
        $method->setAccessible(true);

        $signatures = $method->invoke($scanner);
        $this->assertIsArray($signatures);
        $this->assertGreaterThan(0, count($signatures));
        $this->assertContains('eval(', $signatures);
    }

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

    public function testSetFindingCallback(): void
    {
        $scanner = new FileSafetyScanner([]);
        $callback = function() {};
        
        $scanner->setFindingCallback($callback);
        // Just ensure it doesn't throw an exception
        $this->assertInstanceOf(FileSafetyScanner::class, $scanner);
    }

    public function testGetFindings(): void
    {
        $scanner = new FileSafetyScanner([]);
        $findings = $scanner->getFindings();
        
        $this->assertIsArray($findings);
        $this->assertEmpty($findings);
    }

    public function testClearFindings(): void
    {
        $scanner = new FileSafetyScanner([]);
        
        // Add a "finding" by directly manipulating the property
        $reflector = new ReflectionClass($scanner);
        $property = $reflector->getProperty('findings');
        $property->setAccessible(true);
        $property->setValue($scanner, [['test' => 'data']]);
        
        // Clear findings
        $scanner->clearFindings();
        $findings = $scanner->getFindings();
        
        $this->assertEmpty($findings);
    }

    public function testIsPathExcludedWithRegex(): void
    {
        $scanner = new FileSafetyScanner([
            'excludePaths' => ['/tmp/', '/var/log/.*']
        ]);
        
        $reflector = new ReflectionClass($scanner);
        $method = $reflector->getMethod('isPathExcluded');
        $method->setAccessible(true);
        
        // Test with regex pattern
        $this->assertTrue($method->invoke($scanner, '/var/log/app.log'));
        $this->assertFalse($method->invoke($scanner, '/var/tmp/app.log'));
    }

    public function testIsPathExcludedWithString(): void
    {
        $scanner = new FileSafetyScanner([
            'excludePaths' => ['/tmp/', '/var/log/']
        ]);
        
        $reflector = new ReflectionClass($scanner);
        $method = $reflector->getMethod('isPathExcluded');
        $method->setAccessible(true);
        
        // Test with string patterns
        $this->assertTrue($method->invoke($scanner, '/tmp/test.txt'));
        $this->assertFalse($method->invoke($scanner, '/home/user/file.txt'));
    }

    public function testShouldScanByExtensionWithInclude(): void
    {
        $scanner = new FileSafetyScanner([
            'includeExtensions' => ['php', 'js']
        ]);
        
        $reflector = new ReflectionClass($scanner);
        $method = $reflector->getMethod('shouldScanByExtension');
        $method->setAccessible(true);
        
        // Test with included extensions
        $this->assertTrue($method->invoke($scanner, '/path/to/test.php'));
        $this->assertTrue($method->invoke($scanner, '/path/to/test.JS'));
        $this->assertFalse($method->invoke($scanner, '/path/to/test.txt'));
    }

    public function testShouldScanByExtensionWithExclude(): void
    {
        $scanner = new FileSafetyScanner([
            'excludeExtensions' => ['log', 'tmp']
        ]);
        
        $reflector = new ReflectionClass($scanner);
        $method = $reflector->getMethod('shouldScanByExtension');
        $method->setAccessible(true);
        
        // Test with excluded extensions
        $this->assertFalse($method->invoke($scanner, '/path/to/test.log'));
        $this->assertFalse($method->invoke($scanner, '/path/to/test.tmp'));
        $this->assertTrue($method->invoke($scanner, '/path/to/test.php'));
    }

    public function testShouldScanByExtensionNoRules(): void
    {
        $scanner = new FileSafetyScanner([]);
        
        $reflector = new ReflectionClass($scanner);
        $method = $reflector->getMethod('shouldScanByExtension');
        $method->setAccessible(true);
        
        // Without rules, should scan all files
        $this->assertTrue($method->invoke($scanner, '/path/to/test.php'));
        $this->assertTrue($method->invoke($scanner, '/path/to/test.txt'));
    }

    public function testIsFileSafeWithInvalidPath(): void
    {
        $scanner = new FileSafetyScanner([]);
        
        // This should not throw an exception
        $this->expectNotToPerformAssertions();
        $scanner->isFileSafe('/path/that/does/not/exist');
    }
    
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
        
        // Cleanup
        unlink($file);
    }

    public function testSearchBufferForSignaturesWithRegex(): void
    {
        $scanner = new FileSafetyScanner([
            'signatures' => [
                ['regex' => '/eval\s*\(/i'],
            ]
        ]);
        
        // Test that it doesn't crash with regex pattern
        $reflector = new ReflectionClass($scanner);
        $method = $reflector->getMethod('searchBufferForSignatures');
        $method->setAccessible(true);
        
        try {
            $method->invoke($scanner, 'eval(', '/test.php', 0, 0, 1, false);
            $this->assertTrue(true); // If we get here, no exception was thrown
        } catch (\Exception $e) {
            $this->fail('Exception should not be thrown');
        }
    }

    public function testRecordFindingFromBuffer(): void
    {
        $scanner = new FileSafetyScanner([]);
        
        $reflector = new ReflectionClass($scanner);
        $method = $reflector->getMethod('recordFindingFromBuffer');
        $method->setAccessible(true);
        
        try {
            $method->invoke($scanner, '/test.php', 'eval(', 0, 'eval(abc)', 0, 0, 1, false);
            $this->assertTrue(true); // If we get here, no exception was thrown
        } catch (\Exception $e) {
            $this->fail('Exception should not be thrown');
        }
    }

    public function testIsSafeWithNonExistentPath(): void
    {
        $scanner = new FileSafetyScanner([]);
        
        $this->expectException(\InvalidArgumentException::class);
        $scanner->isSafe('/path/that/does/not/exist');
    }
}
