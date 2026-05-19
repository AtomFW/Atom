<?php

declare(strict_types=1);

namespace Tests\Atom\Generate\Uuids;

use PHPUnit\Framework\TestCase;
use Atom\Component\Uid\Ulid;
use Random\Randomizer;

class UuidsTest extends TestCase
{
    public function test_construct_with_default_parameters()
    {
        $uuids = new Uuids();
        
        $this->assertEquals(Uuids::MODE_ULID, $uuids->current());
    }
    
    public function test_construct_with_mode()
    {
        $uuids = new Uuids(Uuids::MODE_UUID7);
        $this->assertInstanceOf(Uuids::class, $uuids);
    }
    
    public function test_construct_with_length_and_extra()
    {
        $uuids = new Uuids(Uuids::MODE_RANDOM, 16, 8);
        $this->assertInstanceOf(Uuids::class, $uuids);
    }
    
    public function test_generate_ulid_mode()
    {
        $uuids = new Uuids(Uuids::MODE_ULID);
        $result = $uuids->current();
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // ULIDs are 26 characters long in base32
        $this->assertEquals(26, strlen($result));
    }
    
    public function test_generate_uuid7_mode()
    {
        $uuids = new Uuids(Uuids::MODE_UUID7);
        $result = $uuids->current();
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // UUID7 should be 36 characters with hyphens
        $this->assertEquals(36, strlen($result));
    }
    
    public function test_generate_random_mode()
    {
        $uuids = new Uuids(Uuids::MODE_RANDOM, 10);
        $result = $uuids->current();
        
        $this->assertIsString($result);
        $this->assertEquals(10, strlen($result));
    }
    
    public function test_generate_hybrid_mode()
    {
        $uuids = new Uuids(Uuids::MODE_HYBRID, 10, 5);
        $result = $uuids->current();
        
        $this->assertIsString($result);
        // Should contain both ULID and random part
        $parts = explode('.', $result);
        $this->assertCount(2, $parts);
        $this->assertEquals(26, strlen($parts[0])); // ULID part
        $this->assertEquals(5, strlen($parts[1]));  // Random part
    }
    
    public function test_next_id()
    {
        $uuids = new Uuids(Uuids::MODE_RANDOM);
        $firstId = $uuids->nextId();
        
        $this->assertIsString($firstId);
        $this->assertNotEmpty($firstId);
    }
    
    public function test_iterator_methods()
    {
        $uuids = new Uuids(Uuids::MODE_RANDOM, 10);
        
        // Test rewind
        $uuids->rewind();
        $current1 = $uuids->current();
        
        // Test next
        $uuids->next();
        $current2 = $uuids->current();
        
        // Should be different after calling next
        $this->assertIsString($current1);
        $this->assertIsString($current2);
    }
    
    public function test_next_int()
    {
        $uuids = new Uuids(Uuids::MODE_RANDOM);
        $int1 = $uuids->nextInt();
        $int2 = $uuids->nextInt(10, 20);
        
        $this->assertIsInt($int1);
        $this->assertIsInt($int2);
        $this->assertGreaterThanOrEqual(0, $int1);
        $this->assertGreaterThanOrEqual(10, $int2);
        $this->assertLessThanOrEqual(20, $int2);
    }
    
    public function test_next_bytes()
    {
        $uuids = new Uuids(Uuids::MODE_RANDOM);
        $bytes1 = $uuids->nextBytes();
        $bytes2 = $uuids->nextBytes(16);
        
        $this->assertIsString($bytes1);
        $this->assertEquals(32, strlen($bytes1));
        $this->assertEquals(16, strlen($bytes2));
    }
    
    public function test_next_string()
    {
        $uuids = new Uuids(Uuids::MODE_RANDOM);
        $string1 = $uuids->nextString();
        $string2 = $uuids->nextString(10);
        
        $this->assertIsString($string1);
        $this->assertEquals(32, strlen($string1));
        $this->assertEquals(10, strlen($string2));
    }
    
    public function test_invalid_mode()
    {
        $this->expectException(InvalidArgumentException::class);
        new Uuids('invalid-mode');
    }
    
    public function test_iterator_implements_iterator()
    {
        $uuids = new Uuids(Uuids::MODE_RANDOM);
        $this->assertInstanceOf(Iterator::class, $uuids);
    }
}
