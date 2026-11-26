<?php

declare(strict_types=1);

namespace Atom\Form;

use Atom\Filter\InputFilter;
use Atom\Model;
use Override;

/**
 * Multi-line `<textarea>` form field with XSS-safe content escaping.
 */
class TextareaField extends BaseField
{
    public const string TYPE_TEXTAREA = 'textarea';

    public function __construct(Model $model, string $attribute)
    {
        $this->type = self::TYPE_TEXTAREA;
        parent::__construct($model, $attribute);
    }

    #[Override]
    public function renderInput(): string
    {
        $inputClass = $this->resolveInputClass();
        $inputValue = $this->resolveInputValue();

        return \sprintf(
            '<%s class="form-control%s" name="%s" %s>%s</%s>',
            $this->type,
            $inputClass,
            InputFilter::escapeHtml($this->attribute),
            $this->model->property($this->attribute),
            $inputValue,
            $this->type,
        );
    }
}
