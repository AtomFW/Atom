<?php

declare(strict_types=1);

namespace Tests\Atom\Form;

use PHPUnit\Framework\TestCase;
use Atom\Model;
use Atom\form\MeterField;

class MeterFieldTest extends TestCase
{
    public function test_meter_field_constructor_sets_type_and_calls_parent()
    {
        $model = $this->createMock(Model::class);
        $meterField = new MeterField($model, 'testAttribute');
        
        // Check that the type is set correctly by accessing protected property through reflection
        $reflection = new \ReflectionClass($meterField);
        $property = $reflection->getProperty('type');
        $type = $property->getValue($meterField);
        
        $this->assertEquals('meter', $type);
    }
    
    public function test_renderInput_returns_correct_html()
    {
        $model = $thisMock = $this->createPartialMock(Model::class, ['getProperty', 'hasError', 'property']);
        
        $model->expects($this->any())
            ->method('getProperty')
            ->will($this->onConsecutiveCalls(
                ['class' => 'form-control'],
                ['value' => 'test-value']
            ));
            
        $model->method('hasError')
            ->with('testAttribute')
            ->willReturn(false);
            
        $model->expects($this->any())
            ->method('property')
            ->with('testAttribute')
            ->willReturn('');
            
        // Mock the attribute value
        $model = $this->createMock(Model::class);
        $model->method('getProperty')
            ->will($this->onConsecutiveCalls(
                ['class' => 'form-control'],
                ['value' => 'test-value']
            ));
            
        $model->method('hasError')
            ->with('testAttribute')
            ->willReturn(false);
            
        $model->method('property')
            ->with('testAttribute')
            ->willReturn('');
            
        // Mock the attribute value
        $model->expects($this->any())
            ->method('__get')
            ->with('testAttribute')
            ->will($this->returnValue('test-value'));
            
        $meterField = new MeterField($model, 'testAttribute');
        
        $result = $meterField->renderInput();
        
        $expected = '<meter class="form-control" name="testAttribute" value="test-value" >test-value</meter>';
        
        $this->assertEquals($expected, $result);
    }
    
    public function test_renderInput_with_error()
    {
        $model = $this->createMock(Model::class);
        $model->method('getProperty')
            ->will($this->onConsecutiveCalls(
                ['class' => 'form-control'],
                ['value' => 'test-value']
            ));
            
        $model->method('hasError')
            ->with('testAttribute')
            ->willReturn(true);
            
        $model->method('property')
            ->with('testAttribute')
            ->willReturn('');
            
        // Mock the attribute value
        $model->expects($this->any())
            ->method('__get')
            ->with('testAttribute')
            ->will($this->returnValue('test-value'));
        
        $meterField = new MeterField($model, 'testAttribute');
        
        $result = $meterField->renderInput();
        
        $expected = '<meter class="form-control is-invalid" name="testAttribute" value="test-value" >test-value</meter>';
        
        $this->assertEquals($expected, $result);
    }
    
    public function test_renderInput_with_empty_value()
    {
        $model = $this->createMock(Model::class);
        $model->method('getProperty')
            ->will($this->onConsecutiveCalls(
                ['class' => 'form-control'],
                ['value' => '']
            ));
            
        $model->method('hasError')
            ->with('testAttribute')
            ->willReturn(false);
            
        $model->method('property')
            ->with('testAttribute')
            ->willReturn('');
            
        // Mock the attribute value
        $model->expects($this->any())
            ->method('__get')
            ->with('testAttribute')
            ->will($this->returnValue(''));
        
        $meterField = new MeterField($model, 'testAttribute');
        
        $result = $meterField->renderInput();
        
        $expected = '<meter class="form-control" name="testAttribute" value="" ></meter>';
        
        $this->assertEquals($expected, $result);
    }
}
