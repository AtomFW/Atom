<?php

declare(strict_types=1);

namespace Atom;

class Model
{
    public const RULE_REQUIRED = 'required';
    public const RULE_EMAIL = 'email';
    public const RULE_MIN = 'min';
    public const RULE_MAX = 'max';
    public const RULE_MATCH = 'match';
    public const RULE_UNIQUE = 'unique';

    public array $errors = [];

    public function loadData($data)
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    public static function attributes()
    {
        return [];
    }

    public function labels()
    {
        return [];
    }

    public function getLabel($attribute)
    {
        return $this->labels()[$attribute] ?? $attribute;
    }

    public function rules()
    {
        return [];
    }

    public function validate()
    {
        foreach ($this->rules() as $attribute => $rules) {
            $value = $this->{$attribute};
            foreach ($rules as $rule) {
                $ruleName = $rule;
                if (!\is_string($rule)) {
                    $ruleName = $rule[0];
                }
                if ($ruleName === self::RULE_REQUIRED && !$value) {
                    $this->addErrorByRule($attribute, self::RULE_REQUIRED);
                }
                if ($ruleName === self::RULE_EMAIL && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addErrorByRule($attribute, self::RULE_EMAIL);
                }
                if ($ruleName === self::RULE_MIN && strlen($value) < $rule['min']) {
                    $this->addErrorByRule($attribute, self::RULE_MIN, ['min' => $rule['min']]);
                }
                if ($ruleName === self::RULE_MAX && strlen($value) > $rule['max']) {
                    $this->addErrorByRule($attribute, self::RULE_MAX);
                }
                if ($ruleName === self::RULE_MATCH && $value !== $this->{$rule['match']}) {
                    $this->addErrorByRule($attribute, self::RULE_MATCH, ['match' => $rule['match']]);
                }
                if ($ruleName === self::RULE_UNIQUE) {
                    $className = $rule['class'];
                    $uniqueAttr = $rule['attribute'] ?? $attribute;
                    $tableName = $className::tableName();
                    $primaryKey = $className::primaryKey();
                    $db = Atom::$app->db;
                    $select = $db->selectFrom($tableName, $primaryKey)->where("t.$uniqueAttr = :$uniqueAttr")->setParameters(["$uniqueAttr" => $value]);
                    // $a = $db->selectFrom($tableName)->where("t.$uniqueAttr = :$uniqueAttr")->setParameters(["$uniqueAttr" => $value]);
                    // $a = $db->selectFrom($tableName)->where("$uniqueAttr = $value");
                    // var_dump($a->getSQL());
                    $result = $select->executeQuery();
                    // var_dump($re->rowCount());
                    // var_dump($re->fetchAllAssociative());

                    if ($result->rowCount() > 0) {
                        $this->addErrorByRule($attribute, self::RULE_UNIQUE);
                    }

                    // var_dump($a->executeQuery()->fetchAllAssociative());
                    // var_dump($db->selectFrom($tableName)->where([$uniqueAttr => $value])->limit(1)->fetchObject());

// $queryBuilder
//     ->select('u.id', 'u.username', 'u.email')
//     ->from('users', 'u')
//     ->where('u.id = :id')
//     ->andWhere('u.status = :status')
//     ->setParameter('id', 1)
//     ->setParameter('status', 'active')
//     ->orderBy('u.username', 'ASC')
//     ->setMaxResults(10); // LIMIT

// // Wykonanie zapytania
// $result = $queryBuilder->executeQuery();

// // Pobranie danych jako tablicy asocjacyjnej
// $user = $result->fetchAssociative();

                    // die();
                    // $statement = $db->prepare("SELECT * FROM $tableName WHERE $uniqueAttr = :$uniqueAttr");
                    // $statement->bindValue(":$uniqueAttr", $value);
                    // $statement->execute();
                    // $record = $statement->fetchObject();
                    // if ($record) {
                    //     $this->addErrorByRule($attribute, self::RULE_UNIQUE);
                    // }
                }
            }
        }
        return empty($this->errors);
    }

    public function errorMessages()
    {
        return [
            self::RULE_REQUIRED => 'This field is required',
            self::RULE_EMAIL => 'This field must be valid email address',
            self::RULE_MIN => 'Min length of this field must be {min}',
            self::RULE_MAX => 'Max length of this field must be {max}',
            self::RULE_MATCH => 'This field must be the same as {match}',
            self::RULE_UNIQUE => 'Record with with this {field} already exists',
        ];
    }

