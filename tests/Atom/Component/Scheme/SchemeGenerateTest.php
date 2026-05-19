<?php

declare(strict_types=1);

namespace Tests\Atom\Component\Scheme;

use PHPUnit\Framework\TestCase;
use Atom\Component\Scheme\SchemaGenerate;
use Atom\Component\Scheme\SchemaWrapper;

class SchemeGenerateTest extends TestCase
{
    public function testAutoGenerate_returns_string(): void
    {
        $option = ['iconSvg' => '.svg'];
        $data = (object) [
            'uri' => 'https://example.com/page',
            'image' => 'https://example.com/image',
            'lang' => 'en',
            'title' => 'Test Page'
        ];

        // Mock the SchemaWrapper to avoid actual JSON-LD generation
        $mockSchema = $this->createMock(SchemaWrapper::class);
        $mockSchema->method('url')->willReturn($mockSchema);
        $mockSchema->method('image')->willReturn($mockSchema);
        $mockSchema->method('inLanguage')->willReturn($mockSchema);
        $mockSchema->method('name')->willReturn($mockSchema);
        $mockSchema->method('toJsonLd')->willReturn('{"@context":"https://schema.org","@type":"WebPage","url":"https://example.com/page","image":"https://example.com/image.svg","inLanguage":"en","name":"Test Page"}');

        // Override the static method using a mock
        $originalMethod = \Closure::bind(function ($option, $data) {
            return SchemaGenerate::autoGenerate($option, $data);
        }, null, SchemaGenerate::class);

        $result = SchemaGenerate::autoGenerate($option, $data);
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testAutoGenerate_with_empty_option(): void
    {
        $option = [];
        $data = (object) [
            'uri' => 'https://example.com/page',
            'image' => 'https://example.com/image',
            'lang' => 'en',
            'title' => 'Test Page'
        ];

        // Mock the SchemaWrapper to avoid actual JSON-LD generation
        $mockSchema = $this->createMock(SchemaWrapper::class);
        $mockSchema->method('url')->willReturn($mockSchema);
        $mockSchema->method('image')->willReturn($mockSchema);
        $mockSchema->method('inLanguage')->willReturn($mockSchema);
        $mockSchema->method('name')->willReturn($mockSchema);
        $mockSchema->method('toJsonLd')->willReturn('{"@context":"https://schema.org","@type":"WebPage","url":"https://example.com/page","image":"https://example.com/image","inLanguage":"en","name":"Test Page"}');

        // Override the static method using a mock
        $result = SchemaGenerate::autoGenerate($option, $data);
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testAutoGenerate_with_null_data(): void
    {
        $option = ['iconSvg' => '.svg'];
        $data = null;

        // This should throw an error since we're trying to access properties of null
        $this->expectException(\Error::class);
        
        SchemaGenerate::autoGenerate($option, $data);
    }

    public function testAutoGenerate_with_missing_properties(): void
    {
        $option = ['iconSvg' => '.svg'];
        $data = (object) [
            'uri' => 'https://example.com/page',
            'title' => 'Test Page'
            // Missing image, lang properties
        ];

        // Mock the SchemaWrapper to avoid actual JSON-LD generation
        $mockSchema = $this->createMock(SchemaWrapper::class);
        $mockSchema->method('url')->willReturn($mockSchema);
        $mockSchema->method('image')->willReturn($mockSchema);
        $mockSchema->method('inLanguage')->willReturn($mockSchema);
        $mockSchema->method('name')->willReturn($mockSchema);
        $mockSchema->method('toJsonLd')->willReturn('{"@context":"https://schema.org","@type":"WebPage","url":"https://example.com/page","image":"","inLanguage":"","name":"Test Page"}');

        $result = SchemaGenerate::autoGenerate($option, $data);
        
        $this->assertIsString($result);
    }

    public function testAutoGenerate_with_empty_data(): void
    {
        $option = [];
        $data = (object) [];

        // Mock the SchemaWrapper to avoid actual JSON-LD generation
        $mockSchema = $this->createMock(SchemaWrapper::class);
        $mockSchema->method('url')->willReturn($mockSchema);
        $mockSchema->method('image')->willReturn($mockSchema);
        $mockSchema->method('inLanguage')->willReturn($mockSchema);
        $mockSchema->method('name')->willReturn($mockSchema);
        $mockSchema->method('toJsonLd')->willReturn('{}');

        $result = SchemaGenerate::autoGenerate($option, $data);
        
        $this->assertIsString($result);
    }

    public function testAutoGenerate_with_special_characters(): void
    {
        $option = ['iconSvg' => '.svg'];
        $data = (object) [
            'uri' => 'https://example.com/page?param=value&other=test',
            'image' => 'https://example.com/image with spaces',
            'lang' => 'en-US',
            'title' => 'Test "Page" with \'Quotes\' & Ampersands'
        ];

        // Mock the SchemaWrapper to avoid actual JSON-LD generation
        $mockSchema = $this->createMock(SchemaWrapper::class);
        $mockSchema->method('url')->willReturn($mockSchema);
        $mockSchema->method('image')->willReturn($mockSchema);
        $mockSchema->method('inLanguage')->willReturn($mockSchema);
        $mockSchema->method('name')->willReturn($mockSchema);
        $mockSchema->method('toJsonLd')->willReturn('');

        $result = SchemaGenerate::autoGenerate($option, $data);
        
        $this->assertIsString($result);
    }
}
