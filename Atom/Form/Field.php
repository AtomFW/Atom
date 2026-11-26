<?php

declare(strict_types=1);

namespace Atom\Form;

use Atom\Filter\InputFilter;
use Atom\Filter\TypeCaster;
use Atom\Model;
use Override;

/**
 * Standard `<input>` form field supporting all HTML5 input types and automatic PHP 8.6 type inference.
 */
class Field extends BaseField
{
    public const string TYPE_TEXT = 'text';
    public const string TYPE_PASSWORD = 'password';
    public const string TYPE_FILE = 'file';
    public const string TYPE_HIDDEN = 'hidden';
    public const TYPE_CHECKBOX = 'checkbox';
    public const string TYPE_RADIO = 'radio';
    public const string TYPE_DATE = 'date';
    public const string TYPE_TIME = 'time';
    public const string TYPE_EMAIL = 'email';
    public const string TYPE_URL = 'url';
    public const string TYPE_NUMBER = 'number';
    public const string TYPE_RANGE = 'range';
    public const string TYPE_SEARCH = 'search';
    public const string TYPE_COLOR = 'color';
    public const string TYPE_TEL = 'tel';
    public const string TYPE_MONTH = 'month';
    public const string TYPE_WEEK = 'week';
    public const string TYPE_DATETIME_LOCAL = 'datetime-local';
    public const string TYPE_SUBMIT = 'submit';
    public const string TYPE_RESET = 'reset';
    public const string TYPE_BUTTON = 'button';
    public const string TYPE_IMAGE = 'image';

    public function __construct(Model $model, string $attribute)
    {
        $this->type = self::TYPE_TEXT;
        parent::__construct($model, $attribute);
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Automatically infers the HTML5 `<input type="...">` from the model property type, `#[Cast]`, and rules.
     */
    public function autoType(): self
    {
        $this->type = TypeCaster::inferHtmlInputType($this->model, $this->attribute);
        return $this;
    }

    #[Override]
    public function renderInput(): string
    {
        $inputClass = $this->resolveInputClass();
        $inputValue = $this->resolveInputValue();
        $extraProps = $this->model->property($this->attribute);

        return \sprintf(
            '<input type="%s" name="%s" value="%s" class="form-control%s" %s>',
            InputFilter::escapeHtml($this->type),
            InputFilter::escapeHtml($this->attribute),
            $inputValue,
            $inputClass,
            $extraProps,
        );
    }

    public function passwordField(): self
    {
        $this->type = self::TYPE_PASSWORD;
        return $this;
    }

    public function fileField(): self
    {
        $this->type = self::TYPE_FILE;
        return $this;
    }

    public function emailField(): self
    {
        $this->type = self::TYPE_EMAIL;
        return $this;
    }

    public function hiddenField(): self
    {
        $this->type = self::TYPE_HIDDEN;
        return $this;
    }

    public function checkboxField(): self
    {
        $this->type = self::TYPE_CHECKBOX;
        return $this;
    }

    public function radioField(): self
    {
        $this->type = self::TYPE_RADIO;
        return $this;
    }

    public function dateField(): self
    {
        $this->type = self::TYPE_DATE;
        return $this;
    }

    public function timeField(): self
    {
        $this->type = self::TYPE_TIME;
        return $this;
    }

    public function urlField(): self
    {
        $this->type = self::TYPE_URL;
        return $this;
    }

    public function numberField(): self
    {
        $this->type = self::TYPE_NUMBER;
        return $this;
    }

    public function rangeField(): self
    {
        $this->type = self::TYPE_RANGE;
        return $this;
    }

    public function searchField(): self
    {
        $this->type = self::TYPE_SEARCH;
        return $this;
    }

    public function colorField(): self
    {
        $this->type = self::TYPE_COLOR;
        return $this;
    }

    public function telField(): self
    {
        $this->type = self::TYPE_TEL;
        return $this;
    }

    public function monthField(): self
    {
        $this->type = self::TYPE_MONTH;
        return $this;
    }

    public function weekField(): self
    {
        $this->type = self::TYPE_WEEK;
        return $this;
    }

    public function datetimeLocalField(): self
    {
        $this->type = self::TYPE_DATETIME_LOCAL;
        return $this;
    }

    public function submitField(): self
    {
        $this->type = self::TYPE_SUBMIT;
        return $this;
    }

    public function resetField(): self
    {
        $this->type = self::TYPE_RESET;
        return $this;
    }

    public function buttonField(): self
    {
        $this->type = self::TYPE_BUTTON;
        return $this;
    }

    public function imageField(): self
    {
        $this->type = self::TYPE_IMAGE;
        return $this;
    }
}
