<?php

declare(strict_types=1);

namespace Tests\Atom\Form;

use Atom\Form\TextareaField;
use Atom\Model;
use PHPUnit\Framework\TestCase;

class TextareaFieldTest extends TestCase
{
    public function testRenderInputWithModelValue()
    {
        $model = $this->createMock(Model::class);
        $model->method('getProperty')
            ->will($this->returnCallback(function ($attribute, $property) {
                if ($attribute === 'testAttribute' && $property === 'class') {
                    return 'form-control';
                }
                return null;
            }));
        
        $model->expects($this->any())
            ->method('__get')
            ->with('testAttribute')
            ->willReturn('Test value');
            
        $model->expects($this->any())
            ->method('hasError')
            ->with('testAttribute')
            ->willReturn(false);
            
        $model->expects($this->any())
            ->method('property')
            ->with('testAttribute')
            ->willReturn('');
            
        $field = new TextareaField($model, 'testAttribute');
        $result = $field->renderInput();
        
        $this->assertStringContainsString('class="form-control form-control"', $result);
        $this->assertStringContainsString('testAttribute', $result);
        $this->assertStringContainsString('Test value', $result);
    }
    
    public function testRenderInputWithValueAttribute()
    {
        $model = $this->createMock(Model::class);
        $model->method('getProperty')
            ->will($this->returnCallback(function ($attribute, $property) {
                if ($attribute === 'testAttribute' && $property === 'class') {
                    return null;
                }
                if ($attribute === 'testAttribute' && $property === 'value') {
                    return 'value from attribute';
                }
                return null;
            }));
        
        $model->expects($this->any())
            ->method('__get')
            ->with('testAttribute')
            ->willReturn(null);
            
        $model->expects($this->any())
            ->method('hasError')
            ->with('testAttribute')
            ->willReturn(false);
            
        $model->expects($this->any())
            ->method('property')
            ->with('testAttribute')
            ->willReturn('');
            
        $field = new TextareaField($model, 'testAttribute');
        $result = $field->renderInput();
        
        $this->assertStringContainsString('value="value from attribute"', $result);
    }
    
    public function testRenderInputWithError()
    {
        $model = $this->createMock(Model::class);
        $model->method('getProperty')
            ->will($this->returnCallback(function ($attribute, $property) {
                if ($attribute === 'testAttribute' && $property === 'class') {
                    return null;
                }
                return null;
            }));
        
        $model->expects($this->any())
            ->method('__get')
            ->with('testAttribute')
            ->willReturn('Test value');
            
        $model->expects($this->any())
            ->method('hasError')
            ->with('testAttribute')
            ->willReturn(true);
            
        $model->expects($this->any())
            ->method('property')
            ->with('testAttribute')
            ->willReturn('');
            
        $field = new TextareaField($model, 'testAttribute');
        $result = $field->renderInput();
        
        $this->assertStringContainsString('is-invalid', $result);
    }
    
    public function testRenderInputWithoutValue()
    {
        $model = $this->createMock(Model::class);
        $model->method('getProperty')
            ->will($this->returnCallback(function ($attribute, $property) {
                if ($attribute === 'testAttribute' && $property === 'class') {
                    return null;
                }
                return null;
            }));
        
        $model->expects($this->any())
            ->method('__get')
            ->with('testAttribute')
            ->willReturn(null);
            
        $model->expects($this->any())
            ->method('hasError')
            ->with('testAttribute')
            ->willReturn(false);
            
        $model->expects($this->any())
            ->method('property')
            ->with('testAttribute')
            ->willReturn('');
            
        $field = new TextareaField($model, 'testAttribute');
        $result = $field->renderInput();
        
        $this->assertStringContainsString('<textarea', $result);
        $this->assertStringContainsString('name="testAttribute"', $result);
    }
}
