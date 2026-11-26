<?php

declare(strict_types=1);

namespace Tests\Atom\Component\Scheme;

use PHPUnit\Framework\TestCase;
use Atom\Component\Scheme\SchemaWrapper;
use Psr\Log\LoggerInterface;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\Type;
use Mockery;

/**
 * Test suite for the SchemaWrapper component.
 */
class SchemaWrapperTest extends TestCase
{
    /**
     * Test construct sets instance and logger.
     */
    public function test_construct_sets_instance_and_logger()
    {
        $mock = $this->createMock(Type::class);
        $logger = $this->createMock(LoggerInterface::class);
        
        $wrapper = new SchemaWrapper($mock, $logger);
        
        $this->assertEquals($mock, $wrapper->unwrap());
        $this->assertNotNull($wrapper);
    }

    /**
     * Test call static with valid schema type.
     */
    public function test_call_static_with_valid_schema_type()
    {
        $wrapper = SchemaWrapper::person();
        $this->assertInstanceOf(SchemaWrapper::class, $wrapper);
    }

    /**
     * Test call static with invalid schema type throws exception.
     */
    public function test_call_static_with_invalid_schema_type_throws_exception()
    {
        $this->expectException(\BadMethodCallException::class);
        SchemaWrapper::__callStatic('nonExistentType', []);
    }

    /**
     * Test call with valid method returns wrapped result.
     */
    public function test_call_with_valid_method_returns_wrapped_result()
    {
        $wrapper = SchemaWrapper::person();
        $result = $wrapper->name('John Doe');
        
        $this->assertInstanceOf(SchemaWrapper::class, $result);
    }

    /**
     * Test call with invalid method throws exception.
     */
    public function test_call_with_invalid_method_throws_exception()
    {
        $wrapper = SchemaWrapper::person();
        
        $this->expectException(\BadMethodCallException::class);
        $wrapper->nonExistentMethod();
    }

    /**
     * Test unwrap returns underlying instance.
     */
    public function test_unwrap_returns_underlying_instance()
    {
        $mock = $this->createMock(Type::class);
        $wrapper = new SchemaWrapper($mock);
        
        $this->assertEquals($mock, $wrapper->unwrap());
    }

    /**
     * Test add graph item with wrapper.
     */
    public function test_add_graph_item_with_wrapper()
    {
        $wrapper = SchemaWrapper::person();
        $graphItem = SchemaWrapper::person();
        
        $result = $wrapper->addGraphItem($graphItem);
        
        $this->assertInstanceOf(SchemaWrapper::class, $result);
    }

    /**
     * Test add graph item with object.
     */
    public function test_add_graph_item_with_object()
    {
        $wrapper = SchemaWrapper::person();
        $mock = $this->createMock(Type::class);
        
        $result = $wrapper->addGraphItem($mock);
        
        $this->assertInstanceOf(SchemaWrapper::class, $result);
    }

    /**
     * Test add graph item with array.
     */
    public function test_add_graph_item_with_array()
    {
        $wrapper = SchemaWrapper::person();
        $array = ['key' => 'value'];
        
        $result = $wrapper->addGraphItem($array);
        
        $this->assertInstanceOf(SchemaWrapper::class, $result);
    }

    /**
     * Test add graph item with invalid type throws exception.
     */
    public function test_add_graph_item_with_invalid_type_throws_exception()
    {
        $wrapper = SchemaWrapper::person();
        
        $this->expectException(\TypeError::class);
        $wrapper->addGraphItem('invalid');
    }

    /**
     * Test merge graph with other wrapper.
     */
    public function test_merge_graph_with_other_wrapper()
    {
        $wrapper1 = SchemaWrapper::person();
        $wrapper2 = SchemaWrapper::person();
        
        $result = $wrapper1->mergeGraph($wrapper2);
        
        $this->assertInstanceOf(SchemaWrapper::class, $result);
    }

    /**
     * Test to json ld returns string.
     */
    public function test_to_json_ld_returns_string()
    {
        $wrapper = SchemaWrapper::person();
        $result = $wrapper->toJsonLd();
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test to script returns html script tag.
     */
    public function test_to_script_returns_html_script_tag()
    {
        $wrapper = SchemaWrapper::person();
        $result = $wrapper->toScript();
        
        $this->assertStringContainsString('<script type="application/ld+json">', $result);
        $this->assertStringContainsString('</script>', $result);
    }

    /**
     * Test list public methods returns array.
     */
    public function test_list_public_methods_returns_array()
    {
        $methods = SchemaWrapper::listPublicMethods();
        $this->assertIsArray($methods);
        $this->assertIsArray($methods);
    }

    /**
     * Test create type with valid type.
     */
    public function test_create_type_with_valid_type()
    {
        $wrapper = SchemaWrapper::createType('person');
        $this->assertInstanceOf(SchemaWrapper::class, $wrapper);
    }

    /**
     * Test create type with invalid type throws exception.
     */
    public function test_create_type_with_invalid_type_throws_exception()
    {
        $this->expectException(\InvalidArgumentException::class);
        SchemaWrapper::createType('nonExistentType');
    }

    /**
     * Test to string returns script tag.
     */
    public function test_to_string_returns_script_tag()
    {
        $wrapper = SchemaWrapper::person();
        $result = (string)$wrapper;
        
        $this->assertIsString($result);
        $this->assertStringContainsString('<script type="application/ld+json">', $result);
    }

    /**
     * Test debug info returns correct array.
     */
    public function test_debug_info_returns_correct_array()
    {
        $wrapper = SchemaWrapper::person();
        $debugInfo = $wrapper->debugInfo();
        
        $this->assertArrayHasKey('class', $debugInfo);
        $this->assertArrayHasKey('graph_count', $debugInfo);
    }
}
