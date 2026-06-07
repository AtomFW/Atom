<?php

declare(strict_types=1);

namespace Atom\form;

use Atom\Model;

/**
 * The Field class extends BaseField and handles form fields.
 * It contains methods for creating different types of form fields.
 */
class Field extends BaseField
{
    public const TYPE_TEXT = 'text';
    public const TYPE_PASSWORD = 'password';
    public const TYPE_FILE = 'file';
    public const TYPE_HIDDEN = 'hidden';
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_RADIO = 'radio';
    public const TYPE_DATE = 'date';
    public const TYPE_TIME = 'time';
    public const TYPE_EMAIL = 'email';
    public const TYPE_URL = 'url';
    public const TYPE_NUMBER = 'number';
    public const TYPE_RANGE = 'range';
    public const TYPE_SEARCH = 'search';
    public const TYPE_COLOR = 'color';
    public const TYPE_TEL = 'tel';
    public const TYPE_MONTH = 'month';
    public const TYPE_WEEK = 'week';
    public const TYPE_DATETIME_LOCAL = 'datetime-local';
    public const TYPE_SUBMIT = 'submit';
    public const TYPE_RESET = 'reset';
    public const TYPE_BUTTON = 'button';
    public const TYPE_IMAGE = 'image';
        

    /**
     * Field constructor.
     *
     * @param \Atom\Model $model
     * @param string          $attribute
     */
    public function __construct(Model $model, string $attribute)
    {
        $this->type = self::TYPE_TEXT;
        parent::__construct($model, $attribute);
    }

    /**
     * Renders the HTML for the form field.
     *
     * @return string The HTML code for the form field
     */
    public function renderInput(): string
    {
        $classAttrubute = $this->model->getProperty($this->attribute, 'class');
        $valueAttrubute = $this->model->getProperty($this->attribute, 'value');

        $inputClass = $this->model->hasError($this->attribute) ? ' is-invalid' : '';
        if ($classAttrubute) $inputClass .= ' ' . $classAttrubute;

        $inputValue = $this->model->{$this->attribute};
        if ($valueAttrubute && empty($inputValue)) $inputValue = $valueAttrubute;

        return \sprintf('<input type="%s" name="%s" value="%s" class="form-control%s" %s>',
            $this->type,
            $this->attribute,
            $inputValue,
            $inputClass,
            $this->model->property($this->attribute),
        );
    }

    /**
     * Sets the field type to password.
     *
     * @return Field
     */
    public function passwordField(): Field
    {
        $this->type = self::TYPE_PASSWORD;
        return $this;
    }

    /**
     * Sets the field type to file.
     *
     * @return Field
     */
    public function fileField(): Field
    {
        $this->type = self::TYPE_FILE;
        return $this;
    }

    /**
     * Sets the field type to email.
     *
     * @return Field
     */
    public function emailField(): Field
    {
        $this->type = self::TYPE_EMAIL;
        return $this;
    }

    /**
     * Sets the field type to hidden.
     *
     * @return Field
     */
    public function hiddenField(): Field
    {
        $this->type = self::TYPE_HIDDEN;
        return $this;
    }

    /**
     * Sets the field type to checkbox.
     *
     * @return Field
     */
    public function checkboxField(): Field
    {
        $this->type = self::TYPE_CHECKBOX;
        return $this;
    }

    /**
     * Sets the field type to radio button.
     *
     * @return Field
     */

    public function radioField(): Field
    {
        $this->type = self::TYPE_RADIO;
        return $this;
    }

    /**
     * Sets the field type to date.
     *
     * @return Field
     */
    public function dateField(): Field
    {
        $this->type = self::TYPE_DATE;
        return $this;
    }

    /**
     * Sets the field type to time.
     *
     * @return Field
     */
    public function timeField(): Field
    {
        $this->type = self::TYPE_TIME;
        return $this;
    }

    /**
     * Sets the field type to URL.
     *
     * @return Field
     */
    public function urlField(): Field
    {
        $this->type = self::TYPE_URL;
        return $this;
    }

    /**
     * Sets the field type to number.
     *
     * @return Field
     */
    public function numberField(): Field
    {
        $this->type = self::TYPE_NUMBER;
        return $this;
    }

    /**
     * Sets the field type to range.
     *
     * @return Field
     */
    public function rangeField(): Field
    {
        $this->type = self::TYPE_RANGE;
        return $this;
    }

    /**
     * Sets the field type to search.
     *
     * @return Field
     */
    public function searchField(): Field
    {
        $this->type = self::TYPE_SEARCH;
        return $this;
    }

    /**
     * Sets the field type to color.
     *
     * @return Field
     */
    public function colorField(): Field
    {
        $this->type = self::TYPE_COLOR;
        return $this;
    }

    /**
     * Sets the field type to telephone.
     *
     * @return Field
     */
    public function telField(): Field
    {
        $this->type = self::TYPE_TEL;
        return $this;
    }

    /**
     * Sets the field type to month.
     *
     * @return Field
     */

    public function monthField(): Field
    {
        $this->type = self::TYPE_MONTH;
        return $this;
    }

    /**
     * Sets the field type to week.
     *
     * @return Field
     */
    public function weekField(): Field
    {
        $this->type = self::TYPE_WEEK;
        return $this;
    }

    /**
     * Sets the field type to datetime-local.
     *
     * @return Field
     */
    public function datetimeLocalField(): Field
    {
        $this->type = self::TYPE_DATETIME_LOCAL;
        return $this;
    }

    /**
     * Sets the field type to submit.
     *
     * @return Field
     */
    public function submitField(): Field
    {
        $this->type = self::TYPE_SUBMIT;
        return $this;
    }

    /**
     * Sets the field type to reset.
     *
     * @return Field
     */
    public function resetField(): Field
    {
        $this->type = self::TYPE_RESET;
        return $this;
    }

    /**
     * Sets the field type to button.
     *
     * @return Field
     */
    public function buttonField(): Field
    {
        $this->type = self::TYPE_BUTTON;
        return $this;
    }

    /**
     * Sets the field type to image.
     *
     * @return Field
     */
    public function imageField(): Field
    {
        $this->type = self::TYPE_IMAGE;
        return $this;
    }
}
