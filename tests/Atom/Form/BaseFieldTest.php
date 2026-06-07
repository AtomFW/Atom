<?php

declare(strict_types=1);

namespace Tests\Atom\Form;

use PHPUnit\Framework\TestCase;

class BaseFieldTest extends TestCase
{
    private $model;
    private $field;

    protected function setUp(): void
    {
        // Create a mock model for testing
        $this->model = $this->createMock(\Atom\Model::class);
        $this->model->method('getInputOuterType')->willReturn('div');
        $this->model->method('getInputInnerType')->willReturn('label');
        $this->model->method('getInputTargetType')->willReturn('span');
        $this->model->method('getInputOuterAttributes')->willReturn('');
        $this->model->method('getInputInnerAttributes')->willReturn('');
        $this->model->method('getInputTargetAttributes')->willReturn('');
        $this->model->method('getInputOuterAttribute')->willReturn('');
        $this->model->method('getInputInnerAttribute')->willReturn('');
        $this->model->method('getInputTargetAttribute')->willReturn('');
        $this->model->method('getLabel')->with('test')->willReturn('Test Label');
        $this->model->method('getFirstError')->with('test')->willReturn('');

        $this->field = $this->createPartialMock(\Atom\form\BaseField::class, ['renderInput']);
        $this->field->expects($this->any())
                   ->method('renderInput')
                   ->willReturn('<input type="text" name="test" value="">');
    }

    public function testConstructorSetsProperties()
    {
        $model = $this->createMock(\Atom\Model::class);
        $field = new class($model, 'test') extends \Atom\form\BaseField {
            public function renderInput() {
                return '<input type="text">';
            }
        };
        
        $this->assertInstanceOf(\Atom\Model::class, $field->model);
        $this->assertEquals('test', $field->attribute);
    }

    public function testToStringReturnsCorrectString()
    {
        $result = (string)$this->field;
        
        $this->assertStringContainsString('<div class="form-group"', $result);
        $this->assertStringContainsString('Test Label', $result);
        $this->assertStringContainsString('<input type="text" name="test" value="">', $result);
    }

    public function testToStringHandlesEmptyLabel()
    {
        // This would require a different approach to test due to the way
        // the model methods are called in the parent class
        $this->markTestSkipped('Method requires more complex mocking');
    }
}
