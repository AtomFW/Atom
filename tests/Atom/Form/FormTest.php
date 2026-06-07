<?php

declare(strict_types=1);

namespace Tests\Atom\Form;

use PHPUnit\Framework\TestCase;
use Atom\Model;
use Atom\form\Form;

class FormTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clear any output that might affect tests
        if (ob_get_level()) {
            ob_end_clean();
        }
    }

    public function testBeginCreatesFormWithTagWithCorrectAttributes()
    {
        // Create a mock model for testing
        $model = $this->createMock(Model::class);
        
        // Start output buffering to capture form opening tag
        ob_start();
        Form::begin('/test', 'POST');
        $output = ob_get_clean();
        
        // Check that the form was created with correct attributes
        $this->assertStringContainsString('<form action="/test" method="POST">', $output);
    }

    public function testBeginCreatesFormWithTagWithOptions()
    {
        $options = [
            'class' => 'form-horizontal',
            'id' => 'test-form'
        ];
        
        ob_start();
        Form::begin('/test', 'POST', $options);
        $output = ob_get_clean();
        
        $this->assertStringContainsString('<form action="/test" method="POST" class="form-horizontal" id="test-form">', $output);
    }

    public function testEndClosesForm()
    {
        // Capture output from begin and end
        ob_start();
        Form::begin('/test', 'POST');
        Form::end();
        $output = ob_get_clean();
        
        // Should contain both opening and closing form tags
        $this->assertStringContainsString('<form', $output);
        $this->assertStringContainsString('</form>', $output);
    }

    public function testFieldReturnsCorrectFieldInstance()
    {
        $model = $this->createMock(Model::class);
        
        // Create a new Form instance to call field method
        $form = new Form();
        $field = $form->field($model, 'attribute');
        
        $this->assertInstanceOf(\Atom\form\Field::class, $field);
    }

    public function testTextareaFieldReturnsCorrectInstance()
    {
        $model = $this->createMock(Model::class);
        
        $form = new Form();
        $field = $form->textareaField($model, 'attribute');
        
        $this->assertInstanceOf(\Atom\form\TextareaField::class, $field);
    }

    public function testSelectFieldReturnsCorrectInstance()
    {
        $model = $this->createMock(Model::class);
        
        $form = new Form();
        $field = $form->selectField($model, 'attribute');
        
        $this->assertInstanceOf(\Atom\form\SelectField::class, $field);
    }

    public function testProgressFieldReturnsCorrectInstance()
    {
        $model = $this->createMock(Model::class);
        
        $form = new Form();
        $field = $form->progressField($model, 'attribute');
        
        $this->assertInstanceOf(\Atom\form\ProgressField::class, $field);
    }

    public function testMeterFieldReturnsCorrectInstance()
    {
        $model = $this->createMock(Model::class);
        
        $form = new Form();
        $field = $form->meterField($model, 'attribute');
        
        $this->assertInstanceOf(\Atom\form\MeterField::class, $field);
    }

    public function testBeginReturnsFormInstance()
    {
        $result = Form::begin('/test', 'POST');
        $this->assertInstanceOf(Form::class, $result);
    }
}
