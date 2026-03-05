<?php

declare(strict_types=1);

namespace Security;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use league\flysystem;

/**
 * @covers \Security\FileSafetyScanner
 */
final class FileSafetyScannerTest extends TestCase
{
    private vfsStreamDirectory $root;
    private ?LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('root');
        $this->logger = new NullLogger(); // Use NullLogger for tests unless specific logging behavior is tested
    }

    protected function tearDown(): void
    {
        // vfsStream cleans up automatically
    }

    /**
     * Helper to create a file in the virtual file system.
     *
     * @param string $path Relative path from vfsStream root.
     * @param string $content File content.
     * @return string Absolute path to the created file.
     */
    private function createVfsFile(string $path, string $content): string
    {
        $fullPath = vfsStream::url('root/' . $path);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, $content);
        return $fullPath;
    }

    /**
     * Test basic file scanning and finding detection.
     */
    public function testBasicFileScanAndFinding(): void
    {
        $filePath = $this->createVfsFile('test.php', '<?php echo "hello"; eval("bad code"); ?>');

        $scanner = new FileSafetyScanner([], $this->logger);
        $scanner->isFileSafe($filePath);

        $findings = $scanner->getFindings();
        $this->assertNotEmpty($findings);
        // Ensure there is an 'eval(' finding for this file
        $hasEval = false;
        foreach ($findings as $f) {
            if ($f['file'] === $filePath && $f['signature'] === 'eval(') {
                $hasEval = true;
                $this->assertGreaterThan(0, $f['offset']);
                $this->assertGreaterThanOrEqual(1, $f['line']);
                $this->assertStringContainsString('eval("bad code")', $f['snippet']);
                $this->assertFalse($f['truncated']);
                break;
            }
        }
        $this->assertTrue($hasEval, 'Expected to find eval( signature in file');
    }

    /**
     * Test extension filtering using includeExtensions and excludeExtensions.
     */
    public function testExtensionFiltering(): void
    {
        $phpFile = $this->createVfsFile('script.php', '<?php eval("bad"); ?>');
        $txtFile = $this->createVfsFile('document.txt', 'This is a text file with eval("bad");');
        $jsFile = $this->createVfsFile('script.js', 'console.log("no eval here");');

        // Test includeExtensions: only PHP scanned -> findings only from PHP file
        $scanner = new FileSafetyScanner(['includeExtensions' => ['php']], $this->logger);
        $scanner->isSafe(vfsStream::url('root'));
        $findings = $scanner->getFindings();
        $this->assertNotEmpty($findings);
        $uniqueFiles = array_values(array_unique(array_column($findings, 'file')));
        $this->assertSame([$phpFile], $uniqueFiles);
        $scanner->clearFindings();

        // Test excludeExtensions: skip txt -> PHP and JS scanned, but only PHP has signatures
        $scanner = new FileSafetyScanner(['excludeExtensions' => ['txt']], $this->logger);
        $scanner->isSafe(vfsStream::url('root'));
        $findings = $scanner->getFindings();
        $this->assertNotEmpty($findings);
        $uniqueFiles = array_values(array_unique(array_column($findings, 'file')));
        $this->assertSame([$phpFile], $uniqueFiles);
    }

    /**
     * Test scanning directories recursively and honoring includeExtensions priority.
     */
    public function testRecursiveDirectoryScan(): void
    {
        $deepPhp = $this->createVfsFile('a/b/c/deep.php', '<?php echo 1; exec("id");');
        $nestedTxt = $this->createVfsFile('a/b/readme.txt', 'harmless');

        $scanner = new FileSafetyScanner(['includeExtensions' => ['php']], $this->logger);
        $scanner->isSafe(vfsStream::url('root'));

        $findings = $scanner->getFindings();
        $this->assertNotEmpty($findings);
        // Assert deep.php was scanned
        $this->assertTrue(in_array($deepPhp, array_column($findings, 'file'), true));
        // And that there is at least one exec( finding in that file
        $hasExec = false;
        foreach ($findings as $f) {
            if ($f['file'] === $deepPhp && $f['signature'] === 'exec(') {
                $hasExec = true;
                break;
            }
        }
        $this->assertTrue($hasExec, 'Expected to find exec( signature in deep.php');
    }

    /**
     * Test excludePaths supports substring and prevents scanning excluded files.
     */
    public function testExcludePathsSubstring(): void
    {
        $this->createVfsFile('cache/skip.php', '<?php eval("bad");');
        $good = $this->createVfsFile('src/ok.php', '<?php echo 1;');

        $scanner = new FileSafetyScanner([
            'includeExtensions' => ['php'],
            'excludePaths' => ['cache']
        ], $this->logger);
        $scanner->isSafe(vfsStream::url('root'));

        $findings = $scanner->getFindings();
        // Only ok.php scanned and has no default signature beyond '<?php'
        $this->assertNotEmpty($findings);
        $this->assertSame($good, $findings[0]['file']);
        $this->assertEquals('<?php', $findings[0]['signature']);
    }

    /**
     * Test maxBytesPerFile truncation marks findings with truncated=true when limit is small.
     */
    public function testMaxBytesPerFileStopsBeforeSignature(): void
    {
        // Signature appears after byte 10, but we only scan 8 bytes -> no findings
        $content = str_repeat('A', 10) . 'eval(' . str_repeat('B', 1000);
        $file = $this->createVfsFile('short.txt', $content);

        $scanner = new FileSafetyScanner([
            'includeExtensions' => ['txt'],
            'maxBytesPerFile' => 8,
            'chunkSize' => 16
        ], $this->logger);
        $scanner->isFileSafe($file);

        $findings = $scanner->getFindings();
        $this->assertCount(0, $findings);
    }

    /**
     * Test custom regex signature matching.
     */
    public function testRegexSignatures(): void
    {
        $file = $this->createVfsFile('danger.php', '<?php echo base64_decode("SGVsbG8=");');

        $scanner = new FileSafetyScanner([
            'includeExtensions' => ['php'],
            'signatures' => [
                ['regex' => '/base64_decode\s*\(/i'],
            ],
        ], $this->logger);
        $scanner->isFileSafe($file);

        $findings = $scanner->getFindings();
        $this->assertCount(1, $findings);
        $this->assertSame($file, $findings[0]['file']);
        $this->assertStringContainsString('base64_decode', $findings[0]['signature']);
    }

    /**
     * Test case-sensitive search option.
     */
    public function testCaseSensitiveSearch(): void
    {
        $file = $this->createVfsFile('mix.php', '<?php EVAL("x"); eval("y");');

        // Case-sensitive: should only find lowercase eval(
        $scanner = new FileSafetyScanner([
            'includeExtensions' => ['php'],
            'caseInsensitive' => false,
            'signatures' => ['eval('],
        ], $this->logger);
        $scanner->isFileSafe($file);
        $findings = $scanner->getFindings();

        $this->assertCount(1, $findings);
        $this->assertSame('eval(', $findings[0]['signature']);
        $this->assertStringContainsString('eval("y")', $findings[0]['snippet']);
    }

    public function testMaxBytesPerFileTruncationFlag(): void
    {
        $content = str_repeat('A', 20) . 'eval(' . str_repeat('B', 1000);
        $file = $this->createVfsFile('short.php', $content);

        $scanner = new FileSafetyScanner([
            'includeExtensions' => ['php'],
            'maxBytesPerFile' => 25, // will read only first 25 bytes, contains part of 'eval('
            'chunkSize' => 16
        ], $this->logger);
        $scanner->isFileSafe($file);

        $findings = $scanner->getFindings();
        $this->assertNotEmpty($findings);
        $this->assertSame($file, $findings[0]['file']);
        $this->assertEquals('eval(', $findings[0]['signature']);
        $this->assertTrue($findings[0]['truncated']);
    }

    /**
     * Should skip unreadable or non-regular files (like directories) without errors and without findings.
     */
    public function testSkipsUnreadableAndNonRegularFiles(): void
    {
        $dirPath = vfsStream::url('root/someDir');
        mkdir($dirPath, 0777, true);

        $scanner = new FileSafetyScanner(['includeExtensions' => ['php']], $this->logger);
        // Calling isFileSafe on a directory should simply return without findings or exceptions
        $scanner->isFileSafe($dirPath);
        $this->assertSame([], $scanner->getFindings());
    }

    /**
     * Should respect excludePaths regex patterns and skip matching files.
     */
    public function testExcludePathsRegex(): void
    {
        $this->createVfsFile('cache/skip.php', '<?php eval("bad");');
        $ok = $this->createVfsFile('src/ok.php', '<?php echo 123;');

        $scanner = new FileSafetyScanner([
            'includeExtensions' => ['php'],
            // Regex that matches any path containing 'cache/'
            'excludePaths' => ['#/cache/#'],
        ], $this->logger);
        $scanner->isSafe(vfsStream::url('root'));

        $findings = $scanner->getFindings();
        $this->assertNotEmpty($findings);
        // Ensure only ok.php is scanned and reported (default signature '<?php')
        $this->assertSame($ok, $findings[0]['file']);
        $this->assertEquals('<?php', $findings[0]['signature']);
        $this->assertFalse(in_array(vfsStream::url('root/cache/skip.php'), array_column($findings, 'file'), true));
    }

    /**
     * Should detect signatures spanning chunk boundaries using overlap.
     */
    public function testChunkBoundaryOverlapDetectsSignatures(): void
    {
        // Content arranged so that 'eval(' appears split across chunk boundary
        // chunk1: 'AAAA' | chunk2: 'eval' | chunk3: '(' -> with overlap=4, buffer will form 'eval('
        $file = $this->createVfsFile('split.txt', 'AAAA' . 'eval' . '(' . str_repeat('B', 10));

        $scanner = new FileSafetyScanner([
            'includeExtensions' => ['txt'],
            'chunkSize' => 4, // Force small chunks
            // Use default signatures which include 'eval('
        ], $this->logger);
        $scanner->isFileSafe($file);

        $findings = $scanner->getFindings();
        $this->assertNotEmpty($findings, 'Expected to detect eval( across chunk boundary');
        $this->assertSame($file, $findings[0]['file']);
        $this->assertEquals('eval(', $findings[0]['signature']);
    }

    /**
     * Should log a warning and throw InvalidArgumentException when path does not exist.
     */
    public function testNonExistentPathLogsWarningAndThrows(): void
    {
        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->once())
            ->method('warning')
            ->with(
                $this->equalTo('Path does not exist'),
                $this->callback(function ($context) {
                    return is_array($context) && isset($context['path']);
                })
            );

        $scanner = new FileSafetyScanner([], $mockLogger);

        $this->expectException(\InvalidArgumentException::class);
        $scanner->isSafe(vfsStream::url('root/does-not-exist'));
    }

    /**
     * Should invoke finding callback for each finding with correct data.
     */
    public function testFindingCallbackInvokedWithCorrectData(): void
    {
        $file = $this->createVfsFile('cb.php', '<?php echo 1; eval("x"); exec("y");');

        $received = [];
        $scanner = new FileSafetyScanner(['includeExtensions' => ['php']], $this->logger);
        $scanner->setFindingCallback(function (array $finding) use (&$received) {
            $received[] = $finding;
        });

        $scanner->isFileSafe($file);

        $this->assertNotEmpty($received);
        // At least two findings expected: '<?php' and 'eval(', possibly 'exec('
        $files = array_unique(array_column($received, 'file'));
        $this->assertSame([$file], array_values($files));
        foreach ($received as $f) {
            $this->assertArrayHasKey('signature', $f);
            $this->assertArrayHasKey('offset', $f);
            $this->assertArrayHasKey('line', $f);
            $this->assertArrayHasKey('snippet', $f);
            $this->assertArrayHasKey('truncated', $f);
            $this->assertFalse($f['truncated']);
        }
    }
}
