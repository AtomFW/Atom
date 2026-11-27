<?php

declare(strict_types=1);

namespace Atom\DataBase;

use Atom\Atom;
use Atom\Filter\TypeCaster;
use Atom\Model;
use PDOStatement;
use RuntimeException;
use SortDirection;

use function array_keys;
use function clamp;

/**
 * Base Active Record model for database-backed entities with automatic,
 * reflection-cached type casting via `Atom\Filter\TypeCaster`.
 */
abstract class DbModel extends Model
{
    private static function requireDatabase(): Database
    {
        if (!isset(Atom::$app) || Atom::$app->db === null || Atom::$app->isNoDbMode()) {
            throw new RuntimeException('Database operations are disabled in stateless noDB / microservice mode.');
        }

        return Atom::$app->db;
    }
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
        $db = self::requireDatabase();
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

        $probe = isset(Atom::$app) ? Atom::$app->probe : null;
        $dbSpan = $probe !== null
            ? $probe->startSpan('db.insert:' . $tableName, 'database', __FILE__, __LINE__, ['table' => $tableName, 'model' => static::class])
            : '';

        try {
            $result = $db->insertInto($tableName)
                ->values($bindsMap)
                ->setParameters($bindValues)
                ->executeQuery();

            $result->free();
        } finally {
            if ($probe !== null && $dbSpan !== '') {
                $probe->endSpan($dbSpan);
            }
        }

        return true;
    }

    /**
     * Prepares a parameterized SQL statement on the active PDO connection.
     */
    public static function prepare(string $sql): PDOStatement
    {
        return self::requireDatabase()->pdo->prepare($sql);
    }

    /**
     * Fetches a single record matching `$where` and hydrates it into `static`.
     *
     * @param array<string, mixed> $where
     */
    public static function findOne(array $where): static|false
    {
        $db = self::requireDatabase();
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

        $probe = isset(Atom::$app) ? Atom::$app->probe : null;
        $dbSpan = $probe !== null
            ? $probe->startSpan('db.findOne:' . $tableName, 'database', __FILE__, __LINE__, ['table' => $tableName, 'model' => static::class])
            : '';

        $result = $qb->executeQuery();
        try {
            $row = $result->fetchAssociative();
        } finally {
            $result->free();
            if ($probe !== null && $dbSpan !== '') {
                $probe->endSpan($dbSpan, ['hit' => isset($row) && $row !== false]);
            }
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
        $db = self::requireDatabase();
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

        $probe = isset(Atom::$app) ? Atom::$app->probe : null;
        $dbSpan = $probe !== null
            ? $probe->startSpan('db.findAll:' . $tableName, 'database', __FILE__, __LINE__, ['table' => $tableName, 'model' => static::class, 'limit' => $limit])
            : '';

        $result = $qb->executeQuery();
        try {
            $rows = $result->fetchAllAssociative();
        } finally {
            $result->free();
            if ($probe !== null && $dbSpan !== '') {
                $probe->endSpan($dbSpan, ['rows' => isset($rows) ? \count($rows) : 0]);
            }
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
