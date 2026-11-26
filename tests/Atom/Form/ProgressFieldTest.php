<?php

declare(strict_types=1);

namespace Tests\Atom\Form;

use PHPUnit\Framework\TestCase;
use Atom\Model;
use Atom\Form\ProgressField;

/**
 * Test suite for the ProgressField component.
 */
class ProgressFieldTest extends TestCase
{
    /**
     * Test render input returns correct html.
     */
    public function test_renderInput_returns_correct_html()
    {
        $model = new class extends Model {
            public $progress = 75;
            
            public function form(): array {
                return [
                    'progress' => [
                        'class' => 'progress-class',
                        'value' => '50',
                        'max' => '100'
                    ]
                ];
            }
        };

        $field = new ProgressField($model, 'progress');
        $result = $field->renderInput();
        
        $expected = '<progress class="form-control progress-class" name="progress" value="75" max="100">75</progress>';
        
        $this->assertEquals($expected, $result);
    }

    /**
     * Test render input with error.
     */
    public function test_renderInput_with_error()
    {
        $model = new class extends Model {
            public $progress = 75;
            
            public function hasError($attribute)
            {
                return true;
            }
            
            public function form(): array {
                return [
                    'progress' => [
                        'value' => '50',
                        'max' => '100'
                    ]
                ];
            }
        };

        $field = new ProgressField($model, 'progress');
        $result = $field->renderInput();
        
        $expected = '<progress class="form-control is-invalid" name="progress" value="75" max="100">75</progress>';
        
        $this->assertEquals($expected, $result);
    }

    /**
     * Test render input with empty value.
     */
    public function test_renderInput_with_empty_value()
    {
        $model = new class extends Model {
            public $progress = '';
            
            public function form(): array {
                return [
                    'progress' => [
                        'class' => 'progress-class',
                        'value' => '50',
                        'max' => '100'
                    ]
                ];
            }
        };

        $field = new ProgressField($model, 'progress');
        $result = $field->renderInput();
        
        $expected = '<progress class="form-control progress-class" name="progress" value="50" max="100">50</progress>';
        
        $this->assertEquals($expected, $result);
    }
}
