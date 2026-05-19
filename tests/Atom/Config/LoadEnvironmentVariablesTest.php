<?php

declare(strict_types=1);

namespace Tests\Atom\Config;

use PHPUnit\Framework\TestCase;
use Atom\Config\LoadEnvironmentVariables;

class LoadEnvironmentVariablesTest extends TestCase
{
    public function test_checkEnvironmentFileExistsReturnsFalseWhenFileDoesNotExist()
    {
        $result = $this->invokeMethod('checkEnvironmentFileExists', [__DIR__ . '/nonexistent.env']);
        $this->assertFalse($result);
    }

    public function test_checkEnvironmentFileExistsReturnsFalseWhenFileIsNotReadable()
    {
        // Create a temporary file and then remove it to simulate the test
        $tempFile = __DIR__ . '/test_file.env';
        \file_put_contents($tempFile, 'TEST=test');
        
        // Make the file not readable by changing permissions
        chmod($tempFile, 0200); // Write-only for owner
        
        $result = $this->invokeMethod('checkEnvironmentFileExists', [$tempFile]);
        
        // Restore original permissions before cleanup
        unlink($tempFile);
        chmod($tempFile, 0644);
        
        $this->assertFalse($result);
    }

    public function test_checkEnvironmentFileExistsReturnsTrueWhenFileExistsAndIsReadable()
    {
        $tempFile = __DIR__ . '/test_file.env';
        file_put_contents($tempFile, 'TEST=test');
        
        $result = $this->invokeMethod('checkEnvironmentFileExists', [$tempFile]);
        
        unlink($tempFile);
        
        $this->assertTrue($result);
    }

    public function test_setEnvironmentPathWithEmptyPath()
    {
        $result = $this->invokeMethod('setEnvironmentPath', [null, __DIR__]);
        $this->assertStringEndsNotNull($result);
    }

    public function test_setEnvironmentPathWithPathWithoutEnvExtension()
    {
        $path = __DIR__ . '/test_file';
        $result = $this->invokeMethod('setEnvironmentPath', [$path, __DIR__]);
        $this->assertEquals($path . '.env', $result);
    }

    public function test_setEnvironmentPathWithPathEndingWithEnv()
    {
        $path = __DIR__ . '/test_file.env';
        $result = $this->invokeMethod('setEnvironmentPath', [$path, __DIR__]);
        $this->assertEquals($path, $result);
    }

    public function test_setEnvironmentPathWithTrailingSlash()
    {
        $path = __DIR__;
        $result = $this->invokeMethod('setEnvironmentPath', [$path, __DIR__]);
        // Should add the default .env extension
        $this->assertStringEndsNotEquals('.env', $result);
    }

    /**
     * Helper method to invoke protected methods
     */
    private function invokeMethod($methodName, array $parameters = [])
    {
        $reflection = new ReflectionClass(LoadEnvironmentVariables::class);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invoke(null, ...$parameters);
    }
}
