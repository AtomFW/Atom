<?php

declare(strict_types=1);

namespace Tests\Atom\Generate\Manifest;

use PHPUnit\Framework\TestCase;
use Atom\Generate\Manifest\WebAppManifest;

class WebAppManifestTest extends TestCase
{
    public function testConstructorWithInitialData()
    {
        $data = ['name' => 'Test App', 'short_name' => 'Test'];
        $manifest = new WebAppManifest($data);
        
        $this->assertEquals('Test App', $manifest->getName());
        $this->assertEquals('Test', $manifest->getShortName());
    }

    public function testFromArrayFactory()
    {
        $data = [
            'name' => 'Test App',
            'short_name' => 'Test'
        ];
        
        $manifest = WebAppManifest::fromArray($data);
        
        $this->assertEquals('Test App', $manifest->getName());
        $this->assertEquals('Test', $manifest->getShortName());
    }

    public function testMergeMethod()
    {
        $manifest = new WebAppManifest();
        $manifest->merge(['name' => 'Original Name']);
        
        $this->assertEquals('Original Name', $manifest->getName());
        
        $manifest->merge(['description' => 'Test description']);
        $this->assertEquals('Original Name', $manifest->getName());
        $this->assertEquals('Test description', $manifest->getDescription());
    }

    public function testSettersAndGetters()
    {
        $manifest = new WebAppManifest();
        
        // Test name setters/getters
        $manifest->setName('Test App');
        $this->assertEquals('Test App', $manifest->getName());
        
        $manifest->setShortName('Test');
        $this->assertEquals('Test', $manifest->getShortName());
        
        $manifest->setStartUrl('/app');
        $this->assertEquals('/app', $manifest->getStartUrl());
        
        $manifest->setScope('/app');
        $this->assertEquals('/app', $manifest->getScope());
        
        $manifest->setDisplay('standalone');
        $this->assertEquals('standalone', $manifest->getDisplay());
        
        $manifest->setThemeColor('#ffffff');
        $this->assertEquals('#ffffff', $manifest->getThemeColor());
        
        $manifest->setBackgroundColor('#000000');
        $this->assertEquals('#000000', $manifest->getBackgroundColor());
        
        $manifest->setDescription('Test description');
        $this->assertEquals('Test description', $manifest->getDescription());
        
        $manifest->setLang('en');
        $this->assertEquals('en', $manifest->getLang());
        
        $manifest->setDir('ltr');
        $this->assertEquals('ltr', $manifest->getDir());
    }

    public function testCategories()
    {
        $manifest = new WebAppManifest();
        
        // Test setting categories
        $categories = ['education', 'lifestyle'];
        $manifest->setCategories($categories);
        $this->assertEquals($categories, $manifest->getCategories());
        
        // Test adding a category
        $manifest->addCategory('utilities');
        $this->assertContains('utilities', $manifest->getCategories());
        
        // Test adding duplicate category (should not be added)
        $manifest->addCategory('education'); // already exists
        $categories = $manifest->getCategories();
        $this->assertCount(3, $categories); // Should still be 3 items
    }

    public function testIcons()
    {
        $manifest = new WebAppManifest();
        
        // Test adding a single icon
        $manifest->addIcon('/icon.png', '192x192', 'image/png');
        $icons = $manifest->getIcons();
        $this->assertCount(1, $icons);
        $this->assertEquals('/icon.png', $icons[0]['src']);
        $this->assertEquals('192x192', $icons[0]['sizes']);
        $this->assertEquals('image/png', $icons[0]['type']);
        
        // Test adding icon with additional attributes
        $manifest->addIcon('/icon2.png', '384x384', 'image/png', ['purpose' => 'maskable']);
        $icons = $manifest->getIcons();
        $this->assertCount(2, $icons);
        $this->assertEquals('maskable', $icons[1]['purpose']);
        
        // Test adding duplicate icon (should replace)
        $manifest->addIcon('/icon.png', '192x192', 'image/png');
        $this->assertCount(2, $manifest->getIcons());
    }

