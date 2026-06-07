<?php

declare(strict_types=1);

namespace Tests\Atom\Shrink;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Atom\Log\T4LOG;
use Atom\Shrink\Shrink;
use Atom\Exception\IO\FileNotFoundException;

class ShrinkTest extends TestCase
{
    private T4LOG|MockObject $logger;
    private array $options = [
        'assetsCssDirPath' => '/path/to/assets/css',
        'assetsJsDirPath' => '/path/to/assets/js',
        'resourcesCssDirPath' => '/path/to/resources/css',
        'resourcesJsDirPath' => '/path/to/resources/js',
        'assetsCssRootMainPath' => '/path/to/assets/css/',
        'assetsJsRootMainPath' => '/path/to/assets/js/',
        'cssSingleFileName' => 'style',
        'jsSingleFileName' => 'script',
        'onlyRootDir' => false,
        'singleFile' => false,
        'rootCssDir' => '/css',
        'rootDir' => '/js'
    ];
    
    private Shrink $shrink;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->logger = $this->createMock(T4LOG::class);
        $this->shrink = new Shrink($this->logger, $this->options);
    }

    public function testAddCssFileExists(): void
    {
        $path = __DIR__ . '/Fixtures/test.css';
        
        // Create a temporary file for testing
        $fileContent = 'body { color: red; }';
        file_put_contents($path, $fileContent);
        
        $this->expectNotToPerformOperations();
        $this->shrink->addCss($path);
        
        // Cleanup
        unlink($path);
    }

    public function testAddCssFileNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/nonexistent.css';
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->addCss($path);
    }

    public function testCssFileExists(): void
    {
        $path = __DIR__ . '/Fixtures/test.css';
        $fileContent = 'body { color: red; }';
        file_put_contents($path, $fileContent);
        
        $result = $this->shrink->css($path);
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        
        // Cleanup
        unlink($path);
    }

    public function testCssFileNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/nonexistent.css';
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->css($path);
    }

    public function testCssWithSaveFileExists(): void
    {
        $path = __DIR__ . '/Fixtures/test.css';
        $targetPath = __DIR__ . '/Fixtures/test.min.css';
        
        $fileContent = 'body { color: red; }';
        file_put_contents($path, $fileContent);
        
        // Create a temporary target directory if it doesn't exist
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        $result = $this->shrink->cssWithSave($path, $targetPath);
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        
        // Cleanup
        unlink($path);
        unlink($targetPath);
    }

    public function testCssWithSaveFileNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/nonexistent.css';
        $targetPath = __DIR__ . '/Fixtures/test.min.css';
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->cssWithSave($path, $targetPath);
    }

    public function testCssWithSaveTargetDirNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/test.css';
        $targetPath = __DIR__ . '/Fixtures/nonexistent/test.min.css';
        
        $fileContent = 'body { color: red; }';
        file_put_contents($path, $fileContent);
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->cssWithSave($path, $targetPath);
        
        // Cleanup
        unlink($path);
    }

    public function testAutoScanCssDir(): void
    {
        $dirPath = __DIR__ . '/Fixtures';
        $this->shrink->autoScanCssDir($dirPath);
        
        // We can't test the internal state easily, but we know no files were added due to
        // how directory structure is set up. But if files existed, they should be added.
    }

    public function testAddJsFileExists(): void
    {
        $path = __DIR__ . '/Fixtures/test.js';
        
        // Create a temporary file for testing
        $fileContent = 'console.log("Hello world");';
        file_put_contents($path, $fileContent);
        
        $this->expectNotToPerformOperations();
        $this->shrink->addJs($path);
        
        // Cleanup
        unlink($path);
    }

    public function testAddJsFileNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/nonexistent.js';
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->addJs($path);
    }

    public function testJsFileExists(): void
    {
        $path = __DIR__ . '/Fixtures/test.js';
        $fileContent = 'console.log("Hello world");';
        file_put_contents($path, $fileContent);
        
        $result = $this->shrink->js($path);
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        
        // Cleanup
        unlink($path);
    }

    public function testJsFileNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/nonexistent.js';
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->js($path);
    }

    public function testJsWithSaveFileExists(): void
    {
        $path = __DIR__ . '/Fixtures/test.js';
        $targetPath = __DIR__ . '/Fixtures/test.min.js';
        
        $fileContent = 'console.log("Hello world");';
        file_put_contents($path, $fileContent);
        
        // Create a temporary target directory if it doesn't exist
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        $result = $this->shrink->jsWithSave($path, $targetPath);
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        
        // Cleanup
        unlink($path);
        unlink($targetPath);
    }

    public function testJsWithSaveFileNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/nonexistent.js';
        $targetPath = __DIR__ . '/Fixtures/test.min.js';
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->jsWithSave($path, $targetPath);
    }

    public function testJsWithSaveTargetDirNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/test.js';
        $targetPath = __DIR__ . '/Fixtures/nonexistent/test.min.js';
        
        $fileContent = 'console.log("Hello world");';
        file_put_contents($path, $fileContent);
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->jsWithSave($path, $targetPath);
        
        // Cleanup
        unlink($path);
    }

    public function testAutoScanJsDir(): void
    {
        $dirPath = __DIR__ . '/Fixtures';
        $this->shrink->autoScanJsDir($dirPath);
        
        // We can't test the internal state easily, but we know no files were added due to
        // how directory structure is set up. But if files existed, they should be added.
    }

    public function testSave(): void
    {
        // Test save method with empty paths (will throw exceptions)
        $this->expectException(\Exception::class);
        $this->shrink->save();
    }

    public function testDestruct(): void
    {
        $path = __DIR__ . '/Fixtures/test.js';
        $fileContent = 'console.log("Hello world");';
        file_put_contents($path, $fileContent);
        
        $this->shrink->addJs($path);
        // This would normally be called when object is destroyed
        // We're not actually testing destruction but verifying no errors happen
        
        // Cleanup
        unlink($path);
    }
}
