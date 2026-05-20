<?php

declare(strict_types=1);

namespace Atom\form;

use Atom\Model;

/**
 * Class representing a multi-line text input field.
 * Used to create a textarea-type field in forms.
 */
class TextareaField extends BaseField
{
    public const TYPE_TEXTAREA = 'textarea';

    /**
     * Field constructor.
     *
     * @param \Atom\Model $model
     * @param string $attribute
     */
    public function __construct(Model $model, string $attribute)
    {
        $this->type = self::TYPE_TEXTAREA;
        parent::__construct($model, $attribute);
    }

    /**
     * Renders the HTML input element for the textarea field.
     *
     * This method generates the complete HTML markup for a textarea element,
     * including appropriate classes based on validation state and any
     * additional attributes from the model's property method. The content
     * of the textarea is set to the attribute value from the model, with
     * fallback to a value attribute if provided and no model value exists.
     *
     * @return string The HTML markup for the textarea input field
     */
    public function renderInput(): string
    {
        $classAttrubute = $this->model->getProperty($this->attribute, 'class');
        $valueAttrubute = $this->model->getProperty($this->attribute, 'value');

        $inputClass = $this->model->hasError($this->attribute) ? ' is-invalid' : '';
        if ($classAttrubute) $inputClass .= ' ' . $classAttrubute;

        $inputValue = $this->model->{$this->attribute};
        if ($valueAttrubute && empty($inputValue)) $inputValue = $valueAttrubute;

        return \sprintf('<%s class="form-control%s" name="%s" %s>%s</%s>',
            $this->type,
            $inputClass,
            $this->attribute,
            $this->model->property($this->attribute),
            $inputValue,
            $this->type
        );
    }
}
