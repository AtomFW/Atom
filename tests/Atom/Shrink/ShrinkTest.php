<?php

declare(strict_types=1);

namespace Tests\Atom\Shrink;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Atom\Log\T4LOG;
use Atom\Shrink\Shrink;
use Atom\Exception\IO\FileNotFoundException;

/**
 * Test suite for the Shrink component.
 */
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

    /**
     * Test add css file exists.
     */
    public function testAddCssFileExists(): void
    {
        $path = __DIR__ . '/Fixtures/test.css';
        
        // Create a temporary file for testing
        $fileContent = 'body { color: red; }';
        file_put_contents($path, $fileContent);
        
        $this->expectNotToPerformAssertions();
        $this->shrink->addCss($path);
        
        // Cleanup
        unlink($path);
    }

    /**
     * Test add css file not found.
     */
    public function testAddCssFileNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/nonexistent.css';
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->addCss($path);
    }

    /**
     * Test css file exists.
     */
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

    /**
     * Test css file not found.
     */
    public function testCssFileNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/nonexistent.css';
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->css($path);
    }

    /**
     * Test css with save file exists.
     */
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
        
        file_put_contents($targetPath, '');
        $result = $this->shrink->cssWithSave($path, $targetPath);
        file_put_contents($targetPath, '');
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        
        // Cleanup
        unlink($path);
        unlink($targetPath);
    }

    /**
     * Test css with save file not found.
     */
    public function testCssWithSaveFileNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/nonexistent.css';
        $targetPath = __DIR__ . '/Fixtures/test.min.css';
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->cssWithSave($path, $targetPath);
    }

    /**
     * Test css with save target dir not found.
     */
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

    /**
     * Test auto scan css dir.
     */
    public function testAutoScanCssDir(): void
    {
        $dirPath = __DIR__ . '/Fixtures';
        
        $this->expectNotToPerformAssertions();
        $this->shrink->autoScanCssDir($dirPath);
        
        // We can't test the internal state easily, but we know no files were added due to
        // how directory structure is set up. But if files existed, they should be added.
    }

    /**
     * Test add js file exists.
     */
    public function testAddJsFileExists(): void
    {
        $path = __DIR__ . '/Fixtures/test.js';
        
        // Create a temporary file for testing
        $fileContent = 'console.log("Hello world");';
        file_put_contents($path, $fileContent);
        
        $this->expectNotToPerformAssertions();
        $this->shrink->addJs($path);
        
        // Cleanup
        unlink($path);
    }

    /**
     * Test add js file not found.
     */
    public function testAddJsFileNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/nonexistent.js';
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->addJs($path);
    }

    /**
     * Test js file exists.
     */
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

    /**
     * Test js file not found.
     */
    public function testJsFileNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/nonexistent.js';
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->js($path);
    }

    /**
     * Test js with save file exists.
     */
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
        
        file_put_contents($targetPath, '');
        $result = $this->shrink->jsWithSave($path, $targetPath);
        file_put_contents($targetPath, '');
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        
        // Cleanup
        unlink($path);
        unlink($targetPath);
    }

    /**
     * Test js with save file not found.
     */
    public function testJsWithSaveFileNotFound(): void
    {
        $path = __DIR__ . '/Fixtures/nonexistent.js';
        $targetPath = __DIR__ . '/Fixtures/test.min.js';
        
        $this->expectException(FileNotFoundException::class);
        $this->shrink->jsWithSave($path, $targetPath);
    }

    /**
     * Test js with save target dir not found.
     */
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

    /**
     * Test auto scan js dir.
     */
    public function testAutoScanJsDir(): void
    {
        $dirPath = __DIR__ . '/Fixtures';
        $this->expectNotToPerformAssertions();
        $this->shrink->autoScanJsDir($dirPath);
        
        // We can't test the internal state easily, but we know no files were added due to
        // how directory structure is set up. But if files existed, they should be added.
    }

    /**
     * Test save.
     */
    public function testSave(): void
    {
        // Test save method with empty paths (will throw exceptions)
        $this->expectException(\Exception::class);
        $this->shrink->save();
    }

    /**
     * Test destruct.
     */
    public function testDestruct(): void
    {
        $path = __DIR__ . '/Fixtures/test.js';
        $fileContent = 'console.log("Hello world");';
        file_put_contents($path, $fileContent);
        
        $this->expectNotToPerformAssertions();
        $this->shrink->addJs($path);
        // This would normally be called when object is destroyed
        // We're not actually testing destruction but verifying no errors happen
        
        // Cleanup
        unlink($path);
    }
}
