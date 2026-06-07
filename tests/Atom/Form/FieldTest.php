<?php

declare(strict_types=1);

namespace Tests\Atom\Form;

use PHPUnit\Framework\TestCase;
use Atom\Model;
use Atom\Form\Field;

class FieldTest extends TestCase
{
    public function testFieldConstructorSetsDefaultType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        
        // The constructor should set the default type to text
        $this->assertEquals(Field::TYPE_TEXT, $field->type);
    }

    public function testPasswordFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->passwordField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_PASSWORD, $field->type);
    }

    public function testFileFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->fileField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_FILE, $field->type);
    }

    public function testEmailFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->emailField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_EMAIL, $field->type);
    }

    public function testHiddenFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->hiddenField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_HIDDEN, $field->type);
    }

    public function testCheckboxFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->checkboxField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_CHECKBOX, $field->type);
    }

    public function testRadioFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->radioField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_RADIO, $field->type);
    }

    public function testDateFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->dateField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_DATE, $field->type);
    }

    public function testTimeFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->timeField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_TIME, $field->type);
    }

    public function testUrlFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->urlField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_URL, $field->type);
    }

    public function testNumberFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->numberField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_NUMBER, $field->type);
    }

    public function testRangeFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->rangeField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_RANGE, $field->type);
    }

    public function testSearchFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->searchField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_SEARCH, $field->type);
    }

    public function testColorFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->colorField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_COLOR, $field->type);
    }

    public function testTelFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->telField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_TEL, $field->type);
    }

    public function testMonthFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->monthField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_MONTH, $field->type);
    }

    public function testWeekFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->weekField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_WEEK, $field->type);
    }

    public function testDatetimeLocalFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->datetimeLocalField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_DATETIME_LOCAL, $field->type);
    }

    public function testSubmitFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->submitField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_SUBMIT, $field->type);
    }

    public function testResetFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->resetField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_RESET, $field->type);
    }

    public function testButtonFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->buttonField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_BUTTON, $field->type);
    }

    public function testImageFieldSetsType()
    {
        $model = $this->createMock(Model::class);
        $field = new Field($model, 'testAttribute');
        $result = $field->imageField();
        
        $this->assertInstanceOf(Field::class, $result);
        $this->assertEquals(Field::TYPE_IMAGE, $field->type);
    }

    public function testRenderInputRendersCorrectly()
    {
        $model = $this->createMock(Model::class);
        $model->method('getProperty')
            ->willReturnCallback(function ($attribute, $key) {
                if ($key === 'class') return 'form-control';
                if ($key === 'value') return '';
                return null;
            });
        
        $model->expects($this->any())
            ->method('hasError')
            ->with('testAttribute')
            ->willReturn(false);
            
        $model->expects($this->any())
            ->method('property')
            ->with('testAttribute')
            ->willReturn('');
            
        $field = new Field($model, 'testAttribute');
        $result = $field->renderInput();
        
        $this->assertStringContainsString('type="text"', $result);
        $this->assertStringContainsString('name="testAttribute"', $result);
    }
}
