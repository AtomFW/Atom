<?php

declare(strict_types=1);

namespace Tests\Atom\HttpFoundation;

use PHPUnit\Framework\TestCase;
use Atom\HttpFoundation\Header;
use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

final class HeaderTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the static property before each test to ensure isolation
        $reflection = new \ReflectionClass(Header::class);
        $staticProperty = $reflection->getProperty('headerCallbackRegistered');
        // $staticProperty->setAccessible(true);
        $staticProperty->setValue(false);
    }

    public function testConstructorAndBasicSetGet(): void
    {
        $headers = new Header(['Content-Type' => 'application/json', 'X-Custom' => ['foo', 'bar']]);

        $this->assertTrue($headers->has('content-type'));
        $this->assertEquals('application/json', $headers->get('Content-Type'));
        $this->assertEquals(['application/json'], $headers->getAll('content-type'));

        $this->assertTrue($headers->has('x-custom'));
        $this->assertEquals('foo, bar', $headers->get('X-Custom'));
        $this->assertEquals(['foo', 'bar'], $headers->getAll('x-custom'));

        $this->assertFalse($headers->has('Non-Existent'));
        $this->assertNull($headers->get('Non-Existent'));
        $this->assertEquals('default-value', $headers->get('Non-Existent', 'default-value'));
        $this->assertEmpty($headers->getAll('Non-Existent'));

        $this->assertCount(2, $headers);
        $this->assertEquals([
            'Content-Type' => ['application/json'],
            'X-Custom' => ['foo', 'bar']
        ], $headers->toArray());
    }

    public function testImmutability(): void
    {
        $initialHeaders = ['Content-Type' => 'text/html'];
        $headers = new Header($initialHeaders, true); // Immutable

        $newHeaders = $headers->set('X-Foo', 'bar');
        $this->assertNotSame($headers, $newHeaders);
        $this->assertFalse($headers->has('X-Foo'));
        $this->assertTrue($newHeaders->has('X-Foo'));

        $addedHeaders = $headers->add('Content-Type', 'charset=utf-8');
        $this->assertNotSame($headers, $addedHeaders);
        $this->assertEquals('text/html', $headers->get('Content-Type'));
        $this->assertEquals('text/html, charset=utf-8', $addedHeaders->get('Content-Type'));

        $removedHeaders = $headers->remove('Content-Type');
        $this->assertNotSame($headers, $removedHeaders);
        $this->assertTrue($headers->has('Content-Type'));
        $this->assertFalse($removedHeaders->has('Content-Type'));

        // Test mutable behavior
        $mutableHeaders = new Header($initialHeaders, false);
        $returnedMutableHeaders = $mutableHeaders->set('X-Foo', 'bar');
        $this->assertSame($mutableHeaders, $returnedMutableHeaders);
        $this->assertTrue($mutableHeaders->has('X-Foo'));
    }

    public function testAddAndRemoveHeaders(): void
    {
        $headers = new Header();
        $headers->set('X-Foo', 'value1');
        $this->assertEquals('value1', $headers->get('X-Foo'));
        $this->assertEquals(['value1'], $headers->getAll('X-Foo'));

        $headers->add('X-Foo', 'value2');
        $this->assertEquals('value1, value2', $headers->get('X-Foo'));
        $this->assertEquals(['value1', 'value2'], $headers->getAll('X-Foo'));

        $headers->add('X-Bar', ['valA', 'valB']);
        $this->assertEquals('valA, valB', $headers->get('X-Bar'));
        $this->assertEquals(['valA', 'valB'], $headers->getAll('X-Bar'));

        $headers->remove('X-Foo');
        $this->assertFalse($headers->has('X-Foo'));
        $this->assertNull($headers->get('X-Foo'));
        $this->assertEmpty($headers->getAll('X-Foo'));

        $this->assertTrue($headers->has('X-Bar'));
        $this->assertCount(1, $headers);
    }

    public function testParseContentType(): void
    {
        $headers = new Header();

        // No Content-Type
        $this->assertNull($headers->parseContentType());

        // Simple Content-Type
        $headers->set('Content-Type', 'text/html');
        $this->assertEquals(['mime' => 'text/html', 'params' => []], $headers->parseContentType());

        // Content-Type with charset
        $headers->set('Content-Type', 'application/json; charset=utf-8');
        $this->assertEquals(
            ['mime' => 'application/json', 'params' => ['charset' => 'utf-8']],
            $headers->parseContentType()
        );

        // Content-Type with multiple parameters and quotes
        $headers->set('Content-Type', 'text/plain; charset="iso-8859-1"; foo=bar');
        $this->assertEquals(
            ['mime' => 'text/plain', 'params' => ['charset' => 'iso-8859-1', 'foo' => 'bar']],
            $headers->parseContentType()
        );

        // Content-Type with extra spaces
        $headers->set('Content-Type', '  text/xml ;   boundary=foo  ');
        $this->assertEquals(['mime' => 'text/xml', 'params' => ['boundary' => 'foo']], $headers->parseContentType());
    }

    public function testFromGlobals(): void
    {
        // Mock $_SERVER superglobal for testing
        $_SERVER_BAK = $_SERVER;
        $_SERVER = [
            'HTTP_HOST' => 'example.com',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => '123',
            'REQUEST_METHOD' => 'POST', // Not a header
        ];

        $headers = Header::fromGlobals(true); // Immutable

        $this->assertTrue($headers->has('Host'));
        $this->assertEquals('example.com', $headers->get('Host'));

        $this->assertTrue($headers->has('User-Agent'));
        $this->assertEquals('Mozilla/5.0', $headers->get('User-Agent'));

        $this->assertTrue($headers->has('Content-Type'));
        $this->assertEquals('application/x-www-form-urlencoded', $headers->get('Content-Type'));

        $this->assertTrue($headers->has('Content-Length'));
        $this->assertEquals('123', $headers->get('Content-Length'));

        $this->assertFalse($headers->has('Request-Method')); // Should not be present
        $this->assertCount(4, $headers); // Host, User-Agent, Content-Type, Content-Length

        $_SERVER = $_SERVER_BAK; // Restore $_SERVER
    }
}
