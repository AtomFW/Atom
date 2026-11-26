<?php

declare(strict_types=1);

namespace Atom\DataBase;

use Atom\DateTime\DateTime;
use Atom\Filter\TypeCaster;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver as Drivers;
use Doctrine\DBAL\Driver\PDO\Connection as PDOConnection;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as MySQLDriver;
use Doctrine\DBAL\Driver\PDO\OCI\Driver as OCIDriver;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PGSQLDriver;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SQLiteDriver;
use Doctrine\DBAL\Driver\PDO\SQLSrv\Driver as SQLSrvDriver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use PDO;
use ReflectionClass;
use RuntimeException;

use function array_combine;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_replace_recursive;
use function array_values;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function lcfirst;
use function preg_match;
use function preg_replace;
use function property_exists;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strtolower;
use function strtoupper;
use function substr;
use function ucwords;

/**
 * Database connection & query builder abstraction extending Doctrine DBAL Connection.
 */
class Database extends Connection
{
    public PDO $pdo;

    /** @var array<string, mixed> */
    private array $config = [];

    private Migrations $migrations;

    public Database $smartConnection;

    /** @var array<string, mixed> */
    private array $appConfig = [];

    public string $tablePrefix = '';

    /**
     * Read-only property hook exposing configured table prefix without allowing mutation via `$db->prefix`.
     */
    public string $prefix {
        get => $this->tablePrefix;
    }

    private bool $autoTranslateTypes = true;

    /** @var array<string, string> */
    private array $typeMap = [
        'datetime' => 'datetime_immutable',
        'datetimeimmutable' => 'datetime_immutable',
        'date' => 'date_immutable',
        'dateimmutable' => 'date_immutable',
        'time' => 'time_immutable',
        'timeimmutable' => 'time_immutable',
        'json' => 'json',
        'array' => 'json',
        'bool' => 'boolean',
        'boolean' => 'boolean',
        'int' => 'integer',
        'integer' => 'integer',
        'float' => 'float',
        'double' => 'float',
        'string' => 'string',
        'guid' => 'guid',
        'uuid' => 'guid',
        'ulid' => 'guid',
    ];