    public function errorMessage($rule)
    {
        return $this->errorMessages()[$rule];
    }

    protected function addErrorByRule(string $attribute, string $rule, $params = [])
    {
        $params['field'] ??= $attribute;
        $errorMessage = $this->errorMessage($rule);
        foreach ($params as $key => $value) {
            $errorMessage = str_replace("{{$key}}", $value, $errorMessage);
        }
        $this->errors[$attribute][] = $errorMessage;
    }

    public function addError(string $attribute, string $message)
    {
        $this->errors[$attribute][] = $message;
    }

    public function hasError($attribute)
    {
        return $this->errors[$attribute] ?? false;
    }

    public function getFirstError($attribute)
    {
        $errors = $this->errors[$attribute] ?? [];
        return $errors[0] ?? '';
    }

    public function property(string $attribute): string
    {
        if (!isset(($this->form()[$attribute]))) {
            return '';
        }

        $attr = [];
        foreach ($this->form()[$attribute] as $key => $value) {
            $key = strtolower($key);
            if (\in_array($key, ['value', 'class', 'options'])) {
                continue;
            }
            $attr[] = "{$key}=\"{$value}\"";
        }

        return implode(' ', $attr);
    }

    public function getProperty(string $attribute, string $property): string|false
    {
        if (isset(($this->form()[$attribute][$property]))) {
            return $this->form()[$attribute][$property];
        }

        return false;
    }
    
    public function form(): array
    {
        return [];
    }

    public function getInputOuterType(): string|false
    {
        if (!isset(($this->form()["base"]["outer"]["type"]))) {
            return "div";
        }

        return $this->form()["base"]["outer"]["type"];
    }

    public function getInputOuterAttributes(): string|false
    {
        if (!isset($this->form()["base"]["outer"]["attr"])) {
            return false;
        }

        $attr = [];
        foreach ($this->form()["base"]["outer"]["attr"] as $key => $value) {
            $key = strtolower($key);
            if (\in_array($key, ['class'])) {
                continue;
            }
            $attr[] = "{$key}=\"{$value}\"";
        }

        return implode(' ', $attr);
    }

    public function getInputOuterAttribute(string $property): string|false
    {
        if (isset(($this->form()["base"]["outer"]["attr"][$property]))) {
            return $this->form()["base"]["outer"]["attr"][$property];
        }

        return false;
    }

    public function getInputInnerType(): string|false
    {
        if (!isset(($this->form()["base"]["inner"]["type"]))) {
            return "div";
        }

        return $this->form()["base"]["inner"]["type"];
    }

    public function getInputInnerAttributes(): string|false
    {
        if (!isset($this->form()["base"]["inner"]["attr"])) {
            return false;
        }

        $attr = [];
        foreach ($this->form()["base"]["inner"]["attr"] as $key => $value) {
            $key = strtolower($key);
            if (\in_array($key, ['class'])) {
                continue;
            }
            $attr[] = "{$key}=\"{$value}\"";
        }

        return implode(' ', $attr);
    }

    public function getInputInnerAttribute(string $property): string|false
    {
        if (isset(($this->form()["base"]["inner"]["attr"][$property]))) {
            return $this->form()["base"]["inner"]["attr"][$property];
        }

        return false;
    }

    public function getInputTargetType(): string|false
    {
        if (!isset(($this->form()["base"]["target"]["type"]))) {
            return "div";
        }

        return $this->form()["base"]["target"]["type"];
    }

    public function getInputTargetAttributes(): string|false
    {
        if (!isset($this->form()["base"]["target"]["attr"])) {
            return false;
        }

        $attr = [];
        foreach ($this->form()["base"]["target"]["attr"] as $key => $value) {
            $key = strtolower($key);
            if (\in_array($key, ['class'])) {
                continue;
            }
            $attr[] = "{$key}=\"{$value}\"";
        }

        return implode(' ', $attr);
    }

    public function getInputTargetAttribute(string $property): string|false
    {
        if (isset(($this->form()["base"]["target"]["attr"][$property]))) {
            return $this->form()["base"]["target"]["attr"][$property];
        }

        return false;
    }

    public function getOptionSelects(string $attribute): array|false
    {
        if (!isset(($this->form()[$attribute]["options"]))) {
            return false;
        }

        return $this->form()[$attribute]["options"];
    }
}
