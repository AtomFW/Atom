<?php

declare(strict_types=1);

namespace Atom\Security;

use Atom\Component\Cache\CacheWrapper;
use Atom\DataBase\Database;
use PDO;
use SortDirection;
use Time\Duration;

/**
 * High-performance lookup repository for `allowed_values` with bounded in-memory cache,
 * external PSR-16 cache support, and native PHP 8.6 `\SortDirection` & `\Time\Duration` integration.
 */
final class SafetyDataStructureVariable
{
    private const int MAX_MEMORY_SCOPES = 128;

    private Database $database;
    private ?CacheWrapper $cache;
    private int $cacheTtl;
    private string $cachePrefix;
    private string $connectionKey;

    /**
     * Bounded static store: [connectionKey][scope] => list<array<string, mixed>>
     *
     * @var array<string, array<string, list<array<string, mixed>>>>
     */
    private static array $memoryStore = [];

    public function __construct(
        Database $database,
        ?CacheWrapper $cache = null,
        int|Duration $cacheTtl = 300,
        string $cachePrefix = 'local',
    ) {
        $this->database = $database;
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl instanceof Duration ? \max(0, $cacheTtl->seconds) : \max(0, $cacheTtl);
        $this->cachePrefix = $cachePrefix;
        $this->connectionKey = 'prod';
    }

