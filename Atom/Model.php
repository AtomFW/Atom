<?php

declare(strict_types=1);

namespace Atom;

use Atom\Filter\InputFilter;
use Atom\Filter\TypeCaster;

use function filter_var;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function preg_match;
use function property_exists;
use function str_replace;
use function strtolower;

/**
 * Base validation, form-binding, and automatic type-filtering model.
 */
class Model
{
    public const string RULE_REQUIRED = 'required';
    public const string RULE_EMAIL = 'email';
    public const string RULE_MIN = 'min';
    public const string RULE_MAX = 'max';
    public const string RULE_MATCH = 'match';
    public const string RULE_UNIQUE = 'unique';
    public const string RULE_INT = 'int';
    public const string RULE_FLOAT = 'float';
    public const string RULE_BOOL = 'bool';
    public const string RULE_URL = 'url';
    public const string RULE_REGEX = 'regex';
    public const string RULE_CALLBACK = 'callback';
    public const string RULE_IN = 'in';
    public const string RULE_IP = 'ip';
    public const string RULE_UUID = 'uuid';
    public const string RULE_ULID = 'ulid';
    public const string RULE_JSON = 'json';
    public const string RULE_MAC = 'mac';
    public const string RULE_HEX_COLOR = 'hex_color';
    public const string RULE_SLUG = 'slug';

    /** @var array<string, list<string>> */
    public array $errors = [];

    /** @var array<string, mixed> Isolated dynamic attributes for form/mock binding without #[AllowDynamicProperties] */
    private array $dynamicAttributes = [];

    public function __set(string $name, mixed $value): void
    {
        $this->dynamicAttributes[$name] = $value;
    }

    public function __get(string $name): mixed
    {
        return $this->dynamicAttributes[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->dynamicAttributes[$name]);
    }

    /**
     * Loads and automatically type-filters raw form/request/JSON data into typed model properties.
     *
     * @param array<string, mixed> $data
     */
    public function loadData(array $data): static
    {
        $explicitTypes = \method_exists(static::class, 'attributesTypes')
            ? static::attributesTypes()
            : [];

        TypeCaster::populateFromForm($this, $data, $explicitTypes);

        return $this;
    }

    /**
     * @return array<int|string, string>
     */
    public static function attributes(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return [];
    }

    public function getLabel(string $attribute): string
    {
        return $this->labels()[$attribute] ?? $attribute;
    }

    /**
     * @return array<string, list<string|array<int|string, mixed>>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Validates model properties against declared rules, resetting any stale errors first.
     */
    public function validate(): bool
    {
        $this->errors = [];

        foreach ($this->rules() as $attribute => $rules) {
            $value = property_exists($this, $attribute)
                ? ($this->{$attribute} ?? null)
                : ($this->dynamicAttributes[$attribute] ?? null);

            foreach ($rules as $rule) {
                if (\is_callable($rule) && !is_string($rule) && !is_array($rule)) {
                    $this->runCustomValidator($attribute, $value, $rule);
                    continue;
                }

                $ruleName = is_string($rule) ? $rule : (string) ($rule[0] ?? '');

                if ($ruleName === self::RULE_CALLBACK && is_array($rule) && isset($rule['callback']) && \is_callable($rule['callback'])) {
                    $customMsg = isset($rule['message']) ? (string) $rule['message'] : null;
                    $this->runCustomValidator($attribute, $value, $rule['callback'], $customMsg);
                    continue;
                }

                if ($ruleName === self::RULE_REQUIRED && ($value === null || $value === '' || $value === [])) {
                    $this->addErrorByRule($attribute, self::RULE_REQUIRED);
                    continue;
                }

                // Skip secondary format checks when empty and not required
                if ($value === null || $value === '') {
                    continue;
                }

                $stringVal = is_string($value)
                    ? $value
                    : (\is_scalar($value) || $value instanceof \Stringable ? (string) $value : '');

                if ($ruleName === self::RULE_EMAIL && filter_var($stringVal, \FILTER_VALIDATE_EMAIL) === false) {
                    $this->addErrorByRule($attribute, self::RULE_EMAIL);
                }

                if ($ruleName === self::RULE_URL && filter_var($stringVal, \FILTER_VALIDATE_URL) === false) {
                    $this->addErrorByRule($attribute, self::RULE_URL);
                }

                if ($ruleName === self::RULE_IP && filter_var($stringVal, \FILTER_VALIDATE_IP) === false) {
                    $this->addErrorByRule($attribute, self::RULE_IP);
                }

                if ($ruleName === self::RULE_MAC && filter_var($stringVal, \FILTER_VALIDATE_MAC) === false) {
                    $this->addErrorByRule($attribute, self::RULE_MAC);
                }

                if ($ruleName === self::RULE_UUID && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $stringVal) !== 1) {
                    $this->addErrorByRule($attribute, self::RULE_UUID);
                }

                if ($ruleName === self::RULE_ULID && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i', $stringVal) !== 1) {
                    $this->addErrorByRule($attribute, self::RULE_ULID);
                }

                if ($ruleName === self::RULE_JSON && (!is_string($value) || !\json_validate($value))) {
                    $this->addErrorByRule($attribute, self::RULE_JSON);
                }

                if ($ruleName === self::RULE_HEX_COLOR && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $stringVal) !== 1) {
                    $this->addErrorByRule($attribute, self::RULE_HEX_COLOR);
                }

                if ($ruleName === self::RULE_SLUG && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $stringVal) !== 1) {
                    $this->addErrorByRule($attribute, self::RULE_SLUG);
                }

