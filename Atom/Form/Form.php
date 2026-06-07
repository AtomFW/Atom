<?php

declare(strict_types=1);

namespace Atom\form;

use Atom\Model;

class Form
{

    /**
     * Begins a new HTML form element.
     *
     * @param string $action The action attribute value for the form
     * @param string $method The method attribute value for the form
     * @param array $options Additional HTML attributes for the form
     * @return Form Returns an instance of the Form class
     */
    public static function begin(string $action, string $method, array $options = []): Form
    {
        $attributes = [];
        foreach ($options as $key => $value) {
            $attributes[] = "$key=\"$value\"";
        }
        echo \sprintf('<form action="%s" method="%s" %s>', $action, $method, implode(" ", $attributes));
        return new Form();
    }

    /**
     * Ends the HTML form element.
     *
     * This function outputs the closing `</form>` tag to properly close
     * the HTML form that was started with the begin method. It should be called
     * exactly once for every call to begin to ensure valid HTML structure.
     *
     * @return void
     */
    public static function end(): void
    {
        echo '</form>';
    }

    /**
     * Creates a form field for the given model and attribute.
     *
     * @param Model $model The model instance to create the field for
     * @param string $attribute The name of the attribute to create the field for
     * @return Field Returns a new Field instance
     */
    public function field(Model $model, $attribute): Field
    {
        return new Field($model, $attribute);
    }

    /**
     * Creates a textarea form field for the given model and attribute.
     *
     * @param Model $model The model instance to create the field for
     * @param string $attribute The name of the attribute to create the field for
     * @return TextareaField Returns a new TextareaField instance
     */
    public function textareaField(Model $model, $attribute): TextareaField
    {
        return new TextareaField($model, $attribute);
    }

    /**
     * Creates a select form field for the given model and attribute.
     *
     * @param Model $model The model instance to create the field for
     * @param string $attribute The name of the attribute to create the field for
     * @return SelectField Returns a new SelectField instance
     */
    public function selectField(Model $model, $attribute): SelectField
    {
        return new SelectField($model, $attribute);
    }

    /**
     * Creates a progress form field for the given model and attribute.
     *
     * @param Model $model The model instance to create the field for
     * @param string $attribute The name of the attribute to create the field for
     * @return ProgressField Returns a new ProgressField instance
     */
    public function progressField(Model $model, $attribute): ProgressField
    {
        return new ProgressField($model, $attribute);
    }
    
    /**
     * @param Model $model
     * @param mixed $attribute
     * @return MeterField
     */
    public function meterField(Model $model, $attribute): MeterField
    {
        return new MeterField($model, $attribute);
    }
}
