<?php
declare(strict_types=1);

namespace Atom\Database;

use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use PDO;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as MySQLDriver;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SQLiteDriver;
use Doctrine\DBAL\Driver\PDO\SQLSrv\Driver as SQLSrvDriver;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PGSQLDriver;
use Doctrine\DBAL\Driver\PDO\OCI\Driver as OCIDriver;

final class SmartConnection extends Connection
{
    private array $appConfig = [];
    private string $connectionName = 'default';
    private string $tablePrefix = '';
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

    private static function normalizeConfig(array $config, ?string $connectionName): array
    {
        // CASE 1: already in full format
        if (isset($config['connections'])) {
            return $config;
        }

        // CASE 2: flat config
        if (isset($config['driver']) || isset($config['url'])) {
            return [
                'default_connection' => 'default',
                'table_prefix' => $config['table_prefix'] ?? '',
                'auto_translate_types' => $config['auto_translate_types'] ?? true,
                'connections' => [
                    'default' => [
                        'params' => $config,
                    ],
                ],
            ];
        }

        // CASE 3: profiles (main, analytics itd.)
        if ($connectionName === null) {
            $connectionName = $config['default'] ?? array_key_first($config);
        }

        if (!isset($config[$connectionName])) {
            throw new InvalidArgumentException("Brak konfiguracji: {$connectionName}");
        }

        return [
            'default_connection' => $connectionName,
            'connections' => array_map(function ($conn) {
                return [
                    'params' => $conn,
                    'table_prefix' => $conn['prefix'] ?? '',
                ];
            }, $config),
        ];
    }

    public static function fromAppConfig(array $config, ?string $connectionName = null): static
    {
        $config = self::normalizeConfig($config, $connectionName);

        $connectionName ??= $config['default_connection'] ?? 'default';

        $params = $config['connections'][$connectionName]['params'] ?? [];

        $params['wrapperClass'] = static::class;

        $dbalConfig = new \Doctrine\DBAL\Configuration();

        /** @var static $connection */
        $connection = \Doctrine\DBAL\DriverManager::getConnection($params, $dbalConfig);

        return $connection->boot($config, $connectionName);
    }

    public function boot(array $appConfig, string $connectionName): static
    {
        $this->appConfig = $appConfig;
        $this->connectionName = $connectionName;

        $connectionConfig = $appConfig['connections'][$connectionName] ?? [];
        $this->tablePrefix = (string)($connectionConfig['table_prefix'] ?? $appConfig['table_prefix'] ?? '');
        $this->autoTranslateTypes = (bool)($connectionConfig['auto_translate_types'] ?? $appConfig['auto_translate_types'] ?? true);

        $this->typeMap = array_replace($this->typeMap, $appConfig['type_map'] ?? [], $connectionConfig['type_map'] ?? []);

        $autoCommit = $connectionConfig['auto_commit'] ?? $appConfig['auto_commit'] ?? null;
        if ($autoCommit !== null) {
            $this->setAutoCommit((bool)$autoCommit);
        }

        return $this;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function getAppConfig(): array
    {
        return $this->appConfig;
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
     * Returns a new instance with another connection config.
     * This is the safe way to "switch database at runtime".
     */
    public function switchConnection(string $connectionName): static
    {
        return self::fromAppConfig($this->appConfig, $connectionName);
    }

    /**
     * Returns a new instance with overridden DB params.
     */
    public function switchDatabase(array $overrideParams): static
    {
        $config = $this->appConfig;
        $current = $config['connections'][$this->connectionName] ?? [];

        if (!is_array($current)) {
            $current = [];
        }

        $currentParams = $current['params'] ?? $current;
        if (!is_array($currentParams)) {
            $currentParams = [];
        }

        $merged = array_replace_recursive($currentParams, $overrideParams);
        $config['connections'][$this->connectionName]['params'] = $merged;

        return self::fromAppConfig($config, $this->connectionName);
    }

    public function table(string $tableName, ?string $prefixOverride = null): string
    {
        $prefix = $prefixOverride ?? $this->tablePrefix;

        if ($prefix === '') {
            return $tableName;
        }

        if (str_starts_with($tableName, $prefix)) {
            return $tableName;
        }

        return $prefix . $tableName;
    }

    public function tableAlias(string $tableName, string $alias, ?string $prefixOverride = null): array
    {
        return [
            'table' => $this->table($tableName, $prefixOverride),
            'alias' => $alias,
        ];
    }

    public function qb(): QueryBuilder
    {
        return $this->createQueryBuilder();
    }

    public function selectFrom(
        string $table,
        array|string $columns = ['*'],
        ?string $alias = null,
        ?string $prefixOverride = null
    ): QueryBuilder {
        $qb = $this->createQueryBuilder();

        $qb->select(...(array)$columns);
        $qb->from($this->table($table, $prefixOverride), $alias ?? 't');

        return $qb;
    }

    public function insertInto(string $table, array $data, ?string $prefixOverride = null): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('Dane do INSERT nie mogą być puste.');
        }

        $sqlTable = $this->table($table, $prefixOverride);
        $columns = array_keys($data);

        $placeholders = [];
        $params = [];
        $types = [];

        foreach ($data as $column => $value) {
            [$dbValue, $dbType] = $this->prepareParam($value);
            $placeholders[] = ':' . $column;
            $params[$column] = $dbValue;

            if ($dbType !== null) {
                $types[$column] = $dbType;
            }
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $sqlTable,
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );

        return $this->executeStatement($sql, $params, $types);
    }

