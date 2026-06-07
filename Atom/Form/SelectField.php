<?php

declare(strict_types=1);

namespace Atom\form;

use Atom\Model;

/**
 * SelectField class represents a select input field in a form.
 * It extends the BaseField class and provides functionality for creating
 * dropdown selection fields with various options.
 */
class SelectField extends BaseField
{
    public const TYPE_SELECT = 'select';

    /**
     * Field constructor.
     *
     * @param \Atom\Model $model
     * @param string $attribute
     */
    public function __construct(Model $model, string $attribute)
    {
        $this->type = self::TYPE_SELECT;
        parent::__construct($model, $attribute);
    }

    /**
     * Render a select input field with options
     *
     * This method generates the HTML for a <select> element including:
     * - Class attributes with error handling
     * - Value attributes from model properties
     * - option elements generated from model configuration
     * - Proper HTML structure with opening and closing tags
     *
     * @return string The rendered HTML for the select field
     */
    public function renderInput(): string
    {
        $classAttrubute = $this->model->getProperty($this->attribute, 'class');
        $valueAttrubute = $this->model->getProperty($this->attribute, 'value');

        $inputClass = $this->model->hasError($this->attribute) ? ' is-invalid' : '';
        if ($classAttrubute) $inputClass .= ' ' . $classAttrubute;

        $inputValue = $this->model->{$this->attribute};
        if ($valueAttrubute && empty($inputValue)) $inputValue = $valueAttrubute;

        $option = $this->model->getOptionSelects($this->attribute) ?? '';
        if ($option) {
            $option = $this->options($option);
        }

        return \sprintf('<%s class="form-control%s" name="%s" value="%s" %s>%s</%s>',
            $this->type,
            $inputClass,
            $this->attribute,
            $inputValue,
            $this->model->property($this->attribute),
            $option,
            $this->type
        );
    }

    /**
     * Generates HTML option elements for a select field.
     *
     * @param array $options An associative array of options where keys are values and values are labels.
     * @return string The concatenated HTML option tags.
     */
    public function options(array $options): string
    {
        $temp = '';

        foreach ($options as $key => $value) {
            $val = \is_array($value) ? $value[0] : $value;
            $attribute = \is_array($value) && isset($value[1]) ? $value[1] : '';

            $temp .= \sprintf('<option value="%s" %s>%s</option>', $key, $attribute, $val);
        }

        return $temp;
    }
}
