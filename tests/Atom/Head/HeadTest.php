<?php

declare(strict_types=1);

namespace Tests\Atom\Head;

use PHPUnit\Framework\TestCase;
use Atom\Head\Head;
use Atom\Head\Enum\OpenGraphTag;
use Atom\Head\Enum\ColorScheme;

final class HeadTest extends TestCase
{
    public function testCanCreateHeadInstance(): void
    {
        $head = new Head();
        $this->assertInstanceOf(Head::class, $head);
    }

    public function testSetTitle(): void
    {
        $head = new Head();
        $result = $head->title('Test Title');
        
        $this->assertInstanceOf(Head::class, $result);
        $this->assertNotEmpty($head->build());
    }

    public function testMetaMethod(): void
    {
        $head = new Head();
        $result = $head->meta('description', 'Test description');
        
        $this->assertInstanceOf(Head::class, $result);
    }

    public function testPropertyMethod(): void
    {
        $head = new Head();
        $result = $head->property('og:title', 'Test Title');
        
        $this->assertInstanceOf(Head::class, $result);
    }

    public function testLinkMethod(): void
    {
        $head = new Head();
        $result = $head->link('stylesheet', '/styles.css', 'text/css');
        
        $this->assertInstanceOf(Head::class, $result);
    }

    public function testStylesheetMethod(): void
    {
        $head = new Head();
        $result = $head->stylesheet('/styles.css');
        
        $this->assertInstanceOf(Head::class, $result);
    }

    public function testScriptMethod(): void
    {
        $head = new Head();
        $result = $head->script('console.log("test");');
        
        $this->assertInstanceOf(Head::class, $result);
    }

    public function testScriptTextMethod(): void
    {
        $head = new Head();
        $result = $head->scriptText('console.log("test");');
        
        $this->assertInstanceOf(Head::class, $result);
    }

    public function testBuildMethodReturnsString(): void
    {
        $head = new Head();
        $head->title('Test Title')
              ->meta('description', 'Test description')
              ->meta('keywords', 'test, head, meta');
        
        $result = $head->build();
        
        $this->assertIsString($result);
        $this->assertStringContainsString('<title>', $result);
        $this->assertStringContainsString('Test Title', $result);
    }

    public function testMultipleMetaTags(): void
    {
        $head = new Head();
        $head->meta('description', 'Test description')
              ->meta('keywords', 'test, head, meta')
              ->meta('author', 'Test Author');
        
        $result = $head->build();
        
        $this->assertStringContainsString('name="description"', $result);
        $this->assertStringContainsString('name="keywords"', $result);
        $this->assertStringContainsString('name="author"', $result);
    }

    public function testMultipleProperties(): void
    {
        $head = new Head();
        $head->property('og:title', 'Test Title')
              ->property('og:description', 'Test Description')
              ->property('og:image', 'https://example.com/image.jpg');
        
        $result = $head->build();
        
        $this->assertStringContainsString('property="og:title"', $result);
        $this->assertStringContainsString('property="og:description"', $result);
        $this->assertStringContainsString('property="og:image"', $result);
    }

    public function testOpenGraphTag(): void
    {
        $head = new Head();
        $result = $head->og(OpenGraphTag::Title, 'Test Title');
        
        $this->assertInstanceOf(Head::class, $result);
    }

    public function testAppleMobileWebAppTitle(): void
    {
        $head = new Head();
        $result = $head->appleMobileWebAppTitle('Test App');
        
        $this->assertInstanceOf(Head::class, $result);
    }

    public function testColorSchemeMethod(): void
    {
        $head = new Head();
        $result = $head->colorScheme(ColorScheme::Light);
        
        $this->assertInstanceOf(Head::class, $result);
    }

    public function testCharsetMethod(): void
    {
        $head = new Head();
        $result = $head->charset('UTF-8');
        
        $this->assertInstanceOf(Head::class, $result);
    }
}