    /**
     * @param array<int, string>|null $tableNames
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

    public function clearCache(?string $tableNameValue = null): void
    {
        if ($tableNameValue === null) {
            unset(self::$memoryStore[$this->connectionKey]);
            $this->cache?->delete($this->cacheKey('all'));
            return;
        }

        unset(self::$memoryStore[$this->connectionKey][$tableNameValue]);
        $this->cache?->delete($this->cacheKey($tableNameValue));
    }

    public function refresh(): void
    {
        $this->clearCache();
        $this->getAllRows();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(
        string $tableNameValue,
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): array {
        return $this->sortRows($this->getRowsByTableNameValue($tableNameValue), $sortBy, $sortOrder);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function whereTableName(
        string $tableNameValue,
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): array {
        return $this->all($tableNameValue, $sortBy, $sortOrder);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function whereTableAndColumnName(
        string $tableNameValue,
        string $columnName,
        mixed $value,
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): array {
        return $this->search(
            $tableNameValue,
            ['column_name' => $columnName, 'value' => $value],
            $sortBy,
            $sortOrder,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function whereValueKey(
        string $tableNameValue,
        mixed $valueKey,
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): array {
        return $this->where($tableNameValue, 'value_key', $valueKey, $sortBy, $sortOrder);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function where(
        string $tableNameValue,
        string $columnName,
        mixed $value,
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): array {
        return $this->search($tableNameValue, [$columnName => $value], $sortBy, $sortOrder);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function first(
        string $tableNameValue,
        array $filters = [],
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): ?array {
        $rows = $this->search($tableNameValue, $filters, $sortBy, $sortOrder, 1);
        return $rows[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function firstWhere(
        string $tableNameValue,
        string $columnName,
        mixed $value,
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): ?array {
        return $this->first($tableNameValue, [$columnName => $value], $sortBy, $sortOrder);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function firstByTableAndColumnName(
        string $tableNameValue,
        string $columnName,
        mixed $value,
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): ?array {
        return $this->first(
            $tableNameValue,
            ['column_name' => $columnName, 'value_key' => $value],
            $sortBy,
            $sortOrder,
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function value(
        string $tableNameValue,
        string $valueKey,
        array $filters = [],
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): mixed {
        $row = $this->first($tableNameValue, $filters, $sortBy, $sortOrder);
        return $row[$valueKey] ?? null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<mixed>
     */
    public function values(
        string $tableNameValue,
        string $columnName,
        array $filters = [],
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): array {
        $rows = $this->search($tableNameValue, $filters, $sortBy, $sortOrder);
        $out = [];
        foreach ($rows as $row) {
            if (\array_key_exists($columnName, $row)) {
                $out[] = $row[$columnName];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<mixed>
     */
    public function distinct(
        string $tableNameValue,
        string $columnName,
        array $filters = [],
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): array {
        return \array_values(\array_unique(
            $this->values($tableNameValue, $columnName, $filters, $sortBy, $sortOrder),
            \SORT_REGULAR,
        ));
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function exists(
        string $tableNameValue,
        array $filters = [],
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): bool {
        return $this->first($tableNameValue, $filters, $sortBy, $sortOrder) !== null;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(
        string $tableNameValue,
        array $filters = [],
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): int {
        return \count($this->search($tableNameValue, $filters, $sortBy, $sortOrder));
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function search(
        string $tableNameValue,
        array $filters = [],
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
        ?int $limit = null,
        int $offset = 0,
    ): array {
        return $this->filterAndPaginate(
            $this->getRowsByTableNameValue($tableNameValue),
            $filters,
            $sortBy,
            $sortOrder,
            $limit,
            $offset,
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function searchStatic(
        array $filters = [],
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
        ?int $limit = null,
        int $offset = 0,
    ): array {
        return $this->filterAndPaginate(
            $this->getAllRows(),
            $filters,
            $sortBy,
            $sortOrder,
            $limit,
            $offset,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchAcrossColumns(
        string $tableNameValue,
        mixed $needle,
        string $sortBy = 'sort_order',
        string|SortDirection $sortOrder = SortDirection::Ascending,
    ): array {
        $rows = \array_values(\array_filter(
            $this->getRowsByTableNameValue($tableNameValue),
            fn(array $row): bool => \array_any($row, fn(mixed $val): bool => $this->compare($val, '=', $needle))
        ));

        return $this->sortRows($rows, $sortBy, $sortOrder);
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = [];
        foreach ($this->getAllRows() as $row) {
            if (isset($row['table_name'])) {
                $tables[] = (string) $row['table_name'];
            }
        }

        return \array_values(\array_unique($tables));
    }

    /**
     * @return list<string>
     */
    public function columns(string $tableNameValue): array
    {
        $rows = $this->getRowsByTableNameValue($tableNameValue);
        $first = $rows[0] ?? [];

        return \array_map(\strval(...), \array_keys($first));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function filterAndPaginate(
        array $rows,
        array $filters,
        string $sortBy,
        string|SortDirection $sortOrder,
        ?int $limit,
        int $offset,
    ): array {
        if ($filters !== []) {
            $rows = \array_values(\array_filter($rows, function (array $row) use ($filters): bool {
                foreach ($filters as $column => $condition) {
                    $col = (string) $column;

                    if ($col === 'is_active') {
                        if ($condition !== 1 && $condition !== '1' && $condition !== true) {
                            return false;
                        }
                        continue;
                    }

                    if (!\array_key_exists($col, $row)) {
                        return false;
                    }

                    $operator = '=';
                    $value = $condition;

                    if (\is_array($condition) && \array_key_exists('operator', $condition)) {
                        $operator = \strtoupper((string) ($condition['operator'] ?? '='));
                        $value = $condition['value'] ?? null;
                    }

                    if (!$this->compare($row[$col], $operator, $value)) {
                        return false;
                    }
                }

                return true;
            }));
        }

        $rows = $this->sortRows($rows, $sortBy, $sortOrder);

        if ($offset > 0 || $limit !== null) {
            $rows = \array_slice($rows, \max(0, $offset), $limit);
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getAllRows(): array
    {
        if (isset(self::$memoryStore[$this->connectionKey]['__all__'])) {
            return self::$memoryStore[$this->connectionKey]['__all__'];
        }

        if ($this->cache !== null) {
            $cached = $this->cache->get($this->cacheKey('all'));
            if (\is_array($cached)) {
                $this->putMemoryScope('__all__', $cached);
                return $cached;
            }
        }

        $rows = $this->loadAllRowsFromDatabase();
        $this->putMemoryScope('__all__', $rows);
        $this->cache?->set($this->cacheKey('all'), $rows, $this->cacheTtl);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getRowsByTableNameValue(string $tableNameValue): array
    {
        if (isset(self::$memoryStore[$this->connectionKey][$tableNameValue])) {
            return self::$memoryStore[$this->connectionKey][$tableNameValue];
        }

        $rows = \array_values(\array_filter(
            $this->getAllRows(),
            static fn(array $row): bool => (string) ($row['table_name'] ?? '') === $tableNameValue
        ));

        $this->putMemoryScope($tableNameValue, $rows);
        $this->cache?->set($this->cacheKey($tableNameValue), $rows, $this->cacheTtl);

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function putMemoryScope(string $scope, array $rows): void
    {
        if (!isset(self::$memoryStore[$this->connectionKey])) {
            self::$memoryStore[$this->connectionKey] = [];
        }

        if (\count(self::$memoryStore[$this->connectionKey]) >= self::MAX_MEMORY_SCOPES) {
            $oldest = \array_key_first(self::$memoryStore[$this->connectionKey]);
            if ($oldest !== '__all__') {
                unset(self::$memoryStore[$this->connectionKey][$oldest]);
            }
        }

        self::$memoryStore[$this->connectionKey][$scope] = $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadAllRowsFromDatabase(): array
    {
        $rawPrefix = $this->database->tablePrefix;
        $prefix = $rawPrefix !== ''
            ? (\preg_replace('/[^a-zA-Z0-9_]/', '', $rawPrefix) ?? '')
            : '';

        $sql = "SELECT * FROM `{$prefix}allowed_values` WHERE is_active = 1";
        $stmt = $this->database->pdo->query($sql);

        if ($stmt === false) {
            return [];
        }

        try {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            $stmt->closeCursor();
        }

        return $rows;
    }

    private function cacheKey(string $scope): string
    {
        return $this->cachePrefix . '_' . $this->connectionKey . '_' . $scope;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows, string $sortBy, string|SortDirection $sortOrder): array
    {
        if ($rows === []) {
            return [];
        }

        $direction = $this->normalizeSortDirection($sortOrder);

        \usort($rows, static function (array $a, array $b) use ($sortBy, $direction): int {
            $left = $a[$sortBy] ?? null;
            $right = $b[$sortBy] ?? null;

            $comparison = $left == $right ? 0 : ($left <=> $right);

            return $direction === SortDirection::Descending ? -$comparison : $comparison;
        });

        return $rows;
    }

    private function normalizeSortDirection(string|SortDirection $sortOrder): SortDirection
    {
        if ($sortOrder instanceof SortDirection) {
            return $sortOrder;
        }

        return \strtolower(\trim($sortOrder)) === 'desc'
            ? SortDirection::Descending
            : SortDirection::Ascending;
    }

    private function compare(mixed $left, string $operator, mixed $right): bool
    {
        return match (\strtoupper(\trim($operator))) {
            '=', '==' => $left == $right,
            '!=', '<>' => $left != $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            'LIKE' => $this->likeCompare($left, $right),
            'NOT LIKE' => !$this->likeCompare($left, $right),
            'IN' => \is_array($right) && \in_array($left, $right, false),
            'NOT IN' => !\is_array($right) || !\in_array($left, $right, false),
            'BETWEEN' => $this->betweenCompare($left, $right),
            'IS NULL' => $left === null,
            'IS NOT NULL' => $left !== null,
            default => $left == $right,
        };
    }

    private function likeCompare(mixed $left, mixed $right): bool
    {
        if (!\is_scalar($left) || !\is_scalar($right)) {
            return false;
        }

        $needle = \trim(\mb_strtolower((string) $right), '%');
        if ($needle === '') {
            return true;
        }

        return \str_contains(\mb_strtolower((string) $left), $needle);
    }

    private function betweenCompare(mixed $left, mixed $right): bool
    {
        if (!\is_array($right) || \count($right) < 2) {
            return false;
        }

        return $left >= $right[0] && $left <= $right[1];
    }
}
