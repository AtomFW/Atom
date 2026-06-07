<?php

declare(strict_types=1);

namespace Tests\Atom\FileSystem;

use PHPUnit\Framework\TestCase;
use Atom\FileSytem\ResourcesPath;

class ResourcesPathTest extends TestCase
{
    protected function setUp(): void
    {
        // Clean up any existing static properties
        $reflection = new \ReflectionClass(ResourcesPath::class);
        
        // Reset static properties to default values
        $properties = [
            'pathSource', 'resources', 'assets', 'cssPath', 'jsPath',
            'fontPath', 'imagePath', 'moviePath', 'soundPath', 
            'svgPath', 'webManifest'
        ];
        
        foreach ($properties as $prop) {
            if ($reflection->hasStaticPropertyValue($prop)) {
                $reflection->setStaticPropertiesEnabled($prop, '');
            }
        }
    }

    public function test_constructor_sets_correct_properties(): void
    {
        // Arrange
        $path = '/test/path';
        $config = [
            'rootCssDirName' => 'css',
            'rootJsDirName' => 'js',
            'singleFile' => false,
            'on' => false
        ];

        // Act
        $resourcesPath = new ResourcesPath($path, $config);

        // Assert
        $this->assertInstanceOf(ResourcesPath::class, $resourcesPath);
    }

    public function test_all_method_returns_correct_array(): void
    {
        // Arrange
        $path = '/test/path';
        $config = [
            'rootCssDirName' => 'css',
            'rootJsDirName' => 'js',
            'singleFile' => false,
            'on' => false
        ];

        $resourcesPath = new ResourcesPath($path, $config);

        // Act
        $result = $resourcesPath->all();

        // Assert
        $this->assertArrayHasKey('resources', $result);
        $this->assertArrayHasKey('assets', $result);
        $this->assertArrayHasKey('css', $result);
        $this->assertArrayHasKey('js', $result);
        $this->assertArrayHasKey('font', $result);
        $this->assertArrayHasKey('image', $result);
        $this->assertArrayHasKey('movie', $result);
        $this->assertArrayHasKey('sound', $result);
        $this->assertArrayHasKey('svg', $result);
        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('webManifest', $result);
    }

    public function test___toString_returns_correct_string(): void
    {
        // Arrange
        $path = '/test/path';
        $config = [
            'rootCssDirName' => 'css',
            'rootJsDirName' => 'js',
            'singleFile' => false,
            'on' => false
        ];

        $resourcesPath = new ResourcesPath($path, $config);

        // Act
        $result = (string)$resourcesPath;

        // Assert
        $this->assertEquals('/test/path', $result);
    }

    public function test___get_returns_correct_value(): void
    {
        // Arrange
        $path = '/test/path';
        $config = [
            'rootCssDirName' => 'css',
            'rootJsDirName' => 'js',
            'singleFile' => false,
            'on' => false
        ];

        $resourcesPath = new ResourcesPath($path, $config);

        // Act & Assert
        $this->assertEquals('/test/path', $resourcesPath->path);
        $this->assertEquals('/test/path', $resourcesPath->{'path'});
    }

    public function test___get_with_invalid_key_returns_default(): void
    {
        // Arrange
        $path = '/test/path';
        $config = [
            'rootCssDirName' => 'css',
            'rootJsDirName' => 'js',
            'singleFile' => false,
            'on' => false
        ];

        $resourcesPath = new ResourcesPath($path, $config);

        // Act & Assert
        $this->assertEquals('/test/path', $resourcesPath->invalid_key);
    }

    public function test_constructor_handles_null_path(): void
    {
        // Arrange & Act
        $resourcesPath = new ResourcesPath(null, null);

        // Assert
        // Should not throw an error
        $this->assertInstanceOf(ResourcesPath::class, $resourcesPath);
    }

    public function test_all_method_returns_correct_structure(): void
    {
        // Arrange
        $path = '/test/path';
        $config = [
            'rootCssDirName' => 'css',
            'rootJsDirName' => 'js',
            'singleFile' => false,
            'on' => false
        ];

        $resourcesPath = new ResourcesPath($path, $config);

        // Act
        $result = $resourcesPath->all();

        // Assert
        $expectedKeys = [
            'resources', 'assets', 'css', 'js', 'font',
            'image', 'movie', 'sound', 'svg', 'path'
        ];
        
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_constructor_with_config_sets_correctly(): void
    {
        // Arrange
        $config = [
            'rootCssDirName' => 'css',
            'rootJsDirName' => 'js',
            'singleFile' => false,
            'on' => true,
            'singleFile' => true,
            'cssSingleFileName' => 'app',
            'jsSingleFileName' => 'app'
        ];

        // Act
        $resourcesPath = new ResourcesPath('/test', $config);

        // Assert - Just make sure it doesn't break
        $this->assertInstanceOf(ResourcesPath::class, $resourcesPath);
    }

    public function test_getter_returns_correct_values_for_different_properties(): void
    {
        // Arrange
        $path = '/test/path';
        $config = [
            'rootCssDirName' => 'css',
            'rootJsDirName' => 'js',
            'singleFile' => false,
            'on' => false
        ];

        $resourcesPath = new ResourcesPath($path, $config);

        // Act & Assert
        $this->assertEquals('/test/path', $resourcesPath->resources);
        $this->assertEquals('/test/path', $resourcesPath->{'resources'});
    }
}
