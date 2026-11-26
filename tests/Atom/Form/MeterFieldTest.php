<?php

declare(strict_types=1);

namespace Tests\Atom\Form;

use PHPUnit\Framework\TestCase;
use Atom\Model;
use Atom\form\MeterField;

/**
 * Test suite for the MeterField component.
 */
class MeterFieldTest extends TestCase
{
    /**
     * Test meter field constructor sets type and calls parent.
     */
    public function test_meter_field_constructor_sets_type_and_calls_parent()
    {
        $model = new class extends Model {
            public $testAttribute = '';
        };
        $meterField = new MeterField($model, 'testAttribute');
        
        $reflection = new \ReflectionClass($meterField);
        $property = $reflection->getProperty('type');
        $type = $property->getValue($meterField);
        
        $this->assertEquals('meter', $type);
    }
    
    /**
     * Test render input returns correct html.
     */
    public function test_renderInput_returns_correct_html()
    {
        $model = new class extends Model {
            public $testAttribute = 'test-value';
        };
        
        $meterField = new MeterField($model, 'testAttribute');
        
        $result = $meterField->renderInput();
        $expected = '<meter class="form-control" name="testAttribute" value="test-value" >test-value</meter>';
        
        $this->assertEquals($expected, $result);
    }
    
    /**
     * Test render input with error.
     */
    public function test_renderInput_with_error()
    {
        $model = new class extends Model {
            public $testAttribute = 'test-value';
            
            public function hasError($attribute)
            {
                return true;
            }
        };
        
        $meterField = new MeterField($model, 'testAttribute');
        
        $result = $meterField->renderInput();
        $expected = '<meter class="form-control is-invalid" name="testAttribute" value="test-value" >test-value</meter>';
        
        $this->assertEquals($expected, $result);
    }
    
    /**
     * Test render input with empty value.
     */
    public function test_renderInput_with_empty_value()
    {
        $model = new class extends Model {
            public $testAttribute = '';
        };
        
        $meterField = new MeterField($model, 'testAttribute');
        
        $result = $meterField->renderInput();
        $expected = '<meter class="form-control" name="testAttribute" value="" ></meter>';
        
        $this->assertEquals($expected, $result);
    }
}
