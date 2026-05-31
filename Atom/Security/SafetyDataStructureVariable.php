<?php

declare(strict_types=1);

namespace Atom\Security;

use Atom\Component\Cache\CacheWrapper;
use Atom\DataBase\Database;
use Atom\Exception\IO\InvalidArgumentException;
use PDO;

/**
 * SafetyDataStructureVariable
 *
 * High performance static repository for database tables.
 *
 * Features:
 *
 * - static memory storage
 * - optional external cache support
 * - automatic is_active filtering
 * - dynamic searching
 * - multi-column filtering
 * - sorting
 * - pagination
 * - value extraction
 * - existence checks
 * - distinct values
 * - preload support
 *
 * Data source priority:
 *
 * 1. Static memory
 * 2. External cache
 * 3. Database
 *
 * Example:
 *
 * $repo = new SafetyDataStructureVariable(
 *     $pdo,
 *     $cache
 * );
 *
 * $users = $repo->search(
 *     'users',
 *     [
 *         'role'=>'admin'
 *     ],
 *     'created_at',
 *     'desc'
 * );
 */
final class SafetyDataStructureVariable
{
    private Database $database;
    private ?CacheWrapper $cache;
    private int $cacheTtl;
    private string $cachePrefix;
    private string $connectionKey;

    /**
     * Static data store:
     * [connectionKey][tableName] => array<array<string,mixed>>
     */
    private static array $memoryStore = [];

    public function __construct(
        Database $database,
        ?CacheWrapper $cache = null,
        int $cacheTtl = 300,
        string $cachePrefix = 'local'
    ) {
        $this->database = $database;
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
        $this->cachePrefix = $cachePrefix;
        $this->connectionKey = "prod";
        // $this->connectionKey = spl_object_hash($database);
    }

    /**
     * Preloads the full active dataset into static memory.
     *
     * Optionally warms up selected logical table groups by table_name.
     *
     * @param array<int,string>|null $tableNames
     *     Optional list of values from the table_name column.
     *
     *     Example:
     *     [
     *         'users',
     *         'products',
     *         'categories'
     *     ]
     *
     * @return void
     */
    public function preload(?array $tableNames = null): void
    {
        $this->getAllRows();

        if ($tableNames === null) {
            return;
        }

        foreach ($tableNames as $tableNameValue) {
            $this->getRowsByTableNameValue((string) $tableNameValue);
        }
    }

    /**
     * Clears static memory and external cache.
     *
     * If $tableNameValue is null, the full connection scope is cleared.
     *
     * @param string|null $tableNameValue
     *     Optional value from the table_name column.
     *
     * @return void
     */
    public function clearCache(?string $tableNameValue = null): void
    {
        if ($tableNameValue === null) {
            unset(self::$memoryStore[$this->connectionKey]);

            if ($this->cache !== null) {
                $this->cache->delete($this->cacheKey('all'));
            }

            return;
        }

        unset(self::$memoryStore[$this->connectionKey][$tableNameValue]);

        if ($this->cache !== null) {
            $this->cache->delete($this->cacheKey($tableNameValue));
        }
    }

    /**
     * Reloads the full dataset from the database.
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->clearCache();
        $this->getAllRows();
    }

    /**
     * Returns all active rows matching the given table_name value.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     *     Example:
     *     'users'
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     *     Example:
     *     'sort_order'
     *     'id'
     *     'column_name'
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     *     Allowed values:
     *     - asc
     *     - desc
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(
        string $tableNameValue,
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): array {
        $rows = $this->getRowsByTableNameValue($tableNameValue);

        return $this->sortRows($rows, $sortBy, $sortOrder);
    }

    /**
     * Alias for all().
     *
     * @param string $tableNameValue
     * @param string $sortBy
     * @param string $sortOrder
     *
     * @return array<int,array<string,mixed>>
     */
    public function whereTableName(
        string $tableNameValue,
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): array {
        return $this->all($tableNameValue, $sortBy, $sortOrder);
    }

