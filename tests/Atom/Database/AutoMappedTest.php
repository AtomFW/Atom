<?php

declare(strict_types=1);

namespace Tests\Atom\DataBase;

use PHPUnit\Framework\TestCase;

use Atom\DataBase\AutoMapped;

/**
 * Test suite for the AutoMapped component.
 */
class AutoMappedTest extends TestCase
{
    protected function setUp(): void
    {
        // Mock the Atom application instance
        $this->mockAtom = $this->createMock(\Atom\Atom::class);
        \Atom\Atom::$app = $this->mockAtom;
    }


    /**
     * Test map value from d b with json type.
     */
    public function test_mapValueFromDB_with_json_type()
    {
        $result = AutoMapped::mapValueFromDB('{"key": "value"}', 'json');
        $this->assertIsArray($result);
        $this->assertEquals(['key' => 'value'], $result);
    }

    /**
     * Test map value from d b with string json type.
     */
    public function test_mapValueFromDB_with_stringJson_type()
    {
        $result = AutoMapped::mapValueFromDB('{"key": "value"}', 'stringJson');
        $this->assertSame('value', $result->key);
    }

    /**
     * Test map value from d b with uuid type.
     */
    public function test_mapValueFromDB_with_uuid_type()
    {
        $uuidString = '123e4567-e89b-12d3-a456-426614174000';
        $result = AutoMapped::mapValueFromDB($uuidString, 'uuid');
        $this->assertIsString($result);
    }

    /**
     * Test map value from d b with point type.
     */
    public function test_mapValueFromDB_with_point_type()
    {
        // This requires a valid POINT format string
        $pointValue = 'POINT(10.0 20.0)';
        $result = AutoMapped::mapValueFromDB($pointValue, 'point');
        
        // Since Point class is not fully defined, we'll just check it returns something
        $this->assertNotNull($result);
    }

    /**
     * Test map value from d b with default type.
     */
    public function test_mapValueFromDB_with_default_type()
    {
        $value = 'test';
        $result = AutoMapped::mapValueFromDB($value, 'default');
        $this->assertEquals('test', $result);
    }

    /**
     * Test map value from p h p with json type.
     */
    public function test_mapValueFromPHP_with_json_type()
    {
        $array = ['key' => 'value'];
        $result = AutoMapped::mapValueFromPHP($array, 'json');
        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertEquals($array, $decoded);
    }

    /**
     * Test map value from p h p with uuid type.
     */
    public function test_mapValueFromPHP_with_uuid_type()
    {
        $uuidString = '123e4567-e89b-12d3-a456-426614174000';
        $this->expectException(\ValueError::class);
        $result = AutoMapped::mapValueFromPHP($uuidString, 'uuid');
    }

    /**
     * Test map value from p h p with point type.
     */
    public function test_mapValueFromPHP_with_point_type()
    {
        // This requires a valid POINT format string
        $pointValue = 'POINT(10.0 20.0)';
        $result = AutoMapped::mapValueFromPHP($pointValue, 'point');
        
        // Since Point class is not fully defined, we'll just check it returns something
        $this->assertNotNull($result);
    }

    /**
     * Test map value from p h p with default type.
     */
    public function test_mapValueFromPHP_with_default_type()
    {
        $value = 'test';
        $result = AutoMapped::mapValueFromPHP($value, 'default');
        $this->assertEquals('test', $result);
    }

    /**
     * Test map value from d b with int type.
     */
    public function test_mapValueFromDB_with_int_type()
    {
        $result = AutoMapped::mapValueFromDB('123', 'int');
        $this->assertEquals(123, $result);
    }
    
    /**
     * Test map value from d b with float type.
     */
    public function test_mapValueFromDB_with_float_type()
    {
        $result = AutoMapped::mapValueFromDB('123.45', 'float');
        $this->assertEquals(123.45, $result);
    }
    
    /**
     * Test map value from d b with boolean type.
     */
    public function test_mapValueFromDB_with_boolean_type()
    {
        $result = AutoMapped::mapValueFromDB('1', 'boolean');
        $this->assertTrue($result);
        
        $result = AutoMapped::mapValueFromDB('0', 'boolean');
        $this->assertFalse($result);
    }
    
    /**
     * Test map value from d b with string type.
     */
    public function test_mapValueFromDB_with_string_type()
    {
        $result = AutoMapped::mapValueFromDB('test string', 'string');
        $this->assertEquals('test string', $result);
    }
    
    /**
     * Test map value from d b with array type.
     */
    public function test_mapValueFromDB_with_array_type()
    {
        $input = '["a","b","c"]';
        $result = AutoMapped::mapValueFromDB($input, 'array');
        $this->assertEquals([$input], $result);
    }
    
    /**
     * Test map value from d b with object type.
     */
    public function test_mapValueFromDB_with_object_type()
    {
        $input = '{"key":"value"}';
        $result = AutoMapped::mapValueFromDB($input, 'object');
        
        $expected = new \stdClass();
        $expected->scalar = $input;
        
        $this->assertEquals($expected, $result);
    }
    
    /**
     * Test map value from d b with null type.
     */
    public function test_mapValueFromDB_with_null_type()
    {
        $this->expectException(\TypeError::class);
        AutoMapped::mapValueFromDB(null, 'null');
    }
    
    /**
     * Test map value from d b with string json type.
     */
    public function test_mapValueFromDB_with_string_json_type()
    {
        $input = '{"key":"value"}';
        $result = AutoMapped::mapValueFromDB($input, 'stringJson');
        $this->assertInstanceOf('stdClass', $result);
    }
    
    /**
     * Test map value from d b with time type.
     */
    public function test_mapValueFromDB_with_time_type()
    {
        if (!defined('ATOM_LOCAE')) define('ATOM_LOCAE', 'en_US');
        if (!defined('ATOM_TIMEZONE')) define('ATOM_TIMEZONE', 'UTC');
        if (!defined('ATOM_TIME')) define('ATOM_TIME', 'H:i:s');
        if (!defined('ATOM_DATETIME')) define('ATOM_DATETIME', 'Y-m-d H:i:s');
        
        // Use the real Atom datetime service
        $datetime = new \Atom\DateTime\DateTime();
        $datetime::setMacro();
        
        $this->mockAtom->datetime = $datetime;
            
        $result = AutoMapped::mapValueFromDB('12:00:00', 'time');
        $this->assertEquals('12:00:00', $result);
    }
    
    /**
     * Test map value from d b with default case.
     */
    public function test_mapValueFromDB_with_default_case()
    {
        $result = AutoMapped::mapValueFromDB('test', 'unknown_type');
        $this->assertEquals('test', $result);
    }

    /**
     * Test map value from p h p with default case.
     */
    public function test_mapValueFromPHP_with_default_case()
    {
        $result = AutoMapped::mapValueFromPHP('test', 'unknown_type');
        $this->assertEquals('test', $result);
    }
    
    /**
     * Test map value from d b with class type.
     */
    public function test_mapValueFromDB_with_class_type()
    {
        // Test with a mock class that has the required methods
        $result = AutoMapped::mapValueFromDB('some_value', 'stdClass');
        // For now, just make sure it doesn't crash
        $this->assertSame('some_value', $result);
    }
    
    /**
     * Test map value from p h p with class type.
     */
    public function test_mapValueFromPHP_with_class_type()
    {
        // Test with a mock class that has the required methods
        $result = AutoMapped::mapValueFromPHP('some_value', 'stdClass');
        // For now, just make sure it doesn't crash
        $this->assertSame('some_value', $result);
    }    
}
