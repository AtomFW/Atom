<?php

declare(strict_types=1);

namespace Tests\Atom\Config;

use PHPUnit\Framework\TestCase;
use Atom\Config\LoadEnvironmentVariables;

/**
 * Test suite for the LoadEnvironmentVariables component.
 */
class LoadEnvironmentVariablesTest extends TestCase
{
    /**
     * Test check environment file exists returns false when file does not exist.
     */
    public function test_checkEnvironmentFileExistsReturnsFalseWhenFileDoesNotExist()
    {
        $result = $this->invokeMethod('checkEnvironmentFileExists', [__DIR__ . '/nonexistent.env']);
        $this->assertFalse($result);
    }

    /**
     * Test check environment file exists returns false when file is not readable.
     */
    public function test_checkEnvironmentFileExistsReturnsFalseWhenFileIsNotReadable()
    {
        $tempDir = __DIR__ . '/not-a-file.env';
        mkdir($tempDir);
        $result = $this->invokeMethod('checkEnvironmentFileExists', [$tempDir]);
        rmdir($tempDir);
        $this->assertFalse($result);
    }

    /**
     * Test check environment file exists returns true when file exists and is readable.
     */
    public function test_checkEnvironmentFileExistsReturnsTrueWhenFileExistsAndIsReadable()
    {
        $tempFile = __DIR__ . '/test_file.env';
        file_put_contents($tempFile, 'TEST=test');
        
        $result = $this->invokeMethod('checkEnvironmentFileExists', [$tempFile]);
        
        unlink($tempFile);
        
        $this->assertTrue($result);
    }

    /**
     * Test set environment path with empty path.
     */
    public function test_setEnvironmentPathWithEmptyPath()
    {
        $result = $this->invokeMethod('setEnvironmentPath', [null, __DIR__]);
        $this->assertNotNull($result);
    }

    /**
     * Test set environment path with path without env extension.
     */
    public function test_setEnvironmentPathWithPathWithoutEnvExtension()
    {
        $path = __DIR__ . '/test_file';
        $result = $this->invokeMethod('setEnvironmentPath', [$path, __DIR__]);
        $this->assertEquals($path . '.env', $result);
    }

    /**
     * Test set environment path with path ending with env.
     */
    public function test_setEnvironmentPathWithPathEndingWithEnv()
    {
        $path = __DIR__ . '/test_file.env';
        $result = $this->invokeMethod('setEnvironmentPath', [$path, __DIR__]);
        $this->assertEquals($path, $result);
    }

    /**
     * Test set environment path with trailing slash.
     */
    public function test_setEnvironmentPathWithTrailingSlash()
    {
        $path = __DIR__;
        $result = $this->invokeMethod('setEnvironmentPath', [$path, __DIR__]);
        // Should add the default .env extension
        $this->assertSame($path . '.env', $result);
    }

    /**
     * Helper method to invoke protected methods
     */
    private function invokeMethod($methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(LoadEnvironmentVariables::class);
        $method = $reflection->getMethod($methodName);
        return $method->invoke(null, ...$parameters);
    }
}