    /**
     * Returns rows filtered by table_name and column_name.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @param string $columnName
     *     Value from the column_name column.
     *
     *     Example:
     *     'email'
     *
     * @param mixed $value
     *     Value to match against a chosen field.
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return array<int,array<string,mixed>>
     */
    public function whereTableAndColumnName(
        string $tableNameValue,
        string $columnName,
        mixed $value,
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): array {
        return $this->search(
            $tableNameValue,
            [
                'column_name' => $columnName,
                'value' => $value,
            ],
            $sortBy,
            $sortOrder
        );
    }

    /**
     * Returns rows filtered by table_name and value_key.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @param mixed $valueKey
     *     Value to match against the value_key column.
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return array<int,array<string,mixed>>
     */
    public function whereValueKey(
        string $tableNameValue,
        mixed $valueKey,
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): array {
        return $this->where($tableNameValue, 'value_key', $valueKey, $sortBy, $sortOrder);
    }

    /**
     * Returns rows filtered by a single column.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @param string $columnName
     *     Column name used for filtering.
     *
     * @param mixed $value
     *     Expected value.
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return array<int,array<string,mixed>>
     */
    public function where(
        string $tableNameValue,
        string $columnName,
        mixed $value,
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): array {
        return $this->search(
            $tableNameValue,
            [
                $columnName => $value,
            ],
            $sortBy,
            $sortOrder
        );
    }

    /**
     * Returns the first matching active row.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @param array<string,mixed> $filters
     *     Filter structure.
     *
     *     Supported formats:
     *
     *     [
     *         'column_name' => 'email',
     *         'value_key' => 'primary_email',
     *         'sort_order' => 10,
     *         'id' => 1
     *     ]
     *
     *     Advanced format:
     *
     *     [
     *         'sort_order' => [
     *             'operator' => '>=',
     *             'value' => 10
     *         ],
     *         'column_name' => [
     *             'operator' => 'LIKE',
     *             'value' => '%email%'
     *         ]
     *     ]
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return array<string,mixed>|null
     */
    public function first(
        string $tableNameValue,
        array $filters = [],
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): ?array {
        $rows = $this->search($tableNameValue, $filters, $sortBy, $sortOrder);

        return $rows[0] ?? null;
    }

    /**
     * Returns the first matching row by a single column filter.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @param string $columnName
     *     Column name used for filtering.
     *
     * @param mixed $value
     *     Expected value.
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return array<string,mixed>|null
     */
    public function firstWhere(
        string $tableNameValue,
        string $columnName,
        mixed $value,
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): ?array {
        return $this->first($tableNameValue, [$columnName => $value], $sortBy, $sortOrder);
    }

    /**
     * Returns the first matching row by table_name and column_name.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @param string $columnName
     *     Value from the column_name column.
     *
     * @param mixed $value
     *     Expected value.
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return array<string,mixed>|null
     */
    public function firstByTableAndColumnName(
        string $tableNameValue,
        string $columnName,
        mixed $value,
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): ?array {
        return $this->first(
            $tableNameValue,
            [
                'column_name' => $columnName,
                'value_key' => $value,
            ],
            $sortBy,
            $sortOrder
        );
    }

    /**
     * Returns a single column value from the first matching row.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @param string $valueKey
     *     Column name to extract.
     *
     *     Example:
     *     'column_name'
     *     'value_key'
     *     'sort_order'
     *
     * @param array<string,mixed> $filters
     *     Search filters.
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return mixed
     */
    public function value(
        string $tableNameValue,
        string $valueKey,
        array $filters = [],
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): mixed {
        $row = $this->first($tableNameValue, $filters, $sortBy, $sortOrder);

        return $row[$valueKey] ?? null;
    }

