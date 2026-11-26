<?php

declare(strict_types=1);

namespace Tests\Atom\Form;

use PHPUnit\Framework\TestCase;

/**
 * Test suite for the BaseField component.
 */
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

        $this->field = $this->getMockBuilder(\Atom\form\BaseField::class)->setConstructorArgs([$this->model, 'test'])->onlyMethods(['renderInput'])->getMock();
        $this->field->method('renderInput')
                   ->willReturn('<input type="text" name="test" value="">');
    }

    /**
     * Test constructor sets properties.
     */
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

    /**
     * Test to string returns correct string.
     */
    public function testToStringReturnsCorrectString()
    {
        $result = (string)$this->field;
        
        $this->assertStringContainsString('<div class="form-group"', $result);
        $this->assertStringContainsString('Test Label', $result);
        $this->assertStringContainsString('<input type="text" name="test" value="">', $result);
    }

}
