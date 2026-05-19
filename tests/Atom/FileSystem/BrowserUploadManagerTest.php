<?php

declare(strict_types=1);

namespace Tests\Atom\FileSystem;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Atom\FileSytem\BrowserUploadManager;

class BrowserUploadManagerTest extends TestCase
{
    private string $tempDir;
    private string $uploadDir;
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tempDir = __DIR__ . '/tmp';
        $this->uploadDir = __DIR__ . '/uploads';
        
        // Create directories if they don't exist
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0775, true);
        }
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0775, true);
        }
        
        $this->config = [
            'upload_dir' => $this->uploadDir,
            'temp_dir' => $this->tempDir,
            'max_file_size' => 10485760, // 10MB
            'max_total_size' => 52428800, // 50MB
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf'],
            'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'],
            'allow_multiple' => true,
            'max_files' => 10,
            'allow_chunked' => true,
            'chunk_size' => 102400, // 100KB
            'allow_pause_resume' => true,
            'allow_cancel' => true,
            'overwrite_existing' => false,
            'generate_random_names' => true,
        ];
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        $this->cleanDirectory($this->tempDir);
        $this->cleanDirectory($this->uploadDir);
        
        parent::tearDown();
    }
    
    private function cleanDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            $path = $file->getRealPath();
            if (is_writable($path)) {
                is_dir($path) ? rmdir($path) : unlink($path);
            }
        }
    }

    public function test_constructor_initializes_correctly(): void
    {
        $manager = new BrowserUploadManager($this->config);
        
        $this->assertEquals($this->uploadDir, (new \ReflectionClass($manager))->getProperty('uploadDir')->getValue($manager));
        $this->assertEquals($this->tempDir, (new \ReflectionClass($manager))->getProperty('tempDir')->getValue($manager));
    }

    public function test_upload_single_success(): void
    {
        // Create a temporary file for testing
        $testFile = __DIR__ . '/test.txt';
        file_put_contents($testFile, 'Test content');
        
        $files = [
            'name' => [$testFile],
            'type' => ['text/plain'],
            'tmp_name' => [$testFile],
            'error' => [UPLOAD_ERR_OK],
            'size' => [12]
        ];
        
        $manager = new BrowserUploadManager($this->config);
        $result = $manager->uploadSingle($files);
        
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('path', $result['data']);
    }

    public function test_upload_multiple_success(): void
    {
        // Create two temporary files for testing
        $testFile1 = __DIR__ . '/test1.txt';
        $testFile2 = __DIR__ . '/test2.txt';
        file_put_contents($testFile1, 'Test content 1');
        file_put_contents($testFile2, 'Test content 2');
        
        $files = [
            'name' => [$testFile1, $testFile2],
            'type' => ['text/plain', 'text/plain'],
            'tmp_name' => [$testFile1, $testFile2],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [13, 13]
        ];
        
        $manager = new BrowserUploadManager($this->config);
        $result = $manager->uploadMultiple($files);
        
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('files', $result['data']);
    }

    public function test_upload_from_field_success(): void
    {
        // Create a temporary file for testing
        $testFile = __DIR__ . '/test.txt';
        file_put_contents($testFile, 'Test content');
        
        $_FILES = [
            'file' => [
                'name' => ['test.txt'],
                'type' => ['text/plain'],
                'tmp_name' => [$testFile],
                'error' => [UPLOAD_ERR_OK],
                'size' => [12]
            ]
        ];
        
        $manager = new BrowserUploadManager($this->config);
        $result = $manager->uploadFromField('file');
        
        $this->assertTrue($result['ok']);
    }

    public function test_upload_multiple_from_field_success(): void
    {
        // Create a temporary file for testing
        $testFile1 = __DIR__ . '/test1.txt';
        $testFile2 = __DIR__ . '/test2.txt';
        file_put_contents($testFile1, 'Test content 1');
        file_put_contents($testFile2, 'Test content 2');
        
        $_FILES = [
            'files' => [
                'name' => [$testFile1, $testFile2],
                'type' => ['text/plain', 'text/plain'],
                'tmp_name' => [$testFile1, $testFile2],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [13, 13]
            ]
        ];
        
        $manager = new BrowserUploadManager($this->config);
        $result = $manager->uploadMultipleFromField('files');
        
        $this->assertTrue($result['ok']);
    }

    public function test_start_chunk_session_success(): void
    {
        $manager = new BrowserUploadManager($this->config);
        $result = $manager->startChunkSession('test.txt', 1024, 2);
        
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('upload_id', $result['data']);
    }

    public function test_save_chunk_from_request_failure(): void
    {
        $manager = new BrowserUploadManager($this->config);
        $result = $manager->saveChunkFromRequest('nonexistent');
        
        $this->assertFalse($result['ok']);
    }

    public function test_finalize_chunk_missing_chunks(): void
    {
        $manager = new BrowserUploadManager($this->config);
        $result = $manager->finalizeChunk('nonexistent');
        
        $this->assertFalse($result['ok']);
    }

    public function test_pause_unpause_disabled(): void
    {
        $config = array_merge($this->config, ['allow_pause_resume' => false]);
        $manager = new BrowserUploadManager($config);
        
        $result = $manager->pause('nonexistent');
        $this->assertFalse($result['ok']);
        
        $result = $manager->resume('nonexistent');
        $this->assertFalse($result['ok']);
    }

    public function test_cancel_disabled(): void
    {
        $config = array_merge($this->config, ['allow_cancel' => false]);
        $manager = new BrowserUploadManager($config);
        
        $result = $manager->cancel('nonexistent');
        $this->assertFalse($result['ok']);
    }

    public function test_status_nonexistent_session(): void
    {
        $manager = new BrowserUploadManager($this->config);
        $result = $manager->status('nonexistent');
        
        $this->assertFalse($result['ok']);
    }

    public function test_human_bytes_formatting(): void
    {
        $manager = new BrowserUploadManager($this->config);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('humanBytes');
        $method->setAccessible(true);
        
        $this->assertEquals('1024 B', $method->invoke($manager, 1024));
        $this->assertEquals('1 KB', $method->invoke($manager, 1024));
        $this->assertEquals('1 MB', $method->invoke($manager, pow(1024, 2)));
    }

    public function test_process_standard_upload_empty_files(): void
    {
        $files = [];
        $manager = new BrowserUploadManager($this->config);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('processStandardUpload');
        $method->setAccessible(true);
        
        $result = $method->invoke($manager, $files, [], false);
        $this->assertTrue($result['ok']);
    }

    public function test_move_uploaded_file_success(): void
    {
        // Create a temporary file for testing
        $testFile = __DIR__ . '/test.txt';
        file_put_contents($testFile, 'Test content');
        
        $file = [
            'name' => 'test.txt',
            'type' => 'text/plain',
            'tmp_name' => $testFile,
            'error' => UPLOAD_ERR_OK,
            'size' => 12
        ];
        
        $manager = new BrowserUploadManager($this->config);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('moveUploadedFile');
        $method->setAccessible(true);
        
        $result = $method->invoke($manager, $file, []);
        $this->assertTrue($result['ok']);
    }

    public function test_validate_uploaded_file_failure(): void
    {
        $manager = new BrowserUploadManager($this->config);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('validateUploadedFile');
        $method->setAccessible(true);
        
        $file = [
            'name' => '',
            'type' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0
        ];
        
        $result = $method->invoke($manager, $file, []);
        $this->assertFalse($result['ok']);
    }

    public function test_sanitize_filename(): void
    {
        $manager = new BrowserUploadManager($this->config);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('sanitizeFilename');
        $method->setAccessible(true);
        
        $this->assertEquals('test_file.txt', $method->invoke($manager, 'test\file.txt'));
        $this->assertEquals('test_file.txt', $method->invoke($manager, ' test file.txt '));
    }

    public function test_build_final_path_with_random_names(): void
    {
        $manager = new BrowserUploadManager($this->config);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('buildFinalPath');
        $method->setAccessible(true);
        
        $path = $method->invoke($manager, 'test.txt');
        $this->assertStringStartsWith('/uploads/', $path);
    }

    public function test_build_final_path_without_random_names(): void
    {
        $config = array_merge($this->config, ['generate_random_names' => false]);
        $manager = new BrowserUploadManager($config);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('buildFinalPath');
        $method->setAccessible(true);
        
        $path = $method->invoke($manager, 'test.txt');
        $this->assertStringStartsWith('/uploads/', $path);
    }

    public function test_get_progress_from_session(): void
    {
        $manager = new BrowserUploadManager($this->config);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('getProgressFromSession');
        $method->setAccessible(true);
        
        $session = [
            'total_chunks' => 5,
            'received_chunks' => [0, 1, 2]
        ];
        
        $progress = $method->invoke($manager, $session);
        $this->assertEquals(60, $progress); // (3/5) * 100
    }

    public function test_normalize_files_array(): void
    {
        $files = [
            'name' => ['test.txt', 'test2.txt'],
            'type' => ['text/plain', 'text/plain'],
            'tmp_name' => ['/tmp/test1.txt', '/tmp/test2.txt'],
            'error' => [0, 0],
            'size' => [100, 200]
        ];
        
        $manager = new BrowserUploadManager($this->config);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('normalizeFilesArray');
        $method->setAccessible(true);
        
        $result = $method->invoke($manager, $files);
        $this->assertCount(2, $result);
    }

    public function test_normalize_files_single(): void
    {
        $files = [
            'name' => 'test.txt',
            'type' => 'text/plain',
            'tmp_name' => '/tmp/test.txt',
            'error' => 0,
            'size' => 100
        ];
        
        $manager = new BrowserUploadManager($this->config);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('normalizeFilesArray');
        $method->setAccessible(true);
        
        $result = $method->invoke($manager, ['name' => ['test.txt']]);
        $this->assertCount(1, $result);
    }

    public function test_fail_method(): void
    {
        $manager = new BrowserUploadManager($this->config);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('fail');
        $method->setAccessible(true);
        
        $result = $method->invoke($manager, 'Test error message');
        $this->assertFalse($result['ok']);
        $this->assertEquals('Test error message', $result['error']);
    }

    public function test_ok_method(): void
    {
        $manager = new BrowserUploadManager($this->config);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('ok');
        $method->setAccessible(true);
        
        $result = $method->invoke($manager, ['test' => 'data']);
        $this->assertTrue($result['ok']);
        $this->assertEquals(['test' => 'data'], $result['data']);
    }
}