    public function updateTable(
        string $table,
        array $data,
        string $where,
        array $whereParams = [],
        array $whereTypes = [],
        ?string $prefixOverride = null
    ): int {
        if ($data === []) {
            throw new InvalidArgumentException('Dane do UPDATE nie mogą być puste.');
        }

        $sqlTable = $this->table($table, $prefixOverride);

        $setParts = [];
        $params = [];
        $types = [];

        foreach ($data as $column => $value) {
            [$dbValue, $dbType] = $this->prepareParam($value);
            $setParts[] = $this->quoteIdentifier($column) . ' = :' . $column;
            $params[$column] = $dbValue;

            if ($dbType !== null) {
                $types[$column] = $dbType;
            }
        }

        foreach ($whereParams as $key => $value) {
            [$dbValue, $dbType] = $this->prepareParam($value);
            $params[$key] = $dbValue;

            if ($dbType !== null) {
                $whereTypes[$key] = $dbType;
            }
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $sqlTable,
            implode(', ', $setParts),
            $where
        );

        return $this->executeStatement($sql, $params, $types + $whereTypes);
    }

    public function deleteFrom(
        string $table,
        string $where,
        array $params = [],
        array $types = [],
        ?string $prefixOverride = null
    ): int {
        $sqlTable = $this->table($table, $prefixOverride);

        foreach ($params as $key => $value) {
            [$dbValue, $dbType] = $this->prepareParam($value);
            $params[$key] = $dbValue;

            if ($dbType !== null) {
                $types[$key] = $dbType;
            }
        }

        $sql = sprintf('DELETE FROM %s WHERE %s', $sqlTable, $where);

        return $this->executeStatement($sql, $params, $types);
    }

    public function executeFastQuery(string $sql, array $params = [], array $types = [])
    {
        $params = $this->prepareParams($params, $types);
        return $this->executeQuery($sql, $params, $types);
    }

    public function executeFastStatement(string $sql, array $params = [], array $types = []): int
    {
        $params = $this->prepareParams($params, $types);
        return $this->executeStatement($sql, $params, $types);
    }

    public function fetchAllTyped(string $sql, array $params = [], array $types = [], array $resultTypes = []): array
    {
        $rows = $this->executeFastQuery($sql, $params, $types)->fetchAllAssociative();

        if ($resultTypes === []) {
            return $rows;
        }

        return array_map(
            fn(array $row): array => $this->translateRowToPhp($row, $resultTypes),
            $rows
        );
    }

    public function fetchOneTyped(string $sql, array $params = [], array $types = [], ?string $resultType = null): mixed
    {
        $value = $this->executeFastQuery($sql, $params, $types)->fetchOne();

        if ($resultType === null) {
            return $value;
        }

        return $this->convertToPhpValue($value, $resultType);
    }

