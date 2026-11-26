<?php

declare(strict_types=1);

namespace Tests\Atom\Types;

use PHPUnit\Framework\TestCase;
use Atom\Types\Point;

/**
 * Test suite for the Point component.
 */
class PointTest extends TestCase
{
    /**
     * Test point creation.
     */
    public function testPointCreation(): void
    {
        $latitude = 52.2297;
        $longitude = 21.0122;
        
        $point = new Point($latitude, $longitude);
        
        $this->assertSame($latitude, $point->latitude);
        $this->assertSame($longitude, $point->longitude);
    }

    /**
     * Test to string.
     */
    public function testToString(): void
    {
        $point = new Point(52.2297, 21.0122);
        $result = $point->toString();
        
        $this->assertEquals('52.2297,21.0122', $result);
    }

    /**
     * Test to format.
     */
    public function testToFormat(): void
    {
        $point = new Point(52.2297, 21.0122);
        $format = "Lat: %F, Lng: %F";
        $result = $point->toFormat($format);
        
        $this->assertEquals('Lat: 52.229700, Lng: 21.012200', $result);
    }

    /**
     * Test to sql.
     */
    public function testToSql(): void
    {
        $point = new Point(52.2297, 21.0122);
        $result = $point->toSql();
        
        $this->assertEquals('POINT(52.2297, 21.0122)', $result);
    }

    /**
     * Test immutable properties.
     */
    public function testImmutableProperties(): void
    {
        $point = new Point(52.2297, 21.0122);
        
        $this->expectException(\Error::class);
        $point->latitude = 40.7128;
    }
}
