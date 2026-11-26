<?php

declare(strict_types=1);

namespace Tests\Atom\Form;

use PHPUnit\Framework\TestCase;
use Atom\Model;
use Atom\form\Form;

/**
 * Test suite for the Form component.
 */
class FormTest extends TestCase
{
    private int $initialObLevel;

    protected function setUp(): void
    {
        $this->initialObLevel = ob_get_level();
    }

    protected function tearDown(): void
    {
        // Clear any output that might affect tests, but don't close PHPUnit's buffers
        while (ob_get_level() > $this->initialObLevel) {
            ob_end_clean();
        }
    }

    /**
     * Test begin creates form with tag with correct attributes.
     */
    public function testBeginCreatesFormWithTagWithCorrectAttributes()
    {
        // Create a mock model for testing
        $model = $this->createMock(Model::class);
        
        // Start output buffering to capture form opening tag
        ob_start();
        Form::begin('/test', 'POST');
        $output = ob_get_clean();
        
        // Check that the form was created with correct attributes
        $this->assertStringContainsString('<form action="/test" method="POST" >', $output);
    }

    /**
     * Test begin creates form with tag with options.
     */
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

    /**
     * Test end closes form.
     */
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

    /**
     * Test field returns correct field instance.
     */
    public function testFieldReturnsCorrectFieldInstance()
    {
        $model = $this->createMock(Model::class);
        
        // Create a new Form instance to call field method
        $form = new Form();
        $field = $form->field($model, 'attribute');
        
        $this->assertInstanceOf(\Atom\form\Field::class, $field);
    }

    /**
     * Test textarea field returns correct instance.
     */
    public function testTextareaFieldReturnsCorrectInstance()
    {
        $model = $this->createMock(Model::class);
        
        $form = new Form();
        $field = $form->textareaField($model, 'attribute');
        
        $this->assertInstanceOf(\Atom\form\TextareaField::class, $field);
    }

    /**
     * Test select field returns correct instance.
     */
    public function testSelectFieldReturnsCorrectInstance()
    {
        $model = $this->createMock(Model::class);
        
        $form = new Form();
        $field = $form->selectField($model, 'attribute');
        
        $this->assertInstanceOf(\Atom\form\SelectField::class, $field);
    }

    /**
     * Test progress field returns correct instance.
     */
    public function testProgressFieldReturnsCorrectInstance()
    {
        $model = $this->createMock(Model::class);
        
        $form = new Form();
        $field = $form->progressField($model, 'attribute');
        
        $this->assertInstanceOf(\Atom\form\ProgressField::class, $field);
    }

    /**
     * Test meter field returns correct instance.
     */
    public function testMeterFieldReturnsCorrectInstance()
    {
        $model = $this->createMock(Model::class);
        
        $form = new Form();
        $field = $form->meterField($model, 'attribute');
        
        $this->assertInstanceOf(\Atom\form\MeterField::class, $field);
    }

    /**
     * Test begin returns form instance.
     */
    public function testBeginReturnsFormInstance()
    {
        ob_start();
        $result = Form::begin('/test', 'POST');
        ob_end_clean();
        $this->assertInstanceOf(Form::class, $result);
    }
}