                if ($ruleName === self::RULE_IN && is_array($rule) && isset($rule['values']) && is_array($rule['values']) && !in_array($value, $rule['values'], true)) {
                    $this->addErrorByRule($attribute, self::RULE_IN);
                }

                if ($ruleName === self::RULE_INT && filter_var($value, \FILTER_VALIDATE_INT) === false) {
                    $this->addErrorByRule($attribute, self::RULE_INT);
                }

                if ($ruleName === self::RULE_FLOAT && !is_numeric($value)) {
                    $this->addErrorByRule($attribute, self::RULE_FLOAT);
                }

                if ($ruleName === self::RULE_BOOL && !\is_bool($value) && filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) === null) {
                    $this->addErrorByRule($attribute, self::RULE_BOOL);
                }

                if ($ruleName === self::RULE_MIN && is_array($rule) && mb_strlen($stringVal) < (int) $rule['min']) {
                    $this->addErrorByRule($attribute, self::RULE_MIN, ['min' => (string) $rule['min']]);
                }

                if ($ruleName === self::RULE_MAX && is_array($rule) && mb_strlen($stringVal) > (int) $rule['max']) {
                    $this->addErrorByRule($attribute, self::RULE_MAX, ['max' => (string) $rule['max']]);
                }

                if ($ruleName === self::RULE_REGEX && is_array($rule) && isset($rule['pattern']) && preg_match((string) $rule['pattern'], $stringVal) !== 1) {
                    $this->addErrorByRule($attribute, self::RULE_REGEX);
                }

                if ($ruleName === self::RULE_MATCH && is_array($rule)) {
                    $matchProp = (string) $rule['match'];
                    if ($value !== ($this->{$matchProp} ?? null)) {
                        $this->addErrorByRule($attribute, self::RULE_MATCH, ['match' => $this->getLabel($matchProp)]);
                    }
                }

                if ($ruleName === self::RULE_UNIQUE && is_array($rule)) {
                    $className = $rule['class'];
                    $uniqueAttr = (string) ($rule['attribute'] ?? $attribute);
                    $safeCol = \preg_replace('/[^a-zA-Z0-9_]/', '', $uniqueAttr) ?? '';
                    if ($safeCol === '') {
                        continue;
                    }

                    $tableName = $className::tableName();
                    $primaryKey = $className::primaryKey();
                    $db = Atom::$app->db;

                    $result = $db->selectFrom($tableName, $primaryKey)
                        ->where("t.{$safeCol} = :unique_val")
                        ->setParameters(['unique_val' => $value])
                        ->setMaxResults(1)
                        ->executeQuery();

                    try {
                        $exists = $result->fetchOne() !== false;
                    } finally {
                        $result->free();
                    }

                    if ($exists) {
                        $this->addErrorByRule($attribute, self::RULE_UNIQUE, ['field' => $this->getLabel($attribute)]);
                    }
                }
            }
        }

        return $this->errors === [];
    }

    /**
     * @return array<string, string>
     */
    public function errorMessages(): array
    {
        return [
            self::RULE_REQUIRED => 'This field is required',
            self::RULE_EMAIL => 'This field must be valid email address',
            self::RULE_MIN => 'Min length of this field must be {min}',
            self::RULE_MAX => 'Max length of this field must be {max}',
            self::RULE_MATCH => 'This field must be the same as {match}',
            self::RULE_UNIQUE => 'Record with this {field} already exists',
            self::RULE_INT => 'This field must be an integer',
            self::RULE_FLOAT => 'This field must be a number',
            self::RULE_BOOL => 'This field must be a boolean value',
            self::RULE_URL => 'This field must be a valid URL',
            self::RULE_REGEX => 'This field has an invalid format',
            self::RULE_CALLBACK => 'This field failed custom validation',
            self::RULE_IN => 'Selected value is not allowed',
            self::RULE_IP => 'This field must be a valid IP address',
            self::RULE_UUID => 'This field must be a valid UUID',
            self::RULE_ULID => 'This field must be a valid ULID',
            self::RULE_JSON => 'This field must be valid JSON',
            self::RULE_MAC => 'This field must be a valid MAC address',
            self::RULE_HEX_COLOR => 'This field must be a valid hex color',
            self::RULE_SLUG => 'This field must be a valid lowercase URL slug',
        ];
    }

    /**
     * Validates a single attribute on-demand with a custom callable (`fn(mixed $value, static $model): bool|string`).
     */
    public function validateWith(string $attribute, callable $validator, ?string $message = null): bool
    {
        $value = property_exists($this, $attribute)
            ? ($this->{$attribute} ?? null)
            : ($this->dynamicAttributes[$attribute] ?? null);

        return $this->runCustomValidator($attribute, $value, $validator, $message);
    }

    private function runCustomValidator(string $attribute, mixed $value, callable $validator, ?string $fallbackMessage = null): bool
    {
        $outcome = $validator($value, $this);

        if ($outcome === true || $outcome === null) {
            return true;
        }

        if (is_string($outcome) && $outcome !== '') {
            $this->addError($attribute, $outcome);
            return false;
        }

        if ($fallbackMessage !== null && $fallbackMessage !== '') {
            $this->addError($attribute, $fallbackMessage);
            return false;
        }

        $this->addErrorByRule($attribute, self::RULE_CALLBACK);
        return false;
    }

    public function errorMessage(string $rule): string
    {
        return $this->errorMessages()[$rule] ?? 'Invalid value';
    }

    /**
     * @param array<string, string|int|float> $params
     */
    protected function addErrorByRule(string $attribute, string $rule, array $params = []): void
    {
        $params['field'] ??= $attribute;
        $errorMessage = $this->errorMessage($rule);

        foreach ($params as $key => $value) {
            $errorMessage = str_replace("{{$key}}", (string) $value, $errorMessage);
        }

        $this->errors[$attribute][] = $errorMessage;
    }

    public function addError(string $attribute, string $message): void
    {
        $this->errors[$attribute][] = $message;
    }

    /**
     * @return list<string>|bool
     */
    public function hasError(string $attribute)
    {
        return $this->errors[$attribute] ?? false;
    }

    public function getFirstError(string $attribute): string
    {
        return $this->errors[$attribute][0] ?? '';
    }

    public function property(string $attribute): string
    {
        $formConfig = $this->form();
        if (!isset($formConfig[$attribute]) || !is_array($formConfig[$attribute])) {
            return '';
        }

        return self::buildHtmlAttributes($formConfig[$attribute], ['value', 'class', 'options']);
    }

    public function getProperty(string $attribute, string $property): string|int|float|false
    {
        $formConfig = $this->form();
        if (isset($formConfig[$attribute][$property]) && !is_array($formConfig[$attribute][$property])) {
            return $formConfig[$attribute][$property];
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function form(): array
    {
        return [];
    }

    public function getInputOuterType(): string|false
    {
        $form = $this->form();
        return isset($form['base']['outer']['type']) ? (string) $form['base']['outer']['type'] : 'div';
    }

    public function getInputOuterAttributes(): string|false
    {
        $form = $this->form();
        if (!isset($form['base']['outer']['attr']) || !is_array($form['base']['outer']['attr'])) {
            return false;
        }

        return self::buildHtmlAttributes($form['base']['outer']['attr'], ['class']);
    }

    public function getInputOuterAttribute(string $property): string|false
    {
        $form = $this->form();
        return isset($form['base']['outer']['attr'][$property])
            ? (string) $form['base']['outer']['attr'][$property]
            : false;
    }

    public function getInputInnerType(): string|false
    {
        $form = $this->form();
        return isset($form['base']['inner']['type']) ? (string) $form['base']['inner']['type'] : 'div';
    }

    public function getInputInnerAttributes(): string|false
    {
        $form = $this->form();
        if (!isset($form['base']['inner']['attr']) || !is_array($form['base']['inner']['attr'])) {
            return false;
        }

        return self::buildHtmlAttributes($form['base']['inner']['attr'], ['class']);
    }

    public function getInputInnerAttribute(string $property): string|false
    {
        $form = $this->form();
        return isset($form['base']['inner']['attr'][$property])
            ? (string) $form['base']['inner']['attr'][$property]
            : false;
    }

    public function getInputTargetType(): string|false
    {
        $form = $this->form();
        return isset($form['base']['target']['type']) ? (string) $form['base']['target']['type'] : 'div';
    }

    public function getInputTargetAttributes(): string|false
    {
        $form = $this->form();
        if (!isset($form['base']['target']['attr']) || !is_array($form['base']['target']['attr'])) {
            return false;
        }

        return self::buildHtmlAttributes($form['base']['target']['attr'], ['class']);
    }

    public function getInputTargetAttribute(string $property): string|false
    {
        $form = $this->form();
        return isset($form['base']['target']['attr'][$property])
            ? (string) $form['base']['target']['attr'][$property]
            : false;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getOptionSelects(string $attribute): array|false
    {
        $form = $this->form();
        if (!isset($form[$attribute]['options']) || !is_array($form[$attribute]['options'])) {
            return false;
        }

        return $form[$attribute]['options'];
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $excludedKeys
     */
    private static function buildHtmlAttributes(array $attributes, array $excludedKeys): string
    {
        $attr = [];
        foreach ($attributes as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $excludedKeys, true)) {
                continue;
            }

            $safeKey = \preg_replace('/[^a-z0-9\-_:]/', '', $normalizedKey) ?? '';
            if ($safeKey === '') {
                continue;
            }

            $safeVal = InputFilter::escapeHtml($value);
            $attr[] = "{$safeKey}=\"{$safeVal}\"";
        }

        return implode(' ', $attr);
    }
}
