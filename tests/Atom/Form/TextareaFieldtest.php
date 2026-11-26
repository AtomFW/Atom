<?php

declare(strict_types=1);

namespace Tests\Atom\Form;

use Atom\Form\TextareaField;
use Atom\Model;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for the TextareaField component.
 */
class TextareaFieldTest extends TestCase
{
    /**
     * Test render input with model value.
     */
    public function testRenderInputWithModelValue()
    {
        $model = new class extends Model {
            public $testAttribute = 'Test value';
            
            public function form(): array {
                return [
                    'testAttribute' => [
                        'class' => 'form-control'
                    ]
                ];
            }
        };
        
        $field = new TextareaField($model, 'testAttribute');
        $result = $field->renderInput();
        
        $this->assertStringContainsString('class="form-control form-control"', $result);
        $this->assertStringContainsString('testAttribute', $result);
        $this->assertStringContainsString('Test value', $result);
    }
    
    /**
     * Test render input with value attribute.
     */
    public function testRenderInputWithValueAttribute()
    {
        $model = new class extends Model {
            public $testAttribute = '';
            
            public function form(): array {
                return [
                    'testAttribute' => [
                        'value' => 'value from attribute'
                    ]
                ];
            }
        };
        
        $field = new TextareaField($model, 'testAttribute');
        $result = $field->renderInput();
        
        // Textarea renders its value between the tags, not as a value="" attribute
        $this->assertStringContainsString('>value from attribute</textarea>', $result);
    }
    
    /**
     * Test render input with error.
     */
    public function testRenderInputWithError()
    {
        $model = new class extends Model {
            public $testAttribute = 'Test value';
            
            public function hasError($attribute) {
                return true;
            }
        };
        
        $field = new TextareaField($model, 'testAttribute');
        $result = $field->renderInput();
        
        $this->assertStringContainsString('is-invalid', $result);
    }
    
    /**
     * Test render input without value.
     */
    public function testRenderInputWithoutValue()
    {
        $model = new class extends Model {
            public $testAttribute = '';
        };
        
        $field = new TextareaField($model, 'testAttribute');
        $result = $field->renderInput();
        
        $this->assertStringContainsString('<textarea', $result);
        $this->assertStringContainsString('name="testAttribute"', $result);
    }
}
