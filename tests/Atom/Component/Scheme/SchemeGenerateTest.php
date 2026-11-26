<?php

declare(strict_types=1);

namespace Tests\Atom\Component\Scheme;

use PHPUnit\Framework\TestCase;
use Atom\Component\Scheme\SchemeGenerate;
use Atom\Component\Scheme\SchemaWrapper;

/**
 * Test suite for the SchemeGenerate component.
 */
class SchemeGenerateTest extends TestCase
{
    /**
     * Test auto generate returns string.
     */
    public function testAutoGenerate_returns_string(): void
    {
        $option = ['iconSvg' => '.svg'];
        $data = (object) [
            'uri' => 'https://example.com/page',
            'image' => 'https://example.com/image',
            'lang' => 'en',
            'title' => 'Test Page'
        ];

        $result = SchemeGenerate::autoGenerate($option, $data);
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test auto generate with empty option.
     */
    public function testAutoGenerate_with_empty_option(): void
    {
        $option = [];
        $data = (object) [
            'uri' => 'https://example.com/page',
            'image' => 'https://example.com/image',
            'lang' => 'en',
            'title' => 'Test Page'
        ];

        // Override the static method using a mock
        $result = SchemeGenerate::autoGenerate($option, $data);
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test auto generate with null data.
     */
    public function testAutoGenerate_with_null_data(): void
    {
        $option = ['iconSvg' => '.svg'];
        $data = null;

        // This should throw an error since we're trying to access properties of null
        $this->expectException(\Error::class);
        
        SchemeGenerate::autoGenerate($option, $data);
    }

    /**
     * Test auto generate with missing properties.
     */
    public function testAutoGenerate_with_missing_properties(): void
    {
        $option = ['iconSvg' => '.svg'];
        $data = (object) [
            'uri' => 'https://example.com/page',
            'title' => 'Test Page'
            // Missing image, lang properties
        ];

        $result = SchemeGenerate::autoGenerate($option, $data);
        
        $this->assertIsString($result);
    }

    /**
     * Test auto generate with empty data.
     */
    public function testAutoGenerate_with_empty_data(): void
    {
        $option = [];
        $data = (object) [];

        $result = SchemeGenerate::autoGenerate($option, $data);
        
        $this->assertIsString($result);
    }

    /**
     * Test auto generate with special characters.
     */
    public function testAutoGenerate_with_special_characters(): void
    {
        $option = ['iconSvg' => '.svg'];
        $data = (object) [
            'uri' => 'https://example.com/page?param=value&other=test',
            'image' => 'https://example.com/image with spaces',
            'lang' => 'en-US',
            'title' => 'Test "Page" with \'Quotes\' & Ampersands'
        ];

        $result = SchemeGenerate::autoGenerate($option, $data);
        
        $this->assertIsString($result);
    }
}
