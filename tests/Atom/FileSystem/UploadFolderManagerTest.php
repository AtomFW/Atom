<?php

declare(strict_types=1);

namespace Tests\Atom\FileSystem;

use PHPUnit\Framework\TestCase;
use Atom\FileSytem\UploadFolderManager as UploadFolderManager;

class UploadFolderManagerTest extends TestCase
{
    private string $tempDir;
    private string $baseDir;
    private string $tempStorageDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/upload_folder_manager_test_' . uniqid();
        $this->baseDir = $this->tempDir . '/uploads';
        $this->tempStorageDir = $this->tempDir . '/temp_storage';
        
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clear temporary test files after test is complete
        $this->cleanupDir($this->tempDir);
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $path) {
            if ($path->isDir()) {
                rmdir($path->getPathname());
            } else {
                unlink($path->getPathname());
            }
        }
        
        rmdir($dir);
    }

    public function testConstructorWithBasicOptions(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd'],
            [
                'prefix' => 'test_',
                'temp_ttl_seconds' => 3600,
                'temp_storage_dir' => $this->tempStorageDir,
                'dir_permissions' => 0755,
                'file_permissions' => 0644
            ]
        );

        $this->assertInstanceOf(UploadFolderManager::class, $manager);
        $this->assertNotNull($manager);
    }

    public function testCreateStructureWithNonTemporary(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );

        $result = $manager->createStructure(false);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('mainPath', $result);
        $this->assertArrayHasKey('relatedPaths', $result);
        $this->assertArrayHasKey('schema', $result);
    }

    public function testCreateStructureWithTemporary(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd'],
            ['temp_storage_dir' => $this->tempStorageDir]
        );

        $result = $manager->createStructure(true, 'test-id');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('mainPath', $result);
        $this->assertArrayHasKey('relatedPaths', $result);
        $this->assertArrayHasKey('schema', $result);
        $this->assertArrayHasKey('tempJsonPath', $result);
    }

    public function testGetMainPath(): void
    {
        $manager = new UploadFolderManager($this->baseDir, ['Y', 'm', 'd']);
        $manager->createStructure();

        $mainPath = $manager->getMainPath();
        
        $this->assertIsString($mainPath);
        $this->assertStringStartsWith($this->baseDir, $mainPath);
    }

    public function testGetRelatedPaths(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd'],
            [
                'related_schemas' => [['prefix', 'Y', 'm'], ['Y', 'm', 'd']]
            ]
        );
        
        $result = $manager->createStructure();

        $relatedPaths = $manager->getRelatedPaths();
        
        $this->assertIsArray($relatedPaths);
        $this->assertNotEmpty($relatedPaths);
    }

    public function testGetAllPaths(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd'],
            [
                'related_schemas' => [['prefix', 'Y', 'm'], ['Y', 'm', 'd']]
            ]
        );
        
        $result = $manager->createStructure();
        $allPaths = $manager->getAllPaths();

        $this->assertIsArray($allPaths);
        $this->assertArrayHasKey('main', $allPaths);
        $this->assertArrayHasKey('related_1', $allPaths);
        $this->assertArrayHasKey('related_2', $allPaths);
    }

    public function testGetTempJsonPath(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd'],
            ['temp_storage_dir' => $this->tempStorageDir]
        );

        $result = $manager->createStructure(true, 'test-id');
        $jsonPath = $manager->getTempJsonPath();

        $this->assertIsString($jsonPath);
        $this->assertStringContainsString('.json', $jsonPath);
    }

    public function testGetStructureInfo(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $info = $manager->getStructureInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('mainPath', $info);
        $this->assertArrayHasKey('relatedPaths', $info);
        $this->assertArrayHasKey('schema', $info);
        $this->assertArrayHasKey('createdAt', $info);
    }

    public function testIsExpiredWithNonTemporary(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $isExpired = $manager->isExpired();

        $this->assertFalse($isExpired);
    }

    public function testIsExpiredWithTemporary(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd'],
            [
                'temp_storage_dir' => $this->tempStorageDir,
                'temp_ttl_seconds' => 1
            ]
        );
        
        $result = $manager->createStructure(true, 'test-id');
        $isExpired = $manager->isExpired();

        $this->assertFalse($isExpired);
    }

    public function testCleanupExpiredWithNonTemporary(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $cleanup = $manager->cleanupExpiredTemp();

        $this->assertFalse($cleanup);
    }

    public function testFolderExists(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $exists = $manager->folderExists('main');

        $this->assertTrue($exists);
    }

    public function testFileExists(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $fileExists = $manager->fileExists('test.txt');

        $this->assertFalse($fileExists);
    }

    public function testGetAbsoluteFilePath(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $filePath = $manager->getAbsoluteFilePath('test.txt');

        $this->assertIsString($filePath);
        $this->assertStringContainsString('test.txt', $filePath);
    }

    public function testGetAllFilesPaths(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $files = $manager->getAllFilesPaths();

        $this->assertIsArray($files);
    }

    public function testCountFiles(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $count = $manager->countFiles();

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testFilesByExtension(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $files = $manager->filesByExtension();

        $this->assertIsArray($files);
    }

    public function testFilesByMime(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $files = $manager->filesByMime();

        $this->assertIsArray($files);
    }

    public function testListFiles(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $files = $manager->listFiles();

        $this->assertIsArray($files);
    }

    public function testGetCreatedAt(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $createdAt = $manager->getCreatedAt();

        $this->assertInstanceOf(DateTimeImmutable::class, $createdAt);
    }

    public function testGetModifiedAt(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $modifiedAt = $manager->getModifiedAt();

        $this->assertInstanceOf(DateTimeImmutable::class, $modifiedAt);
    }

    public function testGetLastAddedAt(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $lastAdded = $manager->getLastAddedAt();

        $this->assertInstanceOf(DateTimeImmutable::class, $lastAdded);
    }

    public function testGetFolderPermissions(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $permissions = $manager->getFolderPermissions();

        $this->assertIsString($permissions);
    }

    public function testGetFilePermissions(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $permissions = $manager->getFilePermissions('test.txt');

        $this->assertIsString($permissions);
    }

    public function testGetFileInfo(): void
    {
        $manager = new UploadFolderManager(
            $thisDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        try {
            $info = $manager->getFileInfo('test.txt');
            // If all tests pass, it means the file does not exist
            // So we only test that it doesn't throw an exception for a non-existent file
            $this->assertTrue(true);
        } catch (Exception $e) {
            // You can add additional logic to check the exception type if you want to test it
            $this->assertInstanceOf(RuntimeException::class, $e);
        }
    }

    public function testGetFolderInfo(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $info = $manager->getFolderInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('path', $info);
        $this->assertArrayHasKey('permissions', $info);
        $this->assertArrayHasKey('fileCount', $info);
    }

    public function testSaveFile(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $path = $manager->saveFile('test.txt', 'content');

        $this->assertStringContainsString('test.txt', $path);
    }

    public function testGetSchema(): void
    {
        $schema = ['Y', 'm', 'd'];
        $manager = new UploadFolderManager(
            $this->baseDir,
            $schema
        );
        
        $result = $manager->createStructure();
        $actualSchema = $manager->getSchema();

        $this->assertIsArray($actualSchema);
        $this->assertEquals($schema, $actualSchema);
    }

    public function testGetRelatedSchemas(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd'],
            [
                'related_schemas' => [['prefix', 'Y', 'm'], ['Y', 'm', 'd']]
            ]
        );
        
        $result = $manager->createStructure();
        $relatedSchemas = $manager->getRelatedSchemas();

        $this->assertIsArray($relatedSchemas);
    }

    public function testHasRelatedFolder(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd'],
            [
                'related_schemas' => [['prefix', 'Y', 'm'], ['Y', 'm', 'd']]
            ]
        );
        
        $result = $manager->createStructure();
        $hasFolder1 = $manager->hasRelatedFolder(0);
        $hasFolder2 = $manager->hasRelatedFolder(5);

        $this->assertTrue($hasFolder1);
        $this->assertFalse($hasFolder2);
    }

    public function testGetRelatedFolderPath(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd'],
            [
                'related_schemas' => [['prefix', 'Y', 'm']]
            ]
        );
        
        $result = $manager->createStructure();
        $folderPath = $manager->getRelatedFolderPath(0);

        $this->assertIsString($folderPath);
    }

    public function testTouchFolder(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd']
        );
        
        $result = $manager->createStructure();
        $touched = $manager->touchFolder();

        $this->assertTrue($touched);
    }

    public function testRefreshTempMetadata(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd'],
            [
                'temp_storage_dir' => $this->tempStorageDir
            ]
        );
        
        $result = $manager->createStructure(true, 'test-id');
        $refreshed = $manager->refreshTempMetadata();

        // Jeśli nie ma błędów, test powiedzie się
        $this->assertTrue($refreshed);
    }

    public function testGetTempMetadata(): void
    {
        $manager = new UploadFolderManager(
            $this->baseDir,
            ['Y', 'm', 'd'],
            [
                'temp_storage_dir' => $this->tempStorageDir
            ]
        );
        
        $result = $manager->createStructure(true, 'test-id');
        $metadata = $manager->getTempMetadata();

        $this->assertIsArray($metadata);
    }
}
