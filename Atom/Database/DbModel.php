<?php

declare(strict_types=1);

namespace Atom\DataBase;

use Atom\Atom;
use Atom\Filter\TypeCaster;
use Atom\Model;
use PDOStatement;
use SortDirection;

use function array_keys;
use function clamp;

/**
 * Base Active Record model for database-backed entities with automatic,
 * reflection-cached type casting via `Atom\Filter\TypeCaster`.
 */
abstract class DbModel extends Model
{
    /**
     * Name of the database table for the model.
     */
    abstract public static function tableName(): string;

    /**
     * Optional explicit column/property type mapping overrides.
     * Properties with native PHP 8.6 types or `#[Cast]` attributes are handled automatically
     * even when omitted from `attributesTypes()`.
     *
     * @return array<string, string|array<int, string>>
     */
    public static function attributesTypes(): array
    {
        return [];
    }

    /**
     * Primary key property/column name.
     */
    public static function primaryKey(): string
    {
        return 'id';
    }

    /**
     * Inserts the current model instance into the database using non-destructive type extraction.
     */
    public function save(): bool
    {
        $db = Atom::$app->db;
        $tableName = static::tableName();
        $attributes = static::attributes();
        $rawTypes = static::attributesTypes();

        $writeTypes = $db::prioritizeTheMoppingArrayOfTypes($rawTypes, true);
        $attributesKeys = $db->resolveKeys($attributes);
        $filteredKeys = $db::filterIgnoredTypes($attributesKeys, $writeTypes);

        // Preserve column mapping from attributes() rather than overwriting with type names
        $activePropertyMap = [];
        foreach ($attributesKeys as $prop => $col) {
            if (isset($filteredKeys[$prop])) {
                $activePropertyMap[$prop] = $col;
            }
        }

        $propertyToColumnName = $db->propertyToColumnName($activePropertyMap);
        $bindsMap = $db->attributesToBindsProperty($propertyToColumnName);
        $bindsMap = $db->mapColumnFromTypes($bindsMap, $writeTypes);

        $extractedValues = TypeCaster::extractForDatabase($this, $activePropertyMap, $rawTypes);
        $bindValues = $db->bindValuesToProperty($propertyToColumnName, $extractedValues);

        $result = $db->insertInto($tableName)
            ->values($bindsMap)
            ->setParameters($bindValues)
            ->executeQuery();

        $result->free();

        return true;
    }

    /**
     * Prepares a parameterized SQL statement on the active PDO connection.
     */
    public static function prepare(string $sql): PDOStatement
    {
        return Atom::$app->db->pdo->prepare($sql);
    }

    /**
     * Fetches a single record matching `$where` and hydrates it into `static`.
     *
     * @param array<string, mixed> $where
     */
    public static function findOne(array $where): static|false
    {
        $db = Atom::$app->db;
        $tableName = static::tableName();
        $whereKeys = $db->resolveKeys(array_keys($where));
        $selectList = static::attributes();
        $rawTypes = static::attributesTypes();
        $readTypes = null;
        $selectColumnsMap = null;

        if ($selectList !== []) {
            $readTypes = $db::prioritizeTheMoppingArrayOfTypes($rawTypes, false);
            $selectKeys = $db->resolveKeys($selectList);
            $selectColumnsMap = $db->propertyToColumnName($selectKeys);
            $selectExpressions = $db->mapColumnFromTypes($selectColumnsMap, $readTypes);
            $qb = $db->selectFrom($tableName, $selectExpressions);
        } else {
            $qb = $db->selectFrom($tableName);
        }

        $whereColumnMap = $db->propertyToColumnName($whereKeys);
        $whereParams = $db->bindValuesToProperty($whereColumnMap, $where);

        $qb = $db->attributesToAutoBindsComparisonsProperty($qb, $whereColumnMap);
        $qb->setParameters($whereParams)->setMaxResults(1);

        $result = $qb->executeQuery();
        try {
            $row = $result->fetchAssociative();
        } finally {
            $result->free();
        }

        if ($row === false) {
            return false;
        }

        if ($selectColumnsMap !== null) {
            $mappedRow = [];
            foreach ($selectColumnsMap as $propKey => $colName) {
                if (\array_key_exists($colName, $row)) {
                    $mappedRow[$propKey] = $row[$colName];
                }
            }
            $normalizedData = $mappedRow;
        } else {
            $normalizedData = $db->columnNameToProperty($row);
        }

        /** @var static */
        return TypeCaster::hydrateFromDatabase(static::class, $normalizedData, $readTypes);
    }

    /**
     * Fetches multiple records with optional ordering (via PHP 8.6 `\SortDirection`) and clamped limit.
     *
     * @param array<string, mixed> $where
     * @return list<static>
     */
    public static function findAll(
        array $where = [],
        ?string $orderBy = null,
        SortDirection $direction = SortDirection::Ascending,
        int $limit = 100,
        int $offset = 0,
    ): array {
        $db = Atom::$app->db;
        $tableName = static::tableName();
        $selectList = static::attributes();
        $rawTypes = static::attributesTypes();
        $readTypes = $db::prioritizeTheMoppingArrayOfTypes($rawTypes, false);
        $selectColumnsMap = null;

        if ($selectList !== []) {
            $selectKeys = $db->resolveKeys($selectList);
            $selectColumnsMap = $db->propertyToColumnName($selectKeys);
            $selectExpressions = $db->mapColumnFromTypes($selectColumnsMap, $readTypes);
            $qb = $db->selectFrom($tableName, $selectExpressions);
        } else {
            $qb = $db->selectFrom($tableName);
        }

        if ($where !== []) {
            $whereKeys = $db->resolveKeys(array_keys($where));
            $whereColumnMap = $db->propertyToColumnName($whereKeys);
            $whereParams = $db->bindValuesToProperty($whereColumnMap, $where);
            $qb = $db->attributesToAutoBindsComparisonsProperty($qb, $whereColumnMap);
            $qb->setParameters($whereParams);
        }

        if ($orderBy !== null && $orderBy !== '') {
            $qb->orderBy(
                $db->quoteIdentifier($orderBy),
                $direction === SortDirection::Descending ? 'DESC' : 'ASC',
            );
        }

        $qb->setMaxResults(clamp($limit, 1, 10_000))
            ->setFirstResult(clamp($offset, 0, \PHP_INT_MAX));

        $result = $qb->executeQuery();
        try {
            $rows = $result->fetchAllAssociative();
        } finally {
            $result->free();
        }

        $models = [];
        foreach ($rows as $row) {
            if ($selectColumnsMap !== null) {
                $mappedRow = [];
                foreach ($selectColumnsMap as $propKey => $colName) {
                    if (\array_key_exists($colName, $row)) {
                        $mappedRow[$propKey] = $row[$colName];
                    }
                }
                $normalizedData = $mappedRow;
            } else {
                $normalizedData = $db->columnNameToProperty($row);
            }

            /** @var static $hydrated */
            $hydrated = TypeCaster::hydrateFromDatabase(static::class, $normalizedData, $readTypes);
            $models[] = $hydrated;
        }

        return $models;
    }
}
