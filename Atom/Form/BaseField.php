<?php

declare(strict_types=1);

namespace Atom\form;

use Atom\Model;

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

    public function __toString(): string
    {
        $outerType = $this->model->getInputOuterType();
        $outerAttrs = $this->model->getInputOuterAttributes();
        $outerAttrClass = $this->model->getInputOuterAttribute('class');
        if(!$outerAttrClass) {
            $outerAttrClass = 'form-group';
        }

        $innerType = $this->model->getInputInnerType();
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

    abstract public function renderInput();
}
