<?php

declare(strict_types=1);

namespace Tests\Atom\Config;

use PHPUnit\Framework\TestCase;
use Atom\Config\Config;
use Atom\Exception\IO\Generative\FileNotFoundGenerativeException;

class ConfigTest extends TestCase
{
    private $appPath;

    protected function setUp(): void
    {
        $this->appPath = __DIR__ . '/test_fixtures';
        
        // Create test directory if it doesn't exist
        if (!is_dir($this->appPath)) {
            mkdir($this->appPath, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test files after each test
        $testFiles = [
            $this->appPath . '/config/app.php',
            $this->appPath . '/config/database.php',
            $this->appPath . '/.env'
        ];
        
        foreach ($testFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        
        // Clean up config cache directory
        $cacheDir = $this->appPath . '/runtime/cache/config';
        if (is_dir($cacheDir)) {
            $this->cleanupDirectory($cacheDir);
        }
    }

    private function cleanupDirectory($dir)
    {
        $files = array_filter(scandir($dir), function($file) {
            return $file !== '.' && $file !== '..';
        });
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->cleanupDirectory($path);
                rmdir($path);
            } else {
                unlink($path);
            }
        }
    }

    public function test_construct_LoadsConfigWhenAutoLoadIsTrue()
    {
        // Create a mock config file
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        // Mock the environment file
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $config = new Config($this->appPath, true, false);
        $this->assertInstanceOf(Config::class, $config);
    }

    public function test_construct_ThrowsExceptionWhenEnvironmentFileNotFound()
    {
        $this->expectException(FileNotFoundGenerativeException::class);
        $config = new Config($this->appPath, true, false);
    }

    public function test_get_ReturnsConfigValue_WhenKeyExists()
    {
        // Create a mock config file
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        // Mock the environment file
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $result = Config::get('app');
        $this->assertArrayHasKey('debug', $result);
    }

    public function test_get_ThrowsException_WhenKeyDoesNotExist()
    {
        // Create a mock config file
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        // Mock the environment file
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $this->expectException(\RuntimeException::class);
        Config::get('nonexistent');
    }

    public function test_checkConfigCacheIsAvailable_ReturnsTrue_WhenCacheExists()
    {
        // Create cache directory and file
        $cacheDir = $this->appPath . '/runtime/cache/config';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        
        $cacheFile = $cacheDir . '/database.php';
        file_put_contents($cacheFile, '<?php return [];');
        
        // Create a mock config file
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        // Mock the environment file
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $result = Config::checkConfigCacheIsAvailable($this->appPath);
        $this->assertTrue($result);
    }

    public function test_checkConfigCacheIsAvailable_ReturnsFalse_WhenCacheDoesNotExist()
    {
        // Create a mock config file
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        // Mock the environment file
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $result = Config::checkConfigCacheIsAvailable($this->appPath);
        $this->assertFalse($result);
    }

    public function test_getConfigFileNames_ReturnsCorrectArray()
    {
        $result = Config::getConfigFileNames();
        $expected = [
            'app',
            'database', 
            'cache',
            'session',
            'mail',
            'auth',
            'filesystem',
            'modul',
            'logger'
        ];
        
        $this->assertEquals($expected, $result);
    }

    public function test_autoLoad_WithCache_ReturnsTrue()
    {
        // Create cache directory and file
        $cacheDir = $this->appPath . '/runtime/cache/config';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        
        $cacheFile = $cacheDir . '/database.php';
        file_put_contents($cacheFile, '<?php return [];');
        
        // Create a mock config file
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        // Mock the environment file
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $result = Config::autoLoad($this->appPath, true, null);
        $this->assertTrue($result);
    }

    public function test_autoLoad_WithoutCache_ReturnsTrue()
    {
        // Create a mock config file
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        // Mock the environment file
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $result = Config::autoLoad($this->appPath, false, null);
        $this->assertTrue($result);
    }
}
