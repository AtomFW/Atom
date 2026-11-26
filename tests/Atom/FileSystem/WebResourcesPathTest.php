<?php

declare(strict_types=1);

namespace Tests\Atom\FileSytem;

use PHPUnit\Framework\TestCase;
use Atom\FileSytem\WebResourcesPath;

/**
 * Test suite for the WebResourcesPath component.
 */
class WebResourcesPathTest extends TestCase
{
    protected function setUp(): void
    {
        $reflection = new \ReflectionClass(WebResourcesPath::class);
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
     * Test constructor sets up paths correctly.
     */
    public function test_constructor_sets_up_paths_correctly(): void
    {
        $path = new WebResourcesPath('/test/path', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => false,
            'singleFile' => false
        ]);

        $paths = $path->all();

        $ds = DIRECTORY_SEPARATOR;
        $this->assertEquals('/test/path' . $ds . 'resources' . $ds, $paths['resources']);
        $this->assertEquals('/test/path' . $ds . 'storage' . $ds . 'assets' . $ds, $paths['assets']);
        $this->assertEquals('/test/path' . $ds . 'resources' . $ds . 'css' . $ds, $paths['css']);
        $this->assertEquals('/test/path' . $ds . 'resources' . $ds . 'js' . $ds, $paths['js']);
    }

    /**
     * Test constructor handles config shring with on true.
     */
    public function test_constructor_handles_config_shring_with_on_true(): void
    {
        $path = new WebResourcesPath('/test/path', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => true,
            'singleFile' => false,
            'cssSingleFileName' => 'style',
            'jsSingleFileName' => 'app'
        ]);

        $paths = $path->all();
        $ds = DIRECTORY_SEPARATOR;

        // Should use assets path when configShring['on'] is true
        $this->assertEquals('/test/path' . $ds . 'storage' . $ds . 'assets' . $ds, $paths['assets']);
        $this->assertEquals('/test/path' . $ds . 'storage' . $ds . 'assets' . $ds . 'css' . $ds, $paths['css']);
        $this->assertEquals('/test/path' . $ds . 'storage' . $ds . 'assets' . $ds . 'js' . $ds, $paths['js']);
    }

    /**
     * Test constructor handles single file mode.
     */
    public function test_constructor_handles_single_file_mode(): void
    {
        $path = new WebResourcesPath('/test/path', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => true,
            'singleFile' => true,
            'cssSingleFileName' => 'style',
            'jsSingleFileName' => 'app'
        ]);

        $paths = $path->all();

        // Should use single file paths when configShring['singleFile'] is true
        $this->assertStringEndsWith('style.min.css', $paths['css']);
        $this->assertStringEndsWith('app.min.js', $paths['js']);
    }

    /**
     * Test all method returns all paths.
     */
    public function test_all_method_returns_all_paths(): void
    {
        $path = new WebResourcesPath('/test/path', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => false,
            'singleFile' => false
        ]);

        $paths = $path->all();

        $expectedPaths = [
            'resources', 'resourcesCssDirPath', 'resourcesCssRootDirPath',
            'resourcesJsDirPath', 'resourcesJsRootDirPath', 'assets',
            'assetsCssDirPath', 'assetsCssRootMainPath', 'assetsJsDirPath',
            'assetsJsRootMainPath', 'css', 'js', 'font', 'image', 'movie',
            'sound', 'svg', 'path', 'webManifest'
        ];

        foreach ($expectedPaths as $expectedPath) {
            $this->assertArrayHasKey($expectedPath, $paths);
        }
    }

    /**
     * Test to string returns path source.
     */
    public function test_toString_returns_path_source(): void
    {
        $path = new WebResourcesPath('/test/path', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => false,
            'singleFile' => false
        ]);

        $ds = DIRECTORY_SEPARATOR;
        $this->assertEquals('/test/path' . $ds . 'resources' . $ds, (string)$path);
    }

    /**
     * Test getter returns correct path.
     */
    public function test_getter_returns_correct_path(): void
    {
        $path = new WebResourcesPath('/test/path', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => false,
            'singleFile' => false
        ]);

        $ds = DIRECTORY_SEPARATOR;
        // Test direct access to path property
        $this->assertEquals('/test/path' . $ds . 'resources' . $ds, $path->resources);
        
        // Test specific paths
        $this->assertEquals('/test/path' . $ds . 'resources' . $ds . 'css' . $ds, $path->css);
        $this->assertEquals('/test/path' . $ds . 'resources' . $ds . 'js' . $ds, $path->js);
        $this->assertEquals('/test/path' . $ds . 'storage' . $ds . 'assets' . $ds . 'css' . $ds, $path->assetsCssDirPath);
    }

    /**
     * Test getter with invalid path returns all.
     */
    public function test_getter_with_invalid_path_returns_all(): void
    {
        $path = new WebResourcesPath('/test/path', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => false,
            'singleFile' => false
        ]);

        // Should return all paths for unknown property
        $this->expectException(\TypeError::class);
        $result = $path->invalidProperty;
    }

    /**
     * Test path source changes with config on false.
     */
    public function test_path_source_changes_with_config_on_false(): void
    {
        $ds = DIRECTORY_SEPARATOR;
        // Test resources path (when on === false)
        $path1 = new WebResourcesPath('/test/path', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => false,
            'singleFile' => false
        ]);
        
        $this->assertEquals('/test/path' . $ds . 'resources' . $ds, $path1->__get('path'));
    }

    /**
     * Test path source changes with config on true.
     */
    public function test_path_source_changes_with_config_on_true(): void
    {
        $ds = DIRECTORY_SEPARATOR;
        // Test assets path (when on === true)
        $path2 = new WebResourcesPath('/test/path', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => true,
            'singleFile' => false
        ]);
        
        $this->assertEquals('/test/path' . $ds . 'storage' . $ds . 'assets' . $ds, $path2->__get('path'));
    }

    /**
     * Test web manifest path.
     */
    public function test_web_manifest_path(): void
    {
        $path = new WebResourcesPath('/test/path', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => false,
            'singleFile' => false
        ]);

        $paths = $path->all();
        $ds = DIRECTORY_SEPARATOR;
        $this->assertEquals('/test/path' . $ds . 'storage' . $ds . 'assets' . $ds . 'manifest' . $ds . 'manifest.json', $paths['webManifest']);
    }

    /**
     * Test default constructor values.
     */
    public function test_default_constructor_values(): void
    {
        // Test with null constructor values
        $path = new WebResourcesPath();

        // Should default to resources path when no path provided
        $this->assertInstanceOf(WebResourcesPath::class, $path);
    }
}
