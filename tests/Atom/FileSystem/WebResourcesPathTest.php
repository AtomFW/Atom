<?php

declare(strict_types=1);

namespace Tests\Atom\FileSytem;

use PHPUnit\Framework\TestCase;
use Atom\FileSytem\WebResourcesPath;

class WebResourcesPathTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset static properties before each test
        WebResourcesPath::setInstance(null, null);
    }

    public function test_constructor_sets_up_paths_correctly(): void
    {
        $path = new WebResourcesPath('/test/path/', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => false,
            'singleFile' => false
        ]);

        $paths = $path->all();

        $this->assertEquals('/test/path/resources/', $paths['resources']);
        $this->assertEquals('/test/path/storage/assets/', $paths['assets']);
        $this->assertEquals('/test/path/resources/css/', $paths['css']);
        $this->assertEquals('/test/path/resources/js/', $paths['js']);
    }

    public function test_constructor_handles_config_shring_with_on_true(): void
    {
        $path = new WebResourcesPath('/test/path/', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => true,
            'singleFile' => false,
            'cssSingleFileName' => 'style',
            'jsSingleFileName' => 'app'
        ]);

        $paths = $path->all();

        // Should use assets path when configShring['on'] is true
        $this->assertEquals('/test/path/storage/assets/', $paths['assets']);
        $this->assertEquals('/test/path/storage/assets/css/', $paths['css']);
        $this->assertEquals('/test/path/storage/assets/js/', $paths['js']);
    }

    public function test_constructor_handles_single_file_mode(): void
    {
        $path = new WebResourcesPath('/test/path/', [
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

    public function test_all_method_returns_all_paths(): void
    {
        $path = new WebResourcesPath('/test/path/', [
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

    public function test_toString_returns_path_source(): void
    {
        $path = new WebResourcesPath('/test/path/', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => false,
            'singleFile' => false
        ]);

        $this->assertEquals('/test/path/resources/', (string)$path);
    }

    public function test_getter_returns_correct_path(): void
    {
        $path = new WebResourcesPath('/test/path/', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => false,
            'singleFile' => false
        ]);

        // Test direct access to path property
        $this->assertEquals('/test/path/resources/', $path->resources);
        
        // Test specific paths
        $this->assertEquals('/test/path/resources/css/', $path->css);
        $this->assertEquals('/test/path/resources/js/', $path->js);
        $this->assertEquals('/test/path/storage/assets/css/', $path->assetsCssDirPath);
    }

    public function test_getter_with_invalid_path_returns_all(): void
    {
        $path = new WebResourcesPath('/test/path/', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => false,
            'singleFile' => false
        ]);

        // Should return all paths for unknown property
        $result = $path->invalidProperty;
        $this->assertIsArray($result);
    }

    public function test_path_source_changes_with_config(): void
    {
        // Test resources path (when on === false)
        $path1 = new WebResourcesPath('/test/path/', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => false,
            'singleFile' => false
        ]);
        
        $this->assertEquals('/test/path/resources/', $path1->__get('path'));

        // Test assets path (when on === true)
        $path2 = new WebResourcesPath('/test/path/', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => true,
            'singleFile' => false
        ]);
        
        $this->assertEquals('/test/path/storage/assets/', $path2->__get('path'));
    }

    public function test_web_manifest_path(): void
    {
        $path = new WebResourcesPath('/test/path/', [
            'rootCssDirName' => 'build',
            'rootJsDirName' => 'js',
            'on' => false,
            'singleFile' => false
        ]);

        $paths = $path->all();
        $this->assertEquals('/test/path/storage/assets/manifest/manifest.json', $paths['webManifest']);
    }

    public function test_default_constructor_values(): void
    {
        // Test with null constructor values
        $path = new WebResourcesPath();

        // Should default to resources path when no path provided
        $this->assertInstanceOf(WebResourcesPath::class, $path);
    }
}
