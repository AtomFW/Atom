<?php

declare(strict_types=1);

namespace Tests\Atom\Config;

use PHPUnit\Framework\TestCase;
use Atom\Config\Config;
use Atom\Exception\IO\Generative\FileNotFoundGenerativeException;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ConfigTest extends TestCase
{
    private $appPath;

    protected function setUp(): void
    {
        $this->appPath = __DIR__ . '/test_fixtures_' . uniqid();
        
        if (!is_dir($this->appPath)) {
            mkdir($this->appPath, 0777, true);
        }
        
        $this->initializeConfigFixtures();
    }

    protected function initializeConfigFixtures(): void
    {
        $configDir = $this->appPath . '/config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0777, true);
        }
        foreach (['app', 'database', 'cache', 'session', 'mail', 'auth', 'filesystem', 'modul', 'logger'] as $name) {
            $file = $configDir . '/' . $name . '.php';
            if (!file_exists($file)) {
                file_put_contents($file, '<?php return [];' );
            }
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->appPath)) {
            $this->cleanupDirectory($this->appPath);
            rmdir($this->appPath);
        }

        // Reset static property Config::$from using reflection to prevent test interference
        $reflection = new \ReflectionClass(Config::class);
        $property = $reflection->getProperty('from');
        $property->setValue([]);
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

    private function createCacheFiles(string $cacheDir): void
    {
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        foreach (['app', 'database', 'cache', 'session', 'mail', 'auth', 'filesystem', 'modul', 'logger'] as $name) {
            file_put_contents($cacheDir . '/' . $name . '.php', '<?php return [];' );
        }
    }

    private function invokeStatic(string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass(Config::class);
        $method = $reflection->getMethod($methodName);
        return $method->invoke(null, ...$parameters);
    }

    /**
     * Test construct loads config when auto load is true.
     */
    public function test_construct_LoadsConfigWhenAutoLoadIsTrue()
    {
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $config = new Config($this->appPath, true, false);
        $this->assertInstanceOf(Config::class, $config);
    }

    /**
     * Test construct throws exception when environment file not found.
     */
    public function test_construct_ThrowsExceptionWhenEnvironmentFileNotFound()
    {
        $this->expectException(FileNotFoundGenerativeException::class);
        $config = new Config($this->appPath, true, false);
    }

    /**
     * Test get returns config value when key exists.
     */
    public function test_get_ReturnsConfigValue_WhenKeyExists()
    {
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $config = new Config($this->appPath, true, false);
        $result = Config::get('app');
        $this->assertArrayHasKey('app', $result);
    }

    /**
     * Test get throws exception when key does not exist.
     */
    public function test_get_ThrowsException_WhenKeyDoesNotExist()
    {
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $this->expectException(\RuntimeException::class);
        Config::get('nonexistent');
    }

    /**
     * Test check config cache is available returns true when cache exists.
     */
    public function test_checkConfigCacheIsAvailable_ReturnsTrue_WhenCacheExists()
    {
        $cacheDir = $this->appPath . '/runtime/cache/config';
        $this->createCacheFiles($cacheDir);
        
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $result = $this->invokeStatic('checkConfigCacheIsAvailable', [$this->appPath]);
        $this->assertTrue($result);
    }

    /**
     * Test check config cache is available returns false when cache does not exist.
     */
    public function test_checkConfigCacheIsAvailable_ReturnsFalse_WhenCacheDoesNotExist()
    {
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $result = $this->invokeStatic('checkConfigCacheIsAvailable', [$this->appPath]);
        $this->assertFalse($result);
    }

    /**
     * Test get config file names returns correct array.
     */
    public function test_getConfigFileNames_ReturnsCorrectArray()
    {
        $result = $this->invokeStatic('getConfigFileNames');
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

    /**
     * Test auto load with cache returns true.
     */
    public function test_autoLoad_WithCache_ReturnsTrue()
    {
        $cacheDir = $this->appPath . '/runtime/cache/config';
        $this->createCacheFiles($cacheDir);
        
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $result = Config::autoLoad($this->appPath, true, null);
        $this->assertTrue($result);
    }

    /**
     * Test auto load without cache returns true.
     */
    public function test_autoLoad_WithoutCache_ReturnsTrue()
    {
        $configContent = "<?php return [
            'app' => [
                'debug' => true,
                'url' => 'http://localhost'
            ]
        ];";
        
        file_put_contents($this->appPath . '/config/app.php', $configContent);
        
        $envContent = "APP_DEBUG=true\nAPP_URL=http://localhost\n";
        file_put_contents($this->appPath . '/.env', $envContent);
        
        $result = Config::autoLoad($this->appPath, false, null);
        $this->assertTrue($result);
    }
}
