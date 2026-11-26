<?php

declare(strict_types=1);

namespace Atom\Form;

use Atom\Filter\InputFilter;
use Atom\Model;
use Override;

/**
 * HTML5 `<meter>` form field.
 */
class MeterField extends BaseField
{
    public const string TYPE_METER = 'meter';

    public function __construct(Model $model, string $attribute)
    {
        $this->type = self::TYPE_METER;
        parent::__construct($model, $attribute);
    }

    #[Override]
    public function renderInput(): string
    {
        $inputClass = $this->resolveInputClass();
        $inputValue = $this->resolveInputValue();

        return \sprintf(
            '<%s class="form-control%s" name="%s" value="%s" %s>%s</%s>',
            $this->type,
            $inputClass,
            InputFilter::escapeHtml($this->attribute),
            $inputValue,
            $this->model->property($this->attribute),
            $inputValue,
            $this->type,
        );
    }
}
