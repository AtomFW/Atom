<?php

declare(strict_types=1);

namespace Tests\Atom\FileSytem;

use PHPUnit\Framework\TestCase;
use Atom\FileSytem\FileSystem;
use Atom\Exception\IO\Generative\FileNotFoundGenerativeException;
use Atom\Exception\IO\Generative\IOGenerativeException;
use Atom\Exception\IO\Generative\InvalidArgumentGenerativeException;

/**
 * Test suite for the FileSystem component.
 */
class FileSystemTest extends TestCase
{
    private $fileSystem;
    private $tempDir;

    protected function setUp(): void
    {
        $this->fileSystem = new FileSystem();
        $this->tempDir = sys_get_temp_dir() . '/filesystem_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
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

    /**
     * Test copy succeeds.
     */
    public function testCopySucceeds()
    {
        $source = $this->tempDir . '/source.txt';
        $destination = $this->tempDir . '/destination.txt';
        
        file_put_contents($source, 'test content');
        
        $result = $this->fileSystem->copy($source, $destination);
        
        $this->assertTrue($result);
        $this->assertFileExists($destination);
        $this->assertEquals('test content', file_get_contents($destination));
    }

    /**
     * Test copy fails when source does not exist.
     */
    public function testCopyFailsWhenSourceDoesNotExist()
 {
        $source = $this->tempDir . '/nonexistent.txt';
        $destination = $this->tempDir . '/destination.txt';
        
        $this->expectException(FileNotFoundGenerativeException::class);
        $this->fileSystem->copy($source, $destination);
    }

    /**
     * Test mkdir creates directory.
     */
    public function testMkdirCreatesDirectory()
    {
        $dir = $this->tempDir . '/nested/test/directory';
        
        $this->fileSystem->mkdir($dir);
        
        $this->assertDirectoryExists($dir);
    }

    /**
     * Test exists returns true for existing file.
     */
    public function testExistsReturnsTrueForExistingFile() 
    {
        $file = $this->tempDir . '/test.txt';
        file_put_contents($file, 'content');
        
        $result = $this->fileSystem->exists($file);
        
        $this->assertTrue($result);
    }

    /**
     * Test exists returns false for non existing file.
     */
    public function testExistsReturnsFalseForNonExistingFile()
    {
        $file = $this->tempDir . '/nonexistent.txt';
        
        $result = $this->fileSystem->exists($file);
        
        $this->assertFalse($result);
    }

    /**
     * Test touch creates file if not exists.
     */
    public function testTouchCreatesFileIfNotExists()
    {
        $file = $this->tempDir . '/touched_file.txt';
        
        $this->fileSystem->touch($file);
        
        $this->assertFileExists($file);
    }

    /**
     * Test remove removes file.
     */
    public function testRemoveRemovesFile() 
    {
        $file = $this->tempDir . '/to_remove.txt';
        file_put_contents($file, 'content');
        
        $this->fileSystem->remove($file);
        
        $this->assertFileDoesNotExist($file);
    }

    /**
     * Test remove with array.
     */
    public function testRemoveWithArray()
    {
        $file1 = $this->tempDir . '/file1.txt';
        $file2 = $this->tempDir . '/file2.txt';
        
        file_put_contents($file1, 'content1');
        file_put_contents($file2, 'content2');
        
        $this->fileSystem->remove([$file1, $file2]);
        
        $this->assertFileDoesNotExist($file1);
        $this->assertFileDoesNotExist($file2);
    }

    /**
     * Test chmod sets permissions.
     */
    public function testChmodSetsPermissions()
    {
        $file = $this->tempDir . '/test_permissions.txt';
        file_put_contents($file, 'content');
        
        $this->fileSystem->chmod($file, 0755);
        
        clearstatcache(false, $file);
        $permissions = substr(sprintf('%o', fileperms($file)), -4);
        
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->assertEquals('0666', $permissions);
        } else {
            $this->assertEquals('0755', $permissions);
        }
    }

    /**
     * Test rename moves file.
     */
    public function testRenameMovesFile()
    {
        $source = $this->tempDir . '/old_name.txt';
        $target = $this->tempDir . '/new_name.txt';
        
        file_put_contents($source, 'content');
        
        $this->fileSystem->rename($source, $target);
        
        $this->assertFileDoesNotExist($source);
        $this->assertFileExists($target);
        $this->assertEquals('content', file_get_contents($target));
    }

    /**
     * Test symlink creates link.
     */
    public function testSymlinkCreatesLink()
    {
        $source = $this->tempDir . '/source_file.txt';
        $link = $this->tempDir . '/symlink_file.txt';
        
        file_put_contents($source, 'content');
        
        $this->fileSystem->symlink($source, $link, true);
        
        $this->assertFileExists($link);
        $this->assertEquals('content', file_get_contents($link));
    }

    /**
     * Test mirror copies files.
     */
    public function testMirrorCopiesFiles()
    {
        $originDir = $this->tempDir . '/origin';
        $targetDir = $this->tempDir . '/target';
        
        mkdir($originDir);
        file_put_contents($originDir . '/file.txt', 'content');
        
        $this->fileSystem->mirror($originDir, $targetDir);
        
        $this->assertFileExists($targetDir . '/file.txt');
        $this->assertEquals('content', file_get_contents($targetDir . '/file.txt'));
    }

    /**
     * Test absolute path detection.
     */
    public function testAbsolutePathDetection()
    {
        $this->assertTrue($this->fileSystem->isAbsolutePath('/test/path'));
        $this->assertTrue($this->fileSystem->isAbsolutePath('C:\\Windows\\path'));
        $this->assertFalse($this->fileSystem->isAbsolutePath('relative/path'));
    }

    /**
     * Test tempnam creates file.
     */
    public function testTempnamCreatesFile()
    {
        $tempDir = realpath(sys_get_temp_dir());
        $tempFile = $this->fileSystem->tempnam($tempDir, 'test_');
        
        $this->assertFileExists($tempFile);
        $this->assertStringStartsWith($tempDir, realpath($tempFile));
    }

    /**
     * Test dump file writes to file.
     */
    public function testDumpFileWritesToFile()
    {
        $file = $this->tempDir . '/dump_test.txt';
        
        $this->fileSystem->dumpFile($file, 'test content');
        
        $this->assertFileExists($file);
        $this->assertEquals('test content', file_get_contents($file));
    }

    /**
     * Test append to file appends.
     */
    public function testAppendToFileAppends()
    {
        $file = $this->tempDir . '/append_test.txt';
        file_put_contents($file, 'initial ');
        
        $this->fileSystem->appendToFile($file, 'content');
        
        $this->assertEquals('initial content', file_get_contents($file));
    }

    /**
     * Test make path relative.
     */
    public function testMakePathRelative()
    {
        $path = $this->fileSystem->makePathRelative('/a/b/c/test.txt', '/a/b/c/');
        $this->assertEquals('test.txt', rtrim($path, '/\\'));
    }
}
