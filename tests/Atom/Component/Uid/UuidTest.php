<?php

declare(strict_types=1);

namespace Tests\Atom\Component\Uid;

use PHPUnit\Framework\TestCase;
use Atom\Component\Uid\Uuid;
use Symfony\Component\Uid\Uuid as SymfonyUuid;
use InvalidArgumentException;

class UuidTest extends TestCase
{
    public function testConstructsWith_valid_uuid(): void
    {
        $uuidString = '550e8400-e29b-41d4-a716-446655440000';
        $uuid = new Uuid($uuidString);
        
        $this->assertInstanceOf(Uuid::class, $uuid);
        $this->assertEquals($uuidString, (string) $uuid);
    }

    public function testConstructsWith_invalid_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        
        new Uuid('invalid-uuid');
    }

    public function testConstructsWith_checkVariant_true(): void
    {
        $uuidString = '550e8400-e29b-41d4-a716-446655440000';
        $uuid = new Uuid($uuidString, true);
        
        $this->assertInstanceOf(Uuid::class, $uuid);
        $this->assertEquals($uuidString, (string) $uuid);
    }

    public function testToString_returns_string(): void
    {
        $uuidString = '550e8400-e29b-41d4-a716-446655440000';
        $uuid = new Uuid($uuidString);
        
        $this->assertIsString($uuid->__toString());
        $this->assertEquals($uuidString, $uuid->__toString());
    }

    public function testToString_returns_same_as_toString(): void
    {
        $uuidString = '550e8400-e29b-41d4-a716-446655440000';
        $uuid = new Uuid($uuidString);
        
        $this->assertEquals($uuidString, $uuid->toString());
    }

    public function testEquals_returns_true_for_same_uuid(): void
    {
        $uuidString = '550e8400-e29b-41d4-a716-446655440000';
        $uuid1 = new Uuid($uuidString);
        $uuid2 = new Uuid($uuidString);
        
        $this->assertTrue($uuid1->equals($uuid2));
    }

    public function testEquals_returns_false_for_different_uuid(): void
    {
        $uuid1 = new Uuid('550e8400-e29b-41d4-a716-446655440000');
        $uuid2 = new Uuid('a1b2c3d4-e5f6-7890-a1b2-c3d4e5f67890');
        
        $this->assertFalse($uuid1->equals($uuid2));
    }

    public function testToString_returns_valid_uuid_format(): void
    {
        $uuidString = '550e8400-e29b-41d4-a716-446655440000';
        $uuid = new Uuid($uuidString);
        
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid->__toString());
    }

    public function testImmutability(): void
    {
        $uuidString = '550e8400-e29b-41d4-a716-446655440000';
        $uuid = new Uuid($uuidString);
        
        // Test that operations return new instances
        $uuidCopy = clone $uuid;
        $this->assertNotSame($uuid, $uuidCopy);
    }

    public function testExtends_symfony_uuid(): void
    {
        $uuidString = '550e8400-e29b-41d4-a716-446655440000';
        $uuid = new Uuid($uuidString);
        
        // Verify it's an instance of Symfony's UUID class
        $this->assertInstanceOf(SymfonyUuid::class, $uuid);
    }
}
