<?php

declare(strict_types=1);

namespace Atom\form;

use Atom\Model;

/**
 * Class representing a progress field in a form
 *
 * @package Atom\form
 */
class ProgressField extends BaseField
{
    public const TYPE_PROGRESS = 'progress';

    /**
     * Field constructor.
     *
     * @param \Atom\Model $model
     * @param string $attribute
     */
    public function __construct(Model $model, string $attribute)
    {
        $this->type = self::TYPE_PROGRESS;
        parent::__construct($model, $attribute);
    }

    /**
     * Render a progress input field.
     *
     * @return string
     */
    public function renderInput(): string
    {
        $classAttrubute = $this->model->getProperty($this->attribute, 'class');
        $valueAttrubute = $this->model->getProperty($this->attribute, 'value');

        $inputClass = $this->model->hasError($this->attribute) ? ' is-invalid' : '';
        if ($classAttrubute) $inputClass .= ' ' . $classAttrubute;

        $inputValue = $this->model->{$this->attribute};
        if ($valueAttrubute && empty($inputValue)) $inputValue = $valueAttrubute;

        return \sprintf('<%s class="form-control%s" name="%s" value="%s" %s>%s</%s>',
            $this->type, 
            $inputClass,
            $this->attribute,
            $inputValue,
            $this->model->property($this->attribute),
            $inputValue,
            $this->type
        );
    }
}