    /**
     * @param DateTime|array<string, mixed> $datetime
     * @param array<string, mixed>|Drivers $dbConfig
     */
    public function __construct(
        DateTime|array $datetime,
        #[\SensitiveParameter]
        array|Drivers $dbConfig = [],
        ?Configuration $config = null,
    ) {
        if (!$datetime instanceof DateTime) {
            /** @var Drivers $dbConfig */
            parent::__construct($datetime, $dbConfig, $config);
            return;
        }

        /** @var array<string, mixed> $dbConfig */
        $this->config = $dbConfig;
        $this->tablePrefix = (string) ($dbConfig['prefix'] ?? '');

        $dbDsn = (string) ($dbConfig['dsn'] ?? '');
        $username = (string) ($dbConfig['user'] ?? '');
        $password = (string) ($dbConfig['password'] ?? '');
        $options = isset($dbConfig['options']) && is_array($dbConfig['options']) ? $dbConfig['options'] : [];

        $this->pdo = new PDO($dbDsn, $username, $password, $options);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->smartConnection = static::fromExistingPdo($this->pdo);
        $this->smartConnection->setTablePrefix($this->tablePrefix);

        $this->migrations = new Migrations($this, $datetime);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function getMigrations(): Migrations
    {
        return $this->migrations;
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'builder', 'bulider', 'build', 'sql', 'query' => $this->qb(),
            'connect' => $this->smartConnection ?? $this,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromAppConfig(#[\SensitiveParameter] array $config): static
    {
        $params = $config;
        $params['wrapperClass'] = static::class;

        $dbalConfig = new Configuration();

        /** @var static $connection */
        $connection = DriverManager::getConnection($params, $dbalConfig);
        $connection->smartConnection = $connection;

        return $connection->boot($config);
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    public function boot(array $appConfig): static
    {
        $this->appConfig = $appConfig;
        $this->tablePrefix = (string) ($appConfig['table_prefix'] ?? $appConfig['prefix'] ?? '');
        $this->autoTranslateTypes = (bool) ($appConfig['auto_translate_types'] ?? true);

        if (isset($appConfig['type_map']) && is_array($appConfig['type_map'])) {
            $this->typeMap = [...$this->typeMap, ...$appConfig['type_map']];
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAppConfig(): array
    {
        return $this->appConfig;
    }

    public function getPrefix(): string
    {
        return $this->tablePrefix;
    }

    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    public function setTablePrefix(string $prefix): static
    {
        $this->tablePrefix = $prefix;
        return $this;
    }

    public function enableAutoTypeTranslation(bool $enabled = true): static
    {
        $this->autoTranslateTypes = $enabled;
        return $this;
    }

    public function isAutoTypeTranslationEnabled(): bool
    {
        return $this->autoTranslateTypes;
    }

    /**
     * @param array<string, mixed> $overrideParams
     */
    public function switchDatabase(#[\SensitiveParameter] array $overrideParams): static
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive($this->appConfig, $overrideParams);

        return self::fromAppConfig($merged);
    }

    public function table(string $tableName, ?string $prefixOverride = null): string
    {
        $prefix = $prefixOverride ?? $this->tablePrefix;

        if ($prefix === '' || str_starts_with($tableName, $prefix)) {
            return $tableName;
        }

        return $prefix . $tableName;
    }

    /**
     * @return array{table: string, alias: string}
     */
    public function tableAlias(string $tableName, string $alias, ?string $prefixOverride = null): array
    {
        return [
            'table' => $this->table($tableName, $prefixOverride),
            'alias' => $alias,
        ];
    }

    public function qb(): QueryBuilder
    {
        return isset($this->smartConnection)
            ? $this->smartConnection->createQueryBuilder()
            : $this->createQueryBuilder();
    }

    /**
     * @param array<int|string, string>|string $columns
     */
    public function selectFrom(
        string $table,
        array|string $columns = ['*'],
        ?string $alias = null,
        ?string $prefixOverride = null,
    ): QueryBuilder {
        $qb = $this->qb();
        $qb->select(...(array) $columns);
        $qb->from($this->table($table, $prefixOverride), $alias ?? 't');

        return $qb;
    }

    public function insertInto(string $table, ?string $prefixOverride = null): QueryBuilder
    {
        $qb = $this->qb();
        $qb->insert($this->table($table, $prefixOverride));

        return $qb;
    }

    public function updateTable(string $table, ?string $prefixOverride = null): QueryBuilder
    {
        $qb = $this->qb();
        $qb->update($this->table($table, $prefixOverride));

        return $qb;
    }

    public function deleteFrom(string $table, ?string $prefixOverride = null): QueryBuilder
    {
        $qb = $this->qb();
        $qb->delete($this->table($table, $prefixOverride));

        return $qb;
    }

    public function transactionalFast(callable $callback): mixed
    {
        return $this->transactional($callback);
    }

    public function convertToDatabaseValue(mixed $value, string $type): mixed
    {
        $resolvedType = $this->typeMap[$type] ?? $type;
        if (!Type::hasType($resolvedType)) {
            return TypeCaster::castToDatabase($value, $type);
        }

        return Type::getType($resolvedType)->convertToDatabaseValue($value, $this->getDatabasePlatform());
    }

    public function convertToPhpValue(mixed $value, string $type): mixed
    {
        $resolvedType = $this->typeMap[$type] ?? $type;
        if (!Type::hasType($resolvedType)) {
            return TypeCaster::castFromDatabase($value, $type);
        }

        return Type::getType($resolvedType)->convertToPHPValue($value, $this->getDatabasePlatform());
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $resultTypes
     * @return array<string, mixed>
     */
    public function translateRowToPhp(array $row, array $resultTypes): array
    {
        foreach ($resultTypes as $column => $type) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $row[$column] = $this->convertToPhpValue($row[$column], (string) $type);
        }

        return $row;
    }

    public function registerPhpTypeMap(string $phpClassOrScalar, string $dbalType): static
    {
        $this->typeMap[$phpClassOrScalar] = $dbalType;
        return $this;
    }

    #[\Override]
    public function quoteIdentifier(string $identifier): string
    {
        return $this->quoteSingleIdentifier($identifier);
    }

    public static function fromExistingPdo(PDO $pdo): Database
    {
        $params = ['pdo' => $pdo];
        $config = new Configuration();

        $driver = match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'mysql' => new MySQLDriver(),
            'sqlite' => new SQLiteDriver(),
            'pgsql' => new PGSQLDriver(),
            'sqlsrv' => new SQLSrvDriver(),
            'oci' => new OCIDriver(),
            default => throw new RuntimeException('Unsupported PDO database driver.', 1),
        };

        $instance = new static($params, $driver, $config);
        $driverConnection = new PDOConnection($pdo);

        $reflection = new ReflectionClass(Connection::class);
        $propName = property_exists(Connection::class, '_conn') ? '_conn' : 'connection';
        $property = $reflection->getProperty($propName);
        $property->setValue($instance, $driverConnection);

        return $instance;
    }

    /**
     * Converts camelCase property names to snake_case column names.
     *
     * @param array<int|string, string> $propertyName
     * @return array<int|string, string>
     */
    public function propertyToColumnName(array $propertyName): array
    {
        foreach ($propertyName as $key => $value) {
            $converted = preg_replace(
                '/(?<=[a-z])([A-Z])|(?<=[0-9])([A-Z])/',
                '_$1',
                (string) $value,
            );
            $propertyName[$key] = strtolower($converted ?? (string) $value);
        }

        return $propertyName;
    }

    /**
     * Converts snake_case column keys in a row to camelCase property keys.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function columnNameToProperty(array $input): array
    {
        $mapped = [];

        foreach ($input as $key => $value) {
            $camelKey = $key
                |> str_replace('_', ' ', ?)
                |> ucwords(...)
                |> str_replace(' ', '', ?)
                |> lcfirst(...);

            $mapped[$camelKey] = $value;
        }

        return $mapped;
    }

    /**
     * @param array<int|string, string> $input
     * @return array<string, string>
     */
    public function resolveKeys(array $input): array
    {
        $identifiers = [];

        foreach ($input as $key => $value) {
            $resolvedKey = is_int($key) ? (string) $value : $key;
            $identifiers[$resolvedKey] = (string) $value;
        }

        return $identifiers;
    }

    /**
     * @param array<int|string, string> $attributes
     * @return array<string, string>
     */
    public function attributesToBindsProperty(array $attributes, string $comparison = ''): array
    {
        $binds = [];
        foreach ($attributes as $key => $attribute) {
            $binds[(string) $attribute] = "{$comparison}:{$key}";
        }

        return $binds;
    }

    /**
     * @param array<int|string, string> $attributes
     * @return array<int|string, string>
     */
    public function attributesToBindsComparisonsProperty(array $attributes): array
    {
        foreach ($this->attributesToBindsProperty($attributes, ' = ') as $key => $value) {
            $attributes[$key] = $key . $value;
        }

        return $attributes;
    }

    /**
     * @param array<int|string, string> $attributes
     * @return array<int|string, string>
     */
    public function attributesToBindsOtherwisesProperty(array $attributes): array
    {
        return $this->attributesToBindsComparisonsProperty($attributes);
    }

    /**
     * @param array<int|string, string> $attributes
     */
    public function attributesToAutoBindsComparisonsProperty(QueryBuilder $qb, array $attributes): QueryBuilder
    {
        $isFirst = true;
        foreach ($this->attributesToBindsComparisonsProperty($attributes) as $condition) {
            if ($isFirst) {
                $qb->where($condition);
                $isFirst = false;
                continue;
            }

            $qb->andWhere($condition);
        }

        return $qb;
    }

    /**
     * @param array<int|string, string> $attributes
     */
    public function attributesToAutoBindsOtherwisesProperty(QueryBuilder $qb, array $attributes): QueryBuilder
    {
        foreach ($this->attributesToBindsOtherwisesProperty($attributes) as $condition) {
            $qb->orWhere($condition);
        }

        return $qb;
    }

    /**
     * @param array<string, mixed> $bindProperty
     * @param array<int|string, mixed> $values
     * @return array<string, mixed>
     */
    public function bindValuesToProperty(array $bindProperty, array $values): array
    {
        $keys = array_keys($bindProperty);
        $vals = array_values($values);

        return array_combine($keys, $vals);
    }

    /**
     * @param array<int|string, string> $attributes
     * @return list<string>
     */
    public function normalizeAttributes(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $key => $val) {
            $result[] = is_int($key) ? $val : $key;
        }

        return $result;
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, string|array<int, string>> $attributesTypes
     * @return array<string, string>
     */
    public function mapColumnFromTypes(array $attributes, array $attributesTypes): array
    {
        foreach ($attributesTypes as $key => $spec) {
            $typeStr = is_array($spec) ? (string) ($spec[0] ?? '') : (string) $spec;
            if (!str_starts_with($typeStr, 'sql_') || empty($attributes[$key])) {
                continue;
            }

            $sqlFunc = substr($typeStr, 4) |> strtoupper(...);
            if (preg_match('/^[A-Z0-9_]+$/', $sqlFunc) !== 1) {
                continue;
            }

            $column = $attributes[$key];
            $attributes[$key] = "{$sqlFunc}({$column}) as {$column}";
        }

        return $attributes;
    }

    /**
     * Extracts either the primary read type (`$getEnd = false`) or write type (`$getEnd = true`)
     * using a stateless closure optimized by PHP 8.6.
     *
     * @param array<int|string, mixed> $attributesTypes
     * @return array<int|string, mixed>
     */
    public static function prioritizeTheMoppingArrayOfTypes(array $attributesTypes, bool $getEnd = false): array
    {
        if (!$getEnd) {
            return array_map(
                static fn(mixed $types): mixed => is_array($types) ? ($types[0] ?? null) : $types,
                $attributesTypes,
            );
        }

        return array_map(
            static fn(mixed $types): mixed => is_array($types)
                ? (count($types) > 1 ? $types[1] : ($types[0] ?? null))
                : $types,
            $attributesTypes,
        );
    }

    /**
     * Filters out attributes and type definitions marked with `ignore` or `null`.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $attributesTypes
     * @return array<string, mixed>
     */
    public static function filterIgnoredTypes(array $attributes, array $attributesTypes): array
    {
        $validTypes = array_filter(
            $attributesTypes,
            static fn(mixed $type): bool => $type !== null && (!is_string($type) || stripos($type, 'ignore') === false),
        );

        $filteredAttributes = array_filter(
            $attributes,
            static fn(string|int $key): bool => !array_key_exists($key, $attributesTypes)
                || (
                    $attributesTypes[$key] !== null
                    && (!is_string($attributesTypes[$key]) || stripos($attributesTypes[$key], 'ignore') === false)
                ),
            \ARRAY_FILTER_USE_KEY,
        );

        return array_merge($validTypes, $filteredAttributes);
    }
}
