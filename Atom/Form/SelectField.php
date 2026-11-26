<?php

declare(strict_types=1);

namespace Atom\Form;

use Atom\Filter\InputFilter;
use Atom\Model;
use Override;

/**
 * Dropdown `<select>` form field with XSS-safe option rendering.
 */
class SelectField extends BaseField
{
    public const string TYPE_SELECT = 'select';

    public function __construct(Model $model, string $attribute)
    {
        $this->type = self::TYPE_SELECT;
        parent::__construct($model, $attribute);
    }

    #[Override]
    public function renderInput(): string
    {
        $inputClass = $this->resolveInputClass();
        $inputValue = $this->resolveInputValue();

        $rawOptions = $this->model->getOptionSelects($this->attribute);
        $optionsHtml = \is_array($rawOptions) && $rawOptions !== []
            ? $this->options($rawOptions)
            : '';

        return \sprintf(
            '<%s class="form-control%s" name="%s" value="%s" %s>%s</%s>',
            $this->type,
            $inputClass,
            InputFilter::escapeHtml($this->attribute),
            $inputValue,
            $this->model->property($this->attribute),
            $optionsHtml,
            $this->type,
        );
    }

    /**
     * @param array<int|string, mixed> $options
     */
    public function options(array $options): string
    {
        $html = '';

        foreach ($options as $key => $value) {
            $label = \is_array($value) ? ($value[0] ?? '') : $value;
            $rawAttr = \is_array($value) && isset($value[1]) ? (string) $value[1] : '';
            $safeAttr = \preg_replace('/[^a-zA-Z0-9\-_="\' ]/', '', $rawAttr) ?? '';

            $html .= \sprintf(
                '<option value="%s" %s>%s</option>',
                InputFilter::escapeHtml($key),
                $safeAttr,
                InputFilter::escapeHtml($label),
            );
        }

        return $html;
    }
}
