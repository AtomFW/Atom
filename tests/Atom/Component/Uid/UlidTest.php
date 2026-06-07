<?php

declare(strict_types=1);

namespace Tests\Atom\Component\Uid;

use PHPUnit\Framework\TestCase;
use Atom\Component\Uid\Ulid;
use Symfony\Component\Uid\Ulid as SymfonyUlid;
use InvalidArgumentException;

class UlidTest extends TestCase
{
    public function testConstructsWith_valid_ulid(): void
    {
        $ulidString = '01ARZ3NDEM0000000000000000';
        $ulid = new Ulid($ulidString);
        
        $this->assertInstanceOf(Ulid::class, $ulid);
        $this->assertEquals($ulidString, (string) $ulid);
    }

    public function testConstructsWith_null_generates_new_ulid(): void
    {
        $ulid = new Ulid();
        
        $this->assertInstanceOf(Ulid::class, $ulid);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/i', (string) $ulid);
    }

    public function testConstructsWith_invalid_ulid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        
        new Ulid('invalid-ulid');
    }

    public function testToString_returns_string(): void
    {
        $ulidString = '01ARZ3NDEM0000000000000000';
        $ulid = new Ulid($ulidString);
        
        $this->assertIsString($ulid->__toString());
        $this->assertEquals($ulidString, $ulid->__toString());
    }

    public function testToString_returns_same_as_toString(): void
    {
        $ulidString = '01ARZ3NDEM0000000000000000';
        $ulid = new Ulid($ulidString);
        
        $this->assertEquals($ulidString, $ulid->toString());
    }

    public function testEquals_returns_true_for_same_ulid(): void
    {
        $ulidString = '01ARZ3NDEM0000000000000000';
        $ulid1 = new Ulid($ulidString);
        $ulid2 = new Ulid($ulidString);
        
        $this->assertTrue($ulid1->equals($ulid2));
    }

    public function testEquals_returns_false_for_different_ulid(): void
    {
        $ulid1 = new Ulid('01ARZ3NDEM0000000000000000');
        $ulid2 = new Ulid('01ARZ3NDEM0000000000000001');
        
        $this->assertFalse($ulid1->equals($ulid2));
    }

    public function testToString_returns_valid_ulid_format(): void
    {
        $ulidString = '01ARZ3NDEM0000000000000000';
        $ulid = new Ulid($ulidString);
        
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $ulid->__toString());
    }

    public function testImmutability(): void
    {
        $ulidString = '01ARZ3NDEM0000000000000000';
        $ulid = new Ulid($ulidString);
        
        // Test that operations return new instances
        $ulidCopy = clone $ulid;
        $this->assertNotSame($ulid, $ulidCopy);
    }

    public function testExtends_symfony_ulid(): void
    {
        $ulidString = '01ARZ3NDEM0000000000000000';
        $ulid = new Ulid($ulidString);
        
        // Verify it's an instance of Symfony's ULID class
        $this->assertInstanceOf(SymfonyUlid::class, $ulid);
    }

    public function testGenerates_different_ulids(): void
    {
        $ulid1 = new Ulid();
        $ulid2 = new Ulid();
        
        // It's extremely unlikely but theoretically possible that two
        // randomly generated ULIDs could be equal, so we check they're not equal
        $this->assertNotEquals($ulid1->__toString(), $ulid2->__toString());
    }
}
