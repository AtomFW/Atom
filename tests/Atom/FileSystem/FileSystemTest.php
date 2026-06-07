<?php

declare(strict_types=1);

namespace Tests\Atom\FileSytem;

use PHPUnit\Framework\TestCase;
use Atom\FileSytem\FileSystem;
use Atom\Exception\IO\Generative\FileNotFoundGenerativeException;
use Atom\Exception\IO\Generative\IOGenerativeException;
use Atom\Exception\IO\Generative\InvalidArgumentGenerativeException;

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
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if (is_dir($file)) {
                rmdir($file);
            } else {
                unlink($file);
            }
        }

        rmdir($this->tempDir);
    }

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

    public function testCopyFailsWhenSourceDoesNotExist()
 {
        $source = $this->tempDir . '/nonexistent.txt';
        $destination = $this->tempDir . '/destination.txt';
        
        $this->expectException(FileNotFoundGenerativeException::class);
        $this->fileSystem->copy($source, $destination);
    }

    public function testMkdirCreatesDirectory()
    {
        $dir = $this->tempDir . '/nested/test/directory';
        
        $this->fileSystem->mkdir($dir);
        
        $this->assertDirectoryExists($dir);
    }

    public function testExistsReturnsTrueForExistingFile() 
    {
        $file = $this->tempDir . '/test.txt';
        file_put_contents($file, 'content');
        
        $result = $this->fileSystem->exists($file);
        
        $this->assertTrue($result);
    }

    public function testExistsReturnsFalseForNonExistingFile()
    {
        $file = $this->tempDir . '/nonexistent.txt';
        
        $result = $this->fileSystem->exists($file);
        
        $this->assertFalse($result);
    }

    public function testTouchCreatesFileIfNotExists()
    {
        $file = $this->tempDir . '/touched_file.txt';
        
        $this->fileSystem->touch($file);
        
        $this->assertFileExists($file);
    }

    public function testRemoveRemovesFile() 
    {
        $file = $this->tempDir . '/to_remove.txt';
        file_put_contents($file, 'content');
        
        $this->fileSystem->remove($file);
        
        $this->assertFileDoesNotExist($file);
    }

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

    public function testChmodSetsPermissions()
    {
        $file = $this->tempDir . '/test_permissions.txt';
        file_put_contents($file, 'content');
        
        $this->fileSystem->chmod($file, 0755);
        
        clearstatcache(false, $file);
        $permissions = substr(sprintf('%o', fileperms($file)), -4);
        
        $this->assertEquals('0755', $permissions);
    }

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

    public function testSymlinkCreatesLink()
    {
        $source = $this->tempDir . '/source_file.txt';
        $link = $this->tempDir . '/symlink_file.txt';
        
        file_put_contents($source, 'content');
        
        $this->fileSystem->symlink($source, $link);
        
        $this->assertFileExists($link);
        $this->assertEquals(file_get_contents($source), readlink($link));
    }

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

    public function testAbsolutePathDetection()
    {
        $this->assertTrue($this->fileSystem->isAbsolutePath('/test/path'));
        $this->assertTrue($this->fileSystem->isAbsolutePath('C:\\Windows\\path'));
        $this->assertFalse($this->fileSystem->isAbsolutePath('relative/path'));
    }

    public function testTempnamCreatesFile()
    {
        $tempFile = $this->fileSystem->tempnam(sys_get_temp_dir(), 'test_');
        
        $this->assertFileExists($tempFile);
        $this->assertStringStartsWith(sys_get_temp_dir(), $tempFile);
    }

    public function testDumpFileWritesToFile()
    {
        $file = $this->tempDir . '/dump_test.txt';
        
        $this->fileSystem->dumpFile($file, 'test content');
        
        $this->assertFileExists($file);
        $this->assertEquals('test content', file_get_contents($file));
    }

    public function testAppendToFileAppends()
    {
        $file = $this->tempDir . '/append_test.txt';
        file_put_contents($file, 'initial ');
        
        $this->fileSystem->appendToFile($file, 'content');
        
        $this->assertEquals('initial content', file_get_contents($file));
    }

    public function testMakePathRelative()
    {
        $path = $this->fileSystem->makePathRelative('/a/b/c/test.txt', '/a/b/c/');
        $this->assertEquals('test.txt', $path);
    }
}
