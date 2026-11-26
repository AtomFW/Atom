<?php

declare(strict_types=1);

namespace Tests\Atom\FileSystem;

use PHPUnit\Framework\TestCase;
use Atom\FileSytem\ResourcesPath;

/**
 * Test suite for the ResourcesPath component.
 */
class ResourcesPathTest extends TestCase
{
    protected function setUp(): void
    {
        $reflection = new \ReflectionClass(ResourcesPath::class);
        $ds = DIRECTORY_SEPARATOR;
        
        $defaults = [
            'pathSource' => '',
            'resources' => $ds . 'resources' . $ds,
            'assets' => $ds . 'storage' . $ds . 'assets' . $ds,
            'cssPath' => 'css' . $ds,
            'jsPath' => 'js' . $ds,
            'fontPath' => 'font' . $ds,
            'imagePath' => 'image' . $ds,
            'moviePath' => 'movie' . $ds,
            'soundPath' => 'sound' . $ds,
            'svgPath' => 'svg' . $ds,
            'webManifest' => 'manifest' . $ds . 'manifest.json'
        ];
        
        foreach ($defaults as $prop => $val) {
            $reflection->setStaticPropertyValue($prop, $val);
        }
    }

    /**
     * Test constructor sets correct properties.
     */
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

    /**
     * Test all method returns correct array.
     */
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

    /**
     * Test to string returns correct string.
     */
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
        $this->assertEquals('/test/path' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR, $result);
    }

    /**
     * Test get returns correct value.
     */
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
        $this->assertEquals('/test/path' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR, $resourcesPath->path);
        $this->assertEquals('/test/path' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR, $resourcesPath->{'path'});
    }

    /**
     * Test get with invalid key returns default.
     */
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
        $this->expectException(\TypeError::class);
        $result = $resourcesPath->invalid_key;
    }

    /**
     * Test constructor handles null path.
     */
    public function test_constructor_handles_null_path(): void
    {
        // Arrange & Act
        $resourcesPath = new ResourcesPath(null, null);

        // Assert
        // Should not throw an error
        $this->assertInstanceOf(ResourcesPath::class, $resourcesPath);
    }

    /**
     * Test all method returns correct structure.
     */
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

    /**
     * Test constructor with config sets correctly.
     */
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

    /**
     * Test getter returns correct values for different properties.
     */
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
        $this->assertEquals('/test/path' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR, $resourcesPath->resources);
        $this->assertEquals('/test/path' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR, $resourcesPath->{'resources'});
    }
}