    public function testRemoveIcons()
    {
        $manifest = new WebAppManifest();
        $manifest->addIcon('/icon1.png', '192x192');
        $manifest->addIcon('/icon2.png', '384x384');
        
        // Remove by src
        $manifest->removeIcon('/icon1.png');
        $this->assertCount(1, $manifest->getIcons());
        
        // Remove all icons
        $manifest->removeIcon();
        $this->assertEmpty($manifest->getIcons());
    }

    public function testShortcuts()
    {
        $shortcut = [
            'name' => 'Open search',
            'short_name' => 'Search',
            'url' => '/search'
        ];
        
        $manifest = new WebAppManifest();
        $manifest->addShortcut($shortcut);
        
        $shortcuts = $manifest->getShortcuts();
        $this->assertCount(1, $shortcuts);
        $this->assertEquals('Open search', $shortcuts[0]['name']);
    }

    public function testRelatedApplications()
    {
        $manifest = new WebAppManifest();
        $manifest->addRelatedApplication('play', 'com.example.app', ['url' => 'https://example.com']);
        
        $apps = $manifest->getRelatedApplications();
        $this->assertCount(1, $apps);
        $this->assertEquals('play', $apps[0]['platform']);
        $this->assertEquals('com.example.app', $apps[0]['id']);
    }

    public function testScreenshots()
    {
        $manifest = new WebAppManifest();
        $manifest->addScreenshot('/screenshot.png', '1280x720', 'image/png');
        
        $screenshots = $manifest->getScreenshots();
        $this->assertCount(1, $screenshots);
        $this->assertEquals('/screenshot.png', $screenshots[0]['src']);
    }

    public function testShareTarget()
    {
        $manifest = new WebAppManifest();
        $config = [
            'action' => '/share',
            'method' => 'GET',
            'params' => ['title' => 'title']
        ];
        
        $manifest->setShareTarget($config);
        $this->assertEquals($config, $manifest->getShareTarget());
    }

    public function testToArray()
    {
        $data = [
            'name' => 'Test App',
            'short_name' => 'Test'
        ];
        
        $manifest = new WebAppManifest($data);
        $array = $manifest->toArray();
        
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('short_name', $array);
        $this->assertEquals('Test App', $array['name']);
        $this->assertEquals('Test', $array['short_name']);
    }

    public function testToJson()
    {
        $data = [
            'name' => 'Test App',
            'short_name' => 'Test'
        ];
        
        $manifest = new WebAppManifest($data);
        $json = $manifest->toJson();
        
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertEquals('Test App', $decoded['name']);
        $this->assertEquals('Test', $decoded['short_name']);
    }

    public function testValidation()
    {
        $manifest = new WebAppManifest();
        $errors = $manifest->validate();
        
        // Should have errors since name is required
        $this->assertNotEmpty($errors);
        
        // After setting name, should be valid
        $manifest->setName('Valid App');
        $errors = $manifest->validate();
        $this->assertEmpty($errors);
    }

    public function testValidationWithMissingNameAndShortName()
    {
        $manifest = new WebAppManifest([
            'start_url' => '/app'
        ]);
        
        $errors = $manifest->validate();
        $this->assertNotEmpty($errors);
        $this->assertContains('Either "name" or "short_name" should be provided.', $errors);
    }

    public function testValidationWithInvalidDisplay()
    {
        $manifest = new WebAppManifest([
            'name' => 'Test',
            'start_url' => '/app',
            'display' => 'invalid_display'
        ]);
        
        $errors = $manifest->validate();
        $this->assertNotEmpty($errors);
        $this->assertContains('"display" must be one of:', $errors[0]);
    }

    public function testCommonSetters()
    {
        $data = [
            'name' => 'Test App',
            'short_name' => 'Test',
            'start_url' => '/app',
            'description' => 'Test description'
        ];
        
        $manifest = new WebAppManifest();
        $manifest->setCommon($data);
        
        $this->assertEquals('Test App', $manifest->getName());
        $this->assertEquals('Test', $manifest->getShortName());
        $this->assertEquals('/app', $manifest->getStartUrl());
        $this->assertEquals('Test description', $manifest->getDescription());
    }

    public function testToString()
    {
        $manifest = new WebAppManifest([
            'name' => 'Test App'
        ]);
        
        $jsonString = (string)$manifest;
        $this->assertIsString($jsonString);
        $this->assertStringContainsString('Test App', $jsonString);
    }
}
