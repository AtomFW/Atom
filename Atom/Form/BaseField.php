<?php

declare(strict_types=1);

namespace Atom\Form;

use Atom\Filter\InputFilter;
use Atom\Model;
use BackedEnum;
use ReflectionProperty;
use Stringable;
use Time\Duration;

/**
 * Abstract base class for form fields with XSS-safe rendering and safe uninitialized-property resolution.
 */
abstract class BaseField implements Stringable
{
    public Model $model;
    public string $attribute;
    public string $type = 'text';

    public function __construct(Model $model, string $attribute)
    {
        $this->model = $model;
        $this->attribute = $attribute;
    }

    public function __toString(): string
    {
        $outerType = $this->sanitizeTagName((string) ($this->model->getInputOuterType() ?: 'div'), 'div');
        $outerAttrs = (string) ($this->model->getInputOuterAttributes() ?: '');
        $outerAttrClass = (string) ($this->model->getInputOuterAttribute('class') ?: 'form-group');

        $innerType = $this->sanitizeTagName((string) ($this->model->getInputInnerType() ?: 'label'), 'label');
        $innerAttrs = (string) ($this->model->getInputInnerAttributes() ?: '');
        $innerAttrClassRaw = (string) ($this->model->getInputInnerAttribute('class') ?: '');
        $innerAttrClass = $innerAttrClassRaw !== ''
            ? 'class="' . InputFilter::escapeHtml($innerAttrClassRaw) . '"'
            : '';

        $targetType = $this->sanitizeTagName((string) ($this->model->getInputTargetType() ?: 'div'), 'div');
        $targetAttrs = (string) ($this->model->getInputTargetAttributes() ?: '');
        $targetAttrClass = (string) ($this->model->getInputTargetAttribute('class') ?: 'invalid-feedback');

        $rawLabel = $this->model->getLabel($this->attribute);
        $safeAttr = InputFilter::escapeHtml($this->attribute);
        $labelTemplate = $rawLabel !== ''
            ? "<{$innerType} {$innerAttrClass} for=\"{$safeAttr}\" {$innerAttrs}>%s</{$innerType}>"
            : '';

        $scheme = "<{$outerType} class=\"" . InputFilter::escapeHtml($outerAttrClass) . "\" {$outerAttrs}>
            {$labelTemplate}
            %s
            <{$targetType} class=\"" . InputFilter::escapeHtml($targetAttrClass) . "\" {$targetAttrs}>
                %s
            </{$targetType}>
        </{$outerType}>";

        return \sprintf(
            $scheme,
            InputFilter::escapeHtml($rawLabel),
            (string) $this->renderInput(),
            InputFilter::escapeHtml($this->model->getFirstError($this->attribute)),
        );
    }

    /**
     * Resolves CSS validation/custom class suffix safely.
     */
    protected function resolveInputClass(): string
    {
        $classAttribute = $this->model->getProperty($this->attribute, 'class');
        $inputClass = $this->model->hasError($this->attribute) ? ' is-invalid' : '';

        if ($classAttribute !== false && $classAttribute !== '') {
            $inputClass .= ' ' . InputFilter::escapeHtml($classAttribute);
        }

        return $inputClass;
    }

    /**
     * Safely reads and HTML-escapes the current attribute value (even if typed property is uninitialized).
     */
    protected function resolveInputValue(): string
    {
        $rawValue = null;

        if (\property_exists($this->model, $this->attribute)) {
            $ref = new ReflectionProperty($this->model, $this->attribute);
            if ($ref->isPublic() && $ref->isInitialized($this->model)) {
                $rawValue = $this->model->{$this->attribute};
            }
        } elseif (isset($this->model->{$this->attribute})) {
            $rawValue = $this->model->{$this->attribute};
        }

        if ($rawValue instanceof BackedEnum) {
            $rawValue = $rawValue->value;
        } elseif ($rawValue instanceof Duration) {
            $rawValue = $rawValue->negative ? -$rawValue->seconds : $rawValue->seconds;
        } elseif (\is_bool($rawValue)) {
            $rawValue = $rawValue ? '1' : '';
        }

        $fallbackValue = $this->model->getProperty($this->attribute, 'value');
        if (($rawValue === null || $rawValue === '') && $fallbackValue !== false && $fallbackValue !== '') {
            $rawValue = $fallbackValue;
        }

        return InputFilter::escapeHtml($rawValue);
    }

    private function sanitizeTagName(string $tag, string $fallback): string
    {
        $clean = \preg_replace('/[^a-zA-Z0-9\-]/', '', $tag) ?? '';
        return $clean !== '' ? $clean : $fallback;
    }

    /**
     * Render the HTML input element.
     *
     * @return string
     */
    abstract public function renderInput();
}
