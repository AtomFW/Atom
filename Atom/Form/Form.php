<?php

declare(strict_types=1);

namespace Atom\form;

use Atom\Model;

class Form
{
    public static function begin(string $action, string $method, array $options = []): Form
    {
        $attributes = [];
        foreach ($options as $key => $value) {
            $attributes[] = "$key=\"$value\"";
        }
        echo \sprintf('<form action="%s" method="%s" %s>', $action, $method, implode(" ", $attributes));
        return new Form();
    }

    public static function end(): void
    {
        echo '</form>';
    }

    public function field(Model $model, $attribute): Field
    {
        return new Field($model, $attribute);
    }

    public function textareaField(Model $model, $attribute): TextareaField
    {
        return new TextareaField($model, $attribute);
    }

    public function selectField(Model $model, $attribute): SelectField
    {
        return new SelectField($model, $attribute);
    }

    public function progressField(Model $model, $attribute): ProgressField
    {
        return new ProgressField($model, $attribute);
    }
    
    public function meterField(Model $model, $attribute): MeterField
    {
        return new MeterField($model, $attribute);
    }
}
