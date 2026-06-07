<?php

declare(strict_types=1);

namespace Atom\form;

use Atom\Model;

/**
 * Class representing a measurement field in the form.
 * Extends the base form field with measurement-specific functionalities.
 */
class MeterField extends BaseField
{
    /**
     * Constant representing a meter type measurement.
     * Used for defining meter field configurations.
     */
    public const TYPE_METER = 'meter';

    /**
     * Field constructor.
     *
     * @param \Atom\Model $model
     * @param string $attribute
     */
    public function __construct(Model $model, string $attribute)
    {
        $this->type = self::TYPE_METER;
        parent::__construct($model, $attribute);
    }

    /**
     * Render a meter input field with proper class handling and value assignment
     *
     * @return string HTML markup for the meter input element
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