    /**
     * Returns a list of values from a selected column.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @param string $columnName
     *     Column to extract.
     *
     * @param array<string,mixed> $filters
     *     Search filters.
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return array<int,mixed>
     */
    public function values(
        string $tableNameValue,
        string $columnName,
        array $filters = [],
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): array {
        $rows = $this->search($tableNameValue, $filters, $sortBy, $sortOrder);

        $out = [];
        foreach ($rows as $row) {
            if (array_key_exists($columnName, $row)) {
                $out[] = $row[$columnName];
            }
        }

        return $out;
    }

    /**
     * Returns values from a selected column with unique results.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @param string $columnName
     *     Column to extract.
     *
     * @param array<string,mixed> $filters
     *     Search filters.
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return array<int,mixed>
     */
    public function distinct(
        string $tableNameValue,
        string $columnName,
        array $filters = [],
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): array {
        return array_values(array_unique(
            $this->values($tableNameValue, $columnName, $filters, $sortBy, $sortOrder),
            SORT_REGULAR
        ));
    }

    /**
     * Checks whether at least one matching active row exists.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @param array<string,mixed> $filters
     *     Search filters.
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return bool
     */
    public function exists(
        string $tableNameValue,
        array $filters = [],
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): bool {
        return $this->first($tableNameValue, $filters, $sortBy, $sortOrder) !== null;
    }

    /**
     * Counts matching active rows.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @param array<string,mixed> $filters
     *     Search filters.
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return int
     */
    public function count(
        string $tableNameValue,
        array $filters = [],
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): int {
        return \count($this->search($tableNameValue, $filters, $sortBy, $sortOrder));
    }

    /**
     * Searches dynamically inside one logical table group.
     *
     * Supported operators:
     * - =
     * - !=
     * - >
     * - >=
     * - <
     * - <=
     * - LIKE
     * - NOT LIKE
     * - IN
     * - NOT IN
     * - BETWEEN
     * - IS NULL
     * - IS NOT NULL
     *
     * Filter structure examples:
     *
     * Simple:
     * [
     *     'column_name' => 'email',
     *     'value_key' => 'primary_email'
     * ]
     *
     * Advanced:
     * [
     *     'sort_order' => [
     *         'operator' => '>=',
     *         'value' => 10
     *     ],
     *     'column_name' => [
     *         'operator' => 'LIKE',
     *         'value' => '%email%'
     *     ],
     *     'value_key' => [
     *         'operator' => 'IN',
     *         'value' => ['a', 'b', 'c']
     *     ]
     * ]
     *
     * Special rule:
     * The repository always enforces is_active = 1.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @param array<string,mixed> $filters
     *     Filtering conditions.
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @param int|null $limit
     *     Maximum number of rows returned.
     *
     * @param int $offset
     *     Offset for pagination.
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(
        string $tableNameValue,
        array $filters = [],
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc',
        ?int $limit = null,
        int $offset = 0
    ): array {
        $rows = $this->getRowsByTableNameValue($tableNameValue);

        $rows = array_values(array_filter($rows, function (array $row) use ($filters): bool {
            foreach ($filters as $column => $condition) {
                $column = (string) $column;

                if ($column === 'is_active') {
                    if ($condition !== 1 && $condition !== '1' && $condition !== true) {
                        return false;
                    }

                    continue;
                }

                if (!array_key_exists($column, $row)) {
                    return false;
                }

                $operator = '=';
                $value = $condition;

                if (is_array($condition) && array_key_exists('operator', $condition)) {
                    $operator = strtoupper((string) ($condition['operator'] ?? '='));
                    $value = $condition['value'] ?? null;
                }

                if (!$this->compare($row[$column], $operator, $value)) {
                    return false;
                }
            }

            return true;
        }));

        $rows = $this->sortRows($rows, $sortBy, $sortOrder);

        if ($offset > 0 || $limit !== null) {
            $rows = array_slice($rows, max(0, $offset), $limit);
        }

        return $rows;
    }

    /**
     * Searches dynamically across all loaded rows, regardless of table_name value.
     *
     * @param array<string,mixed> $filters
     *     Filtering conditions in the same format as search().
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @param int|null $limit
     *     Maximum number of rows returned.
     *
     * @param int $offset
     *     Offset for pagination.
     *
     * @return array<int,array<string,mixed>>
     */
    public function searchStatic(
        array $filters = [],
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc',
        ?int $limit = null,
        int $offset = 0
    ): array {
        $rows = $this->getAllRows();

        $rows = array_values(array_filter($rows, function (array $row) use ($filters): bool {
            foreach ($filters as $column => $condition) {
                $column = (string) $column;

                if ($column === 'is_active') {
                    if ($condition !== 1 && $condition !== '1' && $condition !== true) {
                        return false;
                    }

                    continue;
                }

                if (!array_key_exists($column, $row)) {
                    return false;
                }

                $operator = '=';
                $value = $condition;

                if (is_array($condition) && array_key_exists('operator', $condition)) {
                    $operator = strtoupper((string) ($condition['operator'] ?? '='));
                    $value = $condition['value'] ?? null;
                }

                if (!$this->compare($row[$column], $operator, $value)) {
                    return false;
                }
            }

            return true;
        }));

        $rows = $this->sortRows($rows, $sortBy, $sortOrder);

        if ($offset > 0 || $limit !== null) {
            $rows = array_slice($rows, max(0, $offset), $limit);
        }

        return $rows;
    }

