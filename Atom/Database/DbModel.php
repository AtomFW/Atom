<?php

declare(strict_types=1);

namespace Atom\DataBase;

use Atom\Atom;
use Atom\Model;
use Atom\Types\Point;
use stdClass;
use Atom\Structure\Cast;
use Carbon\Carbon;

/**
 * Base Active Record-like model for DB-backed entities.
 *
 * Subclasses must provide tableName() and tableKeys().
 */
abstract class DbModel extends Model
{
    /**
     * Name of the database table for the model.
     */
    abstract public static function tableName(): string;

    /**
     * Returns an array of column names mapped to their respective
     * database data types.
     *
     * For example, if a column is a datetime, the value should be
     * 'datetime'. If the column is a boolean, the value should be
     * 'boolean'.
     *
     * @return array
     */
    abstract public static function attributesTypes(): array;

    /**
     * Returns the primary key column name.
     */
    public static function primaryKey(): string
    {
        return 'id';
    }

    /**
     * Insert the current model instance into the database using its attributes.
     *
     * Binds attributes as named parameters and executes the INSERT statement.
     *
     * @return bool True on successful execution.
     */
    public function save(): bool
    {
        $tableName = $this->tableName();
        $attributes = $this->attributes();
        $attributesTypes = static::attributesTypes();
        $attributesTypes = Atom::$app->db::prioritizeTheMoppingArrayOfTypes($attributesTypes, true);
        $attributesKeys = Atom::$app->db->resolveKeys($attributes);
        $attributesKeys = Atom::$app->db::filterIgnoredTypes($attributesKeys, $attributesTypes);
        $propertyToColumnName = Atom::$app->db->propertyToColumnName($attributesKeys);
        $attributesToBindsProperty = Atom::$app->db->attributesToBindsProperty($propertyToColumnName);
        $attributesToBindsProperty = Atom::$app->db->mapColumnFromTypes($attributesToBindsProperty, $attributesTypes);
        $valueToArray = self::bindValuesToKeys($this, $attributesKeys, $attributesTypes);
        $bindValuesToProperty = Atom::$app->db->bindValuesToProperty($propertyToColumnName, $valueToArray);

        $insert = Atom::$app->db->insertInto($tableName)->values($attributesToBindsProperty)->setParameters($bindValuesToProperty);

        $statement = $insert->executeQuery();

        return true;
    }

    /**
     * Prepare a SQL statement via the application's PDO connection.
     *
     * @param string $sql Raw SQL with named placeholders.
     * @return \PDOStatement
     */
    public static function prepare($sql): \PDOStatement
    {
        return Atom::$app->db->pdo->prepare($sql);
    }

    /**
     * Fetch a single record by conditions and map it to the current subclass.
     *
     * @param array $where Associative array of column => value filters.
     * @return static|false Instance of the model or null if not found.
     */
    public static function findOne(array $where): static|false
    {

        $tableName = static::tableName();
        $attributes = array_keys($where);
        $select = static::attributes();

        $attributesKeys = Atom::$app->db->resolveKeys($attributes);

        if ($select) {
            $attributesTypes = static::attributesTypes();
            $attributesTypes = Atom::$app->db::prioritizeTheMoppingArrayOfTypes($attributesTypes);
            $attributesTypes = Atom::$app->db::filterIgnoredTypes($attributesKeys, $attributesTypes);
            $selectAttributes = Atom::$app->db->resolveKeys($select);
            $selectAttributesToColumnName = Atom::$app->db->propertyToColumnName($selectAttributes);
            $selectAttributes = Atom::$app->db->mapColumnFromTypes($selectAttributesToColumnName, $attributesTypes);
        }

        $propertyToColumnName = Atom::$app->db->propertyToColumnName($attributesKeys);
        
        $bindValuesToProperty = Atom::$app->db->bindValuesToProperty($propertyToColumnName, $where);

        if (isset($selectAttributes)) {
            $select = Atom::$app->db->selectFrom($tableName, $selectAttributes);
        } else {
            $select = Atom::$app->db->selectFrom($tableName);
        }

        $select = Atom::$app->db->attributesToAutoBindsComparisonsProperty($select, $propertyToColumnName);

        $select->setParameters($bindValuesToProperty);

        $result = $select->executeQuery();

        $data = $result->fetchAssociative();

        if ($data) {
            if (isset($selectAttributesToColumnName)) {
                $temp = [];
                foreach ($selectAttributesToColumnName as $key => $value) {
                    $temp[$key] = $data[$value];
                }
                $data = $temp;
            }

            $data = Atom::$app->db->columnNameToProperty($data);

            $data = self::bindDataToStaticClass($data, $attributesTypes ?? null);
        }

        return $data;
    }