    public function fetchAssociativeTyped(string $sql, array $params = [], array $types = [], array $resultTypes = []): array|false
    {
        $row = $this->executeFastQuery($sql, $params, $types)->fetchAssociative();

        if ($row === false) {
            return false;
        }

        if ($resultTypes === []) {
            return $row;
        }

        return $this->translateRowToPhp($row, $resultTypes);
    }

    public function transactionalFast(callable $callback): mixed
    {
        return $this->transactional($callback);
    }

    public function prepareParam(mixed $value, ?string $explicitType = null): array
    {
        if (!$this->autoTranslateTypes) {
            return [$value, $this->normalizeExplicitType($explicitType)];
        }

        if ($explicitType !== null) {
            return [$this->convertToDatabaseValue($value, $explicitType), $this->normalizeExplicitType($explicitType)];
        }

        [$detectedType, $normalized] = $this->detectType($value);

        if ($detectedType === null) {
            return [$value, null];
        }

        return [$this->convertToDatabaseValue($value, $detectedType), $normalized];
    }

    public function prepareParams(array $params, array &$types = []): array
    {
        foreach ($params as $key => $value) {
            $explicitType = $types[$key] ?? null;
            [$dbValue, $dbType] = $this->prepareParam($value, is_string($explicitType) ? $explicitType : null);
            $params[$key] = $dbValue;

            if ($dbType !== null) {
                $types[$key] = $dbType;
            }
        }

        return $params;
    }

    public function convertToDatabaseValue(mixed $value, string $type): mixed
    {
        if (!Type::hasType($type)) {
            return $value;
        }

        return Type::getType($type)->convertToDatabaseValue($value, $this->getDatabasePlatform());
    }

    public function convertToPhpValue(mixed $value, string $type): mixed
    {
        if (!Type::hasType($type)) {
            return $value;
        }

        return Type::getType($type)->convertToPHPValue($value, $this->getDatabasePlatform());
    }

    public function translateRowToPhp(array $row, array $resultTypes): array
    {
        foreach ($resultTypes as $column => $type) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $row[$column] = $this->convertToPhpValue($row[$column], (string)$type);
        }

        return $row;
    }

    public function registerPhpTypeMap(string $phpClassOrScalar, string $dbalType): static
    {
        $this->typeMap[$phpClassOrScalar] = $dbalType;
        return $this;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->quoteSingleIdentifier($identifier);
    }

    private function detectType(mixed $value): array
    {
        if ($value instanceof DateTimeInterface) {
            return ['datetime_immutable', 'datetime_immutable'];
        }

        if (\is_bool($value)) {
            return ['boolean', 'boolean'];
        }

        if (\is_int($value)) {
            return ['integer', 'integer'];
        }

        if (\is_float($value)) {
            return ['float', 'float'];
        }

        if (\is_array($value)) {
            return ['json', 'json'];
        }

        if (\is_object($value)) {
            foreach ($this->typeMap as $class => $dbalType) {
                if (class_exists($class) && $value instanceof $class) {
                    return [$dbalType, $dbalType];
                }
            }
        }

        return [null, null];
    }

    private function normalizeExplicitType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        return $this->typeMap[$type] ?? $type;
    }

    public static function fromExistingPdo(PDO $pdo): SmartConnection
    {
        $params = ['pdo' => $pdo];
        $config = new Configuration();

        $driver = match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            "mysql" => new MySQLDriver(),
            "sqlite" => new SQLiteDriver(),
            "pgsql" => new PGSQLDriver(),
            "sqlsrv" => new SQLSrvDriver(),
            "oci" => new OCIDriver(),
            default => new MySQLDriver()
        };

        $instance = new static($params, $driver, $config);

        $driverConnection = new \Doctrine\DBAL\Driver\PDO\Connection($pdo);

        $reflection = new \ReflectionClass(Connection::class);
        
        $propName = property_exists(Connection::class, '_conn') ? '_conn' : 'connection';
        
        $property = $reflection->getProperty($propName);
        $property->setValue($instance, $driverConnection);

        return $instance;
    }
}
