<?php

declare(strict_types=1);

namespace Tests\Atom\Form;

use Atom\Model;
use PHPUnit\Framework\TestCase;
use Atom\Form\SelectField;

class SelectFieldTest extends TestCase
{
    public function test_renderInput_outputs_correct_html(): void
    {
        $model = $this->createMock(Model::class);
        $model->method('getProperty')
            ->willReturnCallback(function ($attribute, $default = null) {
                if ($attribute === 'testAttribute' && $default === 'class') {
                    return 'custom-class';
                }
                return $default;
            });
        
        $model->method('hasError')->with('testAttribute')->willReturn(false);
        $model->method('getOptionSelects')->willReturn(['option1' => 'Option 1', 'option2' => 'Option 2']);
        $model->testAttribute = 'option1';
        
        $selectField = new SelectField($model, 'testAttribute');
        $result = $selectField->renderInput();
        
        $this->assertStringContainsString('<select', $result);
        $this->assertStringContainsString('class="form-control custom-class"', $result);
        $this->assertStringContainsString('name="testAttribute"', $result);
        $this->assertStringContainsString('option1', $result);
        $this->assertStringContainsString('Option 2', $result);
    }

    public function test_renderInput_with_errors(): void
    {
        $model = $this->createMock(Model::class);
        $model->method('getProperty')->willReturnCallback(function ($attribute, $default = null) {
            if ($attribute === 'testAttribute' && $default === 'class') {
                return 'custom-class';
            }
            return $default;
        });
        
        $model->method('hasError')->with('testAttribute')->willReturn(true);
        $model->method('getOptionSelects')->willReturn([]);
        
        $selectField = new SelectField($model, 'testAttribute');
        $result = $selectField->renderInput();
        
        $this->assertStringContainsString('class="form-control is-invalid"', $result);
    }

    public function test_options_generates_correct_html(): void
    {
        $model = $this->createMock(Model::class);
        $selectField = new SelectField($model, 'testAttribute');
        
        $options = [
            'option1' => 'Option 1',
            'option2' => 'Option 2'
        ];
        
        $result = $selectField->options($options);
        
        $this->assertStringContainsString('<option value="option1">Option 1</option>', $result);
        $this->assertStringContainsString('<option value="option2">Option 2</option>', $result);
    }

    public function test_options_with_attributes(): void
    {
        $model = $this->createMock(Model::class);
        $selectField = new SelectField($model, 'testAttribute');
        
        $options = [
            'option1' => ['Option 1', 'selected'],
            'option2' => ['Option 2', 'disabled']
        ];
        
        $result = $selectField->options($options);
        
        $this->assertStringContainsString('value="option1" selected>Option 1</option>', $result);
        $this->assertStringContainsString('value="option2" disabled>Option 2</option>', $result);
    }

    public function test_renderInput_with_empty_options(): void
    {
        $model = $this->createMock(Model::class);
        $model->method('hasError')->willReturn(false);
        $model->method('getOptionSelects')->willReturn([]);
        
        $selectField = new SelectField($model, 'testAttribute');
        $result = $selectField->renderInput();
        
        $this->assertStringContainsString('<select', $result);
        $this->assertStringContainsString('name="testAttribute"', $result);
    }

    public function test_renderInput_with_model_property(): void
    {
        $model = $this->createMock(Model::class);
        $model->method('getProperty')
            ->willReturnCallback(function ($attribute, $default = null) {
                if ($attribute === 'testAttribute' && $default === 'class') {
                    return 'custom-class';
                }
                if ($attribute === 'testAttribute' && $default === 'value') {
                    return 'model-value';
                }
                return $default;
            });
        
        $model->method('hasError')->willReturn(false);
        $model->method('getOptionSelects')->willReturn([]);
        $model->testAttribute = 'model-value';
        
        $selectField = new SelectField($model, 'testAttribute');
        $result = $selectField->renderInput();
        
        $this->assertStringContainsString('value="model-value"', $result);
    }
}