    /**
     * Binds values from class properties to their corresponding keys and applies type casting
     * 
     * This private static method takes a model instance and maps its properties to a key-value array,
     * applying type conversions based on specified attribute types and property-level cast attributes.
     * It's designed to prepare data for database operations, API responses, or data transfer scenarios.
     * 
     * The method performs several operations:
     * 1. Processes attributesTypes array to map values with proper type conversion
     * 2. Uses Reflection to inspect and validate properties before processing
     * 3. Respects #[Cast] attributes for specialized value conversions
     * 
     * @param object $modelClass The model instance containing properties to be processed
     * @param array $classProperty Associative array of property names and their values
     * @param array $attributesTypes Array mapping property names to their expected types for conversion
     * @return array Returns an associative array with property names as keys and converted values
     */
    private static function bindValuesToKeys(object $modelClass, array $classProperty, array $attributesTypes)
    {
        $temp = [];

        if ($attributesTypes && \count($attributesTypes) > 0) {
            foreach ($attributesTypes as $key => $value) {
                if (isset($modelClass->{$key})) {
                    $modelClass->{$key} = AutoMapped::mapValueFromPHP($modelClass->{$key}, $value);
                }

            }
        }

        $reflection = new \ReflectionClass(static::class);

        foreach ($classProperty as $key => $value) {
            if ($reflection->hasProperty($key)) {
                $prop = $reflection->getProperty($key);
                // Check if exist attribute #[Cast]
                $attributes = $prop->getAttributes();
                foreach ($attributes as $attr) {
                    // We check by name (string), not by class
                    if (str_ends_with($attr->getName(), 'Cast')) {
                        // Returns an array of arguments given in #[Cast('this_is_argument')]
                        $arguments = $attr->getArguments();
                        $castType = \count($arguments) > 1 ? $arguments[1] : $arguments[0] ?? null;

                        if ($castType && $modelClass->{$key}) {
                            $modelClass->{$key} = AutoMapped::mapValueFromPHP($modelClass->{$key}, $castType);
                        }
                    }
                }
            }
        }

        foreach ($classProperty as $key => $value) {
            if (isset($modelClass->{$key})) {
                $temp[$key] = $modelClass->{$key};
            }
        }

        return $temp;
    }

    /**
     * Binds data to a static class instance by mapping array data to object properties
     * 
     * This private static method takes an associative array of data and maps it to the properties
     * of the current class. It provides flexible data binding with optional type casting and
     * automatic restriction of which properties can be set. The method offers two modes:
     * 1. Auto-restrict mode (default) - Only allows properties that exist in the class
     * 2. Non-auto-restrict mode - Allows setting any property, even if it doesn't exist
     * 
     * When autoRestrict is true and data is provided:
     * - For each data key-value pair, it attempts to map values based on type information
     * - Uses Reflection to inspect class properties and their types
     * - Applies automatic type conversion through the AutoMapped::mapValueFromDB() method
     * - Respects property-level #[Cast] attributes for specialized casting
     * 
     * @param array $data Associative array of data to bind to object properties
     * @param array|null $attributesTypes Optional array of attribute type mappings for type checking
     * @param bool $autoRestrict If true, only allows setting properties that exist in the class
     * @return static Returns a new instance of the calling class with bound data
     */
    private static function bindDataToStaticClass(array $data, ?array $attributesTypes, bool $autoRestrict = true): static
    {
        if (!$autoRestrict) {
            $static = new static(); 
            foreach ($data as $key => $value) {
                $static->$key = $value;
            }
            return $static;
        }

        $reflection = new \ReflectionClass(static::class);
        $static = new static();

        if ($data && \is_array($data) && \is_array($attributesTypes) && \count($attributesTypes) > 0) {
            $static = new static(); 
            foreach ($data as $key => $value) {
                if (isset($attributesTypes[$key])) {
                    $newValue = AutoMapped::mapValueFromDB($data[$key], $attributesTypes[$key]);
                    $static->$key = $newValue;
                    continue;
                }

                if ($reflection->hasProperty($key)) {
                    $property = $reflection->getProperty($key);
                    $type = $property->getType();

                    $typeName = ($type instanceof \ReflectionNamedType) ? $type->getName() : null;

                    $newValue = AutoMapped::mapValueFromDB($data[$key], $typeName);
                    $static->$key = $newValue;
                    
                    continue;
                }

                $static->$key = $value;
            }

            return $static;
        }

        foreach ($data as $key => $value) {
            if ($reflection->hasProperty($key)) {
                $property = $reflection->getProperty($key);
                // Check if Attribute #[Cast] is present
                $attributes = $property->getAttributes();
                foreach ($attributes as $attr) {
                    // We check by name (string), not by class
                    if (str_ends_with($attr->getName(), 'Cast')) {
                        // Returns an array of arguments given in #[Cast('this_is_argument')]
                        $arguments = $attr->getArguments();
                        $castType = $arguments[0] ?? null;
                        if ($castType) {
                            $value = AutoMapped::mapValueFromDB($value, $castType);
                            $property->setValue($static, $value);
                            continue;
                        }
                    }
                }

                $type = $property->getType();
                $typeName = ($type instanceof \ReflectionNamedType) ? $type->getName() : null;

                $value = AutoMapped::mapValueFromDB($data[$key], $typeName);

                $property->setValue($static, $value);
                continue;
            }

            $static->$key = $value;
        }

        return $static;
    }
}
