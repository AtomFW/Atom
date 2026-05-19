<?php

declare(strict_types=1);

namespace Tests\Atom\Form;

use PHPUnit\Framework\TestCase;
use Atom\Model;
use Atom\Form\ProgressField;
use stdClass;

class ProgressFieldTest extends TestCase
{
    public function test_renderInput_returns_correct_html()
    {
        $model = $this->createMock(Model::class);
        $model->method('getProperty')
            ->will($this->returnValueMap([
                ['progress', 'class', null, 'progress-class'],
                ['progress', 'value', null, '50'],
            ]));
        
        $model->method('hasError')
            ->with('progress')
            ->willReturn(false);
            
        $model->method('property')
            ->with('progress')
            ->willReturn('max="100"');
            
        $model->expects($this->any())
            ->method($this->anything(), 'progress')
            ->willReturn(75);

        $field = new ProgressField($model, 'progress');
        $result = $field->renderInput();
        
        $expected = '<progress class="form-control progress-class" name="progress" value="75" max="100">75</progress>';
        
        $this->assertEquals($expected, $result);
    }

    public function test_renderInput_with_error()
    {
        $model = $this->createMock(Model::class);
        $model->method('getProperty')
            ->will($this->returnValueMap([
                ['progress', 'class', null, 'progress-class'],
                ['progress', 'value', null, '50'],
            ]));
        
        $model->method('hasError')
            ->with('progress')
            ->willReturn(true);
            
        $model->method('property')
            ->with('progress')
            ->willReturn('max="100"');
            
        $model->expects($this->any())
            ->method($this->anything(), 'progress')
            ->willReturn(75);

        $field = new ProgressField($model, 'progress');
        $result = $field->renderInput();
        
        $expected = '<progress class="form-control is-invalid" name="progress" value="75" max="100">75</progress>';
        
        $this->assertEquals($expected, $result);
    }

    public function test_renderInput_with_empty_value()
    {
        $model = $this->createMock(Model::class);
        $model->method('getProperty')
            ->will($this->returnValueMap([
                ['progress', 'class', null, 'progress-class'],
                ['progress', 'value', null, '50'],
            ]));
        
        $model->method('hasError')
            ->with('progress')
            ->willReturn(false);
            
        $model->method('property')
            ->with('progress')
            ->willReturn('max="100"');
            
        $model->expects($this->any())
            ->method($this->anything(), 'progress')
            ->willReturn('');

        $field = new ProgressField($model, 'progress');
        $result = $field->renderInput();
        
        $expected = '<progress class="form-control progress-class" name="progress" value="50" max="100">50</progress>';
        
        $this->assertEquals($expected, $result);
    }
}
