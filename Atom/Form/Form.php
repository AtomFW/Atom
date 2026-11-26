<?php

declare(strict_types=1);

namespace Atom\Form;

use Atom\Filter\InputFilter;
use Atom\Filter\TypeCaster;
use Atom\Model;

/**
 * HTML Form builder with XSS-safe attribute rendering and automatic type-inferred field creation.
 */
class Form
{
    /**
     * Begins an HTML `<form>` element and returns a Form builder instance.
     *
     * @param array<string, scalar> $options
     */
    public static function begin(string $action, string $method, array $options = []): self
    {
        $attributes = [];
        foreach ($options as $key => $value) {
            $safeKey = \preg_replace('/[^a-zA-Z0-9\-_:]/', '', (string) $key) ?? '';
            if ($safeKey === '') {
                continue;
            }
            $safeVal = InputFilter::escapeHtml($value);
            $attributes[] = "{$safeKey}=\"{$safeVal}\"";
        }

        echo \sprintf(
            '<form action="%s" method="%s" %s>',
            InputFilter::escapeHtml($action),
            InputFilter::escapeHtml($method),
            \implode(' ', $attributes),
        );

        return new self();
    }

    public static function end(): void
    {
        echo '</form>';
    }

    public function field(Model $model, string $attribute): Field
    {
        return new Field($model, $attribute);
    }

    /**
     * Automatically selects the optimal HTML5 field/widget (`SelectField`, `TextareaField`, or typed `Field`)
     * based on the model's PHP 8.6 property type, `#[Cast]` metadata, validation rules, and select options.
     */
    public function autoField(Model $model, string $attribute): BaseField
    {
        $selectOptions = $model->getOptionSelects($attribute);
        if (\is_array($selectOptions) && $selectOptions !== []) {
            return new SelectField($model, $attribute);
        }

        $inferredType = TypeCaster::inferHtmlInputType($model, $attribute);
        if ($inferredType === TextareaField::TYPE_TEXTAREA) {
            return new TextareaField($model, $attribute);
        }

        return (new Field($model, $attribute))->setType($inferredType);
    }

    public function textareaField(Model $model, string $attribute): TextareaField
    {
        return new TextareaField($model, $attribute);
    }

    public function selectField(Model $model, string $attribute): SelectField
    {
        return new SelectField($model, $attribute);
    }

    public function progressField(Model $model, string $attribute): ProgressField
    {
        return new ProgressField($model, $attribute);
    }

    public function meterField(Model $model, string $attribute): MeterField
    {
        return new MeterField($model, $attribute);
    }
}
