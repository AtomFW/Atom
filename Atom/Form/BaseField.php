<?php

declare(strict_types=1);

namespace Atom\form;

use Atom\Model;

/**
 * Base class for form fields
 *
 * @package Atom\form
 */
abstract class BaseField
{

    public Model $model;
    public string $attribute;
    public string $type;

    /**
     * Field constructor.
     *
     * @param \Atom\Model $model
     * @param string $attribute
     */
    public function __construct(Model $model, string $attribute)
    {
        $this->model = $model;
        $this->attribute = $attribute;
    }

    /**
     * Returns the HTML representation of the field including label, input and error message.
     *
     * @return string
     */
    public function __toString(): string
    {
        $outerType = $this->model->getInputOuterType() ?? "div";
        $outerAttrs = $this->model->getInputOuterAttributes();
        $outerAttrClass = $this->model->getInputOuterAttribute('class');
        if(!$outerAttrClass) {
            $outerAttrClass = 'form-group';
        }

        $innerType = $this->model->getInputInnerType() ?? "label";
        $innerAttrs = $this->model->getInputInnerAttributes();
        $innerAttrClass = $this->model->getInputInnerAttribute('class');
        if($innerAttrClass) {
           $innerAttrClass = "class=\"{$innerAttrClass}\""; 
        }

        $targetType = $this->model->getInputTargetType();
        $targetAttrs = $this->model->getInputTargetAttributes();
        $targetAttrClass = $this->model->getInputTargetAttribute('class');
        if(!$targetAttrClass) {
            $targetAttrClass = 'invalid-feedback';
        }

        $getLabel = $this->model->getLabel($this->attribute);
        $label = !empty($getLabel) ? "<{$innerType} {$innerAttrClass} for=\"{$this->attribute}\" {$innerAttrs}>%s</{$innerType}>" : '';

        $scheme = "<{$outerType} class=\"{$outerAttrClass}\" {$outerAttrs}>
            {$label}
            %s
            <{$targetType} class=\"{$targetAttrClass}\" {$targetAttrs}>
                %s
            </{$targetType}>
        </{$outerType}>";

        return \sprintf($scheme,
            $getLabel,
            $this->renderInput(),
            $this->model->getFirstError($this->attribute)
        );
    }

    /**
     * Render the input field.
     *
     * @return string
     */
    abstract public function renderInput();
}