    /**
     * Searches for a value across all columns inside one logical table group.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @param mixed $needle
     *     Value to search for.
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return array<int,array<string,mixed>>
     */
    public function searchAcrossColumns(
        string $tableNameValue,
        mixed $needle,
        string $sortBy = 'sort_order',
        string $sortOrder = 'asc'
    ): array {
        $rows = $this->getRowsByTableNameValue($tableNameValue);

        $rows = array_values(array_filter($rows, function (array $row) use ($needle): bool {
            foreach ($row as $value) {
                if ($this->compare($value, '=', $needle)) {
                    return true;
                }
            }

            return false;
        }));

        return $this->sortRows($rows, $sortBy, $sortOrder);
    }

    /**
     * Returns the list of unique table_name values currently loaded in memory.
     *
     * @return array<int,string>
     */
    public function tables(): array
    {
        $rows = $this->getAllRows();
        $tables = [];

        foreach ($rows as $row) {
            if (isset($row['table_name'])) {
                $tables[] = (string) $row['table_name'];
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Returns the list of available columns from the first matching table_name group.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @return array<int,string>
     */
    public function columns(string $tableNameValue): array
    {
        $rows = $this->getRowsByTableNameValue($tableNameValue);
        $first = $rows[0] ?? [];

        return array_keys($first);
    }

    /**
     * Returns all active rows from the database, memory store, or external cache.
     *
     * @return array<int,array<string,mixed>>
     */
    private function getAllRows(): array
    {
        if (isset(self::$memoryStore[$this->connectionKey])) {
            return self::$memoryStore[$this->connectionKey];
        }

        if ($this->cache !== null) {
            $cached = $this->cache->get($this->cacheKey('all'));

            if (\is_array($cached)) {
                self::$memoryStore[$this->connectionKey] = $cached;
                return $cached;
            }
        }

        $rows = $this->loadAllRowsFromDatabase();

        self::$memoryStore[$this->connectionKey] = $rows;

        if ($this->cache !== null) {
            $this->cache->set($this->cacheKey('all'), $rows, $this->cacheTtl);
        }

        return $rows;
    }

    /**
     * Returns rows filtered by table_name value.
     *
     * @param string $tableNameValue
     *     Value from the table_name column.
     *
     * @return array<int,array<string,mixed>>
     */
    private function getRowsByTableNameValue(string $tableNameValue): array
    {
        if (isset(self::$memoryStore[$this->connectionKey][$tableNameValue])) {
            return self::$memoryStore[$this->connectionKey][$tableNameValue];
        }

        $rows = array_values(array_filter(
            $this->getAllRows(),
            static fn (array $row): bool => (string) ($row['table_name'] ?? '') === $tableNameValue
        ));

        self::$memoryStore[$this->connectionKey][$tableNameValue] = $rows;

        if ($this->cache !== null) {
            $this->cache->set($this->cacheKey($tableNameValue), $rows, $this->cacheTtl);
        }

        return $rows;
    }

    /**
     * Loads all active rows from the database.
     *
     * @return array<int,array<string,mixed>>
     */
    private function loadAllRowsFromDatabase(): array
    {
        $sql = 'SELECT * FROM `allowed_values` WHERE is_active = 1';
        $stmt = $this->database->pdo->query($sql);

        if ($stmt === false) {
            return [];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return \is_array($rows) ? $rows : [];
    }

    /**
     * Creates a cache key.
     *
     * @param string $scope
     *     Cache scope name.
     *
     * @return string
     */
    private function cacheKey(string $scope): string
    {
        return $this->cachePrefix . '_' . $this->connectionKey . '_' . $scope;
    }

    /**
     * Sorts rows by a selected column and direction.
     *
     * @param array<int,array<string,mixed>> $rows
     *     Rows to sort.
     *
     * @param string $sortBy
     *     Column used for sorting.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return array<int,array<string,mixed>>
     */
    private function sortRows(array $rows, string $sortBy, string $sortOrder): array
    {
        if ($rows === []) {
            return [];
        }

        $sortOrder = $this->normalizeSortOrder($sortOrder);

        usort($rows, function (array $a, array $b) use ($sortBy, $sortOrder): int {
            $left = $a[$sortBy] ?? null;
            $right = $b[$sortBy] ?? null;

            $comparison = $this->compareValues($left, $right);

            return $sortOrder === 'desc' ? -$comparison : $comparison;
        });

        return array_values($rows);
    }

    /**
     * Normalizes the sort direction.
     *
     * @param string $sortOrder
     *     Sort direction.
     *
     * @return string
     */
    private function normalizeSortOrder(string $sortOrder): string
    {
        $sortOrder = strtolower(trim($sortOrder));

        return $sortOrder === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Compares two values using a selected operator.
     *
     * @param mixed $left
     * @param string $operator
     * @param mixed $right
     *
     * @return bool
     */
    private function compare(mixed $left, string $operator, mixed $right): bool
    {
        $operator = strtoupper(trim($operator));

        return match ($operator) {
            '=', '==' => $left == $right,
            '!=', '<>' => $left != $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            'LIKE' => $this->likeCompare($left, $right),
            'NOT LIKE' => !$this->likeCompare($left, $right),
            'IN' => is_array($right) ? in_array($left, $right, false) : false,
            'NOT IN' => is_array($right) ? !in_array($left, $right, false) : true,
            'BETWEEN' => $this->betweenCompare($left, $right),
            'IS NULL' => $left === null,
            'IS NOT NULL' => $left !== null,
            default => $left == $right,
        };
    }

    /**
     * Performs a loose comparison useful for sorting.
     *
     * @param mixed $left
     * @param mixed $right
     *
     * @return int
     */
    private function compareValues(mixed $left, mixed $right): int
    {
        if ($left == $right) {
            return 0;
        }

        return ($left <=> $right);
    }

    /**
     * Performs a LIKE-style comparison.
     *
     * @param mixed $left
     * @param mixed $right
     *
     * @return bool
     */
    private function likeCompare(mixed $left, mixed $right): bool
    {
        if (!is_scalar($left) || !is_scalar($right)) {
            return false;
        }

        $left = mb_strtolower((string) $left);
        $right = mb_strtolower((string) $right);
        $needle = trim($right, '%');

        if ($needle === '') {
            return true;
        }

        return str_contains($left, $needle);
    }

    /**
     * Performs a BETWEEN comparison.
     *
     * @param mixed $left
     * @param mixed $right
     *
     * @return bool
     */
    private function betweenCompare(mixed $left, mixed $right): bool
    {
        if (!\is_array($right) || \count($right) < 2) {
            return false;
        }

        $min = $right[0];
        $max = $right[1];

        return $left >= $min && $left <= $max;
    }
}
