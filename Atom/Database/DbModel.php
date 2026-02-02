<?php

namespace Atom\DataBase;

use Atom\Atom;
use Atom\Model;

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
     * Schema definition or list of columns for the table.
     *
     * @return array
     */
    abstract public static function tableKeys(): array;

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
    public function save()
    {
        $tableName = $this->tableName();
        $attributes = $this->attributes();
        $params = array_map(fn($attr) => ":$attr", $attributes);
        $statement = self::prepare("INSERT INTO $tableName (" . implode(",", $attributes) . ") 
                VALUES (" . implode(",", $params) . ")");
        foreach ($attributes as $attribute) {
            $statement->bindValue(":$attribute", $this->{$attribute});
        }
        $statement->execute();
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
        return Atom::$app->db->prepare($sql);
    }

    /**
     * Fetch a single record by conditions and map it to the current subclass.
     *
     * @param array $where Associative array of column => value filters.
     * @param bool|null $autoLoadKeysOrAutoRenameKeysFromTable Controls field selection/renaming.
     * @return static|null Instance of the model or null if not found.
     */
    public static function findOne($where, bool|null $autoLoadKeysOrAutoRenameKeysFromTable = true)
    {
        $tableName = static::tableName();
        $attributes = array_keys($where);
        $sql = implode("AND", array_map(fn($attr) => "$attr = :$attr", $attributes));
        $tableKey = "*";
        if ($autoLoadKeysOrAutoRenameKeysFromTable !== null) {
            if ($autoLoadKeysOrAutoRenameKeysFromTable === true) {
                $tableKey = self::buildAutoTranslatedTableKeys(static::tableKeys(), true);
            } else {
                $tableKey = self::buildAutoTranslatedTableKeys(static::tableKeys(), false);
            }
        }

        $statement = self::prepare("SELECT $tableKey FROM $tableName WHERE $sql");

        foreach ($where as $key => $item) {
            $statement->bindValue(":$key", $item);
        }

        $statement->execute();

        return $statement->fetchObject(static::class);
    }

    public function getSafeSqlFields(array $tableKeys, bool $autoVerifyKeys = true): string
    {
        return self::buildAutoTranslatedTableKeys($tableKeys, $autoVerifyKeys);
    }



    protected static function getMappedDBKeys(array $tableKeys, bool $autoRestrict = true): array
    {
        $classProps = [];
        // if ($autoRestrict) $classProps = array_keys(get_class_vars(static::class)); //in_array
        if ($autoRestrict) {
            $classProps = array_flip(array_keys(get_class_vars(static::class)));
        }

        $mapped = [];

        foreach ($tableKeys as $key => $value) {
            $dbColumn = is_int($key) ? $value : $key;
            // if ($autoRestrict && !in_array($propName, $classProps)) continue;
            // if ($autoRestrict && !array_key_exists($propName, $classProps)) continue;
            if ($autoRestrict && !isset($classProps[$value])) {
                continue;
            }
            // 1. in_array() (Linear search) : 0.0045 ms string(24) "firstname,email,password"
            // 2. array_key_exists() (Linear search) : 0.0038 ms string(24) "firstname,email,password"
            // 3. isset() (Linear search) : 0.0035 ms string(24) "firstname,email,password"

            $mapped[$dbColumn] = $value;
        }

        return $mapped;
    }

    protected static function buildAutoTranslatedTableKeys(array $tableKeys, bool $autoRestrict = true): string
    {
        $fields = self::getMappedDBKeys($tableKeys, $autoRestrict);
        $queryParts = [];

        foreach ($fields as $dbCol => $propName) {
            if ($dbCol === $propName) {
                $queryParts[] = "`$dbCol`";
                continue;
            }

            $queryParts[] = "`$dbCol`AS`$propName`";
        }

        return implode(',', $queryParts);
    }
}
