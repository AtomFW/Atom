<?php

declare(strict_types=1);

namespace Atom\DataBase;

use Atom\Atom;
use Atom\Database\Migrations;
use Atom\DateTime\DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Connection as PDOConnection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as MySQLDriver;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SQLiteDriver;
use Doctrine\DBAL\Driver\PDO\SQLSrv\Driver as SQLSrvDriver;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PGSQLDriver;
use Doctrine\DBAL\Driver\PDO\OCI\Driver as OCIDriver;
use Doctrine\DBAL\Driver as Drivers;

use function PHPUnit\Framework\matches;

/**
 * Database class
 *
 * This class is responsible for connecting to the database and
 * running migrations.
 *
 * @final
 */
final class Database extends Connection
{
    /**
     * The PDO instance for the database connection.
     *
     * @var \PDO
     */
    public \PDO $pdo;

    /**
     * The configuration for the database connection.
     *
     * @var array
     */
    private array $config;

    private Migrations $migrations;


    /**
     * The SmartConnection instance for the database connection.
     *
     * @var Database
     */
    public Database $smartConnection;

    private array $appConfig = [];
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

    public function getConfig (): array
    {
        return $this->config;
    }

    /**
     * Constructs a new Database object.
     *
     * This object is responsible for connecting to the database and
     * running migrations.
     *
     * @param array $dbConfig The configuration for the database connection.
     *     The configuration should contain the following keys:
     *     - dsn: The DSN for the database connection.
     *     - user: The username for the database connection.
     *     - password: The password for the database connection.
     */
    public function __construct(DateTime|array $datetime, array|Drivers $dbConfig = [], ?Configuration $config = null)
    {
        if (!$datetime instanceof DateTime) {
            parent::__construct($datetime, $dbConfig, $config);
            return;
        }

        $this->config = $dbConfig;

        $dbDsn = $dbConfig['dsn'] ?? '';
        $username = $dbConfig['user'] ?? '';
        $password = $dbConfig['password'] ?? '';

        $this->pdo = new \PDO($dbDsn, $username, $password, (array)$dbConfig['options']);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->smartConnection = static::fromExistingPdo($this->pdo);

        $this->migrations = new Migrations($this, $datetime);
    }

    public function __get($name)
    {
        return match ($name) {
            'bulider', 'build', 'sql', 'query' => $this->smartConnection->createQueryBuilder(),
            'connect' => $this->smartConnection,
            default => null
        };
    }

    public static function fromAppConfig(array $config): static
    {
        $params = $config;

        $params['wrapperClass'] = static::class;

        $dbalConfig = new Configuration();

        /** @var static $connection */
        $connection = DriverManager::getConnection($params, $dbalConfig);

        return $connection->boot($config);
    }

    public function boot(array $appConfig): static
    {
        $this->appConfig = $appConfig;

        $this->tablePrefix = $appConfig['table_prefix'] ?? '';
        $this->autoTranslateTypes = $appConfig['auto_translate_types'] ?? true;

        $this->typeMap = [...$this->typeMap, ...$appConfig['type_map'] ?? []];

        return $this;
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
     * Returns a new instance with overridden DB params.
     */
    public function switchDatabase(array $overrideParams): static
    {
        $config = $this->appConfig;

        $currentParams = [];

        $merged = array_replace_recursive($currentParams, $overrideParams);
        $config = $merged;

        return self::fromAppConfig($config);
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
        return $this->smartConnection->createQueryBuilder();
    }

    public function selectFrom(
        string $table,
        array|string $columns = ['*'],
        ?string $alias = null,
        ?string $prefixOverride = null
    ): QueryBuilder {
        $qb = $this->qb();

        $qb->select(...(array)$columns);
        $qb->from($this->table($table, $prefixOverride), $alias ?? 't');

        return $qb;
    }

    public function insertInto(string $table, ?string $prefixOverride = null): QueryBuilder
    {
        $gb = $this->qb();
        $gb->insert($this->table($table, $prefixOverride));

        return $gb;
    }

    public function updateTable(
        string $table,
        ?string $prefixOverride = null
    ): QueryBuilder {
        $gb = $this->qb();
        $gb->update($this->table($table, $prefixOverride));

        return $gb;
    }

    public function deleteFrom(
        string $table,
        ?string $prefixOverride = null
    ): QueryBuilder {
        $gb = $this->qb();
        $gb->delete($this->table($table, $prefixOverride));

        return $gb;
    }

    public function transactionalFast(callable $callback): mixed
    {
        return $this->transactional($callback);
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
            if (!\array_key_exists($column, $row)) {
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

    public static function fromExistingPdo(\PDO $pdo): Database
    {
        $params = ['pdo' => $pdo];
        $config = new Configuration();

        $driver = match ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            "mysql" => new MySQLDriver(),
            "sqlite" => new SQLiteDriver(),
            "pgsql" => new PGSQLDriver(),
            "sqlsrv" => new SQLSrvDriver(),
            "oci" => new OCIDriver(),
            default => null
        };

        if ($driver == null) {
            throw new \Exception("Driver not found", 1);
        }

        $instance = new static($params, $driver, $config);

        $driverConnection = new PDOConnection($pdo);

        $reflection = new \ReflectionClass(Connection::class);
        
        $propName = property_exists(Connection::class, '_conn') ? '_conn' : 'connection';
        
        $property = $reflection->getProperty($propName);
        $property->setValue($instance, $driverConnection);

        return $instance;
    }

    /**
     * Convert a property name to a column name.
     *
     * This method will take a property name (e.g. "userName") and convert it to a column name (e.g. "user_name").
     *
     * @param array $propertyName The property name to convert.
     * @return array The column name.
     */
    public function propertyToColumnName(array $propertyName): array
    {
        foreach ($propertyName as $key => $value) {
            $propertyName[$key] = strtolower(preg_replace(
                // Match any lowercase letter followed by an uppercase letter
                // or a digit followed by an uppercase letter
                '/(?<=[a-z])([A-Z])|(?<=[0-9])([A-Z])/', 
                '_$1', 
                $value
            ));
        }

        return $propertyName;
    }

    /**
     * Convert a column name to a property name.
     *
     * This method will take a column name (e.g. "user_name") and convert it to a property name (e.g. "userName").
     *
     * @param array $input The column name to convert.
     * @return array The property name.
     */
    public function columnNameToProperty(array $input): array
    {
        /**
         * Replace underscores with spaces, capitalize the first letter of each word,
         * and remove the spaces.
         */

        $temp = [];

        foreach ($input as $key => $value) {
            $newKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            $temp[$newKey] = $value;
        }
        return $temp;
    }

    /**
     * Retrieves identifiers from an array regardless of the format used.
     * If the key is a string -> takes the key.
     * If the key is a number (auto-incremented) -> takes the value.
     *
     * @param array $input Mixed array, e.g. ["cosMod" => "table", "obdS"]
     * @return array Extracted identifiers: ["cosMod", "obdS"]
     */
    public function resolveKeys(array $input): array
    {
        $identifiers = [];

        foreach ($input as $key => $value) {
            $resolveKey = \is_int($key) ? $value : $key;
            $identifiers[$resolveKey] = $value;
        }

        return $identifiers;
    }

    public function attributesToBindsProperty(array $attributes, string $comparison = ""): array
    {
        $binds = [];
        foreach ($attributes as $key => $attribute) {
            $binds[$attribute] = $comparison . ":$key";
        }

        return $binds;
    }

    public function attributesToBindsComparisonsProperty(array $attributes): array
    {
        foreach (self::attributesToBindsProperty($attributes, ' = ') as $key => $value) {
            $attributes[$key] = ($key . $value);
        }

        return $attributes;
    }
    
    public function attributesToBindsOtherwisesProperty(array $attributes): array
    {
        foreach (self::attributesToBindsProperty($attributes, ' = ') as $key => $value) {
            $attributes[$key] = $key . $value;
        }

        return $attributes;
    }
    
    public function attributesToAutoBindsComparisonsProperty(QueryBuilder $qb, array $attributes): QueryBuilder
    {
        foreach (self::attributesToBindsComparisonsProperty($attributes) as $key => $value) {
            if ($key === 0) {
                $qb->where($value);
                continue;
            }
         
            $qb->andWhere($value);
        }

        return $qb;
    }
    
    public function attributesToAutoBindsOtherwisesProperty(QueryBuilder $qb, array $attributes): QueryBuilder
    {
        foreach (self::attributesToBindsOtherwisesProperty($attributes) as $key => $value) {
            if ($key === 0) {
                $qb->orWhere($value);
                continue;
            }
         
            $qb->orWhere($value);
        }

        return $qb;
    }

    public function bindValuesToProperty(array $bindProperty, array $values): array
    {
        return \array_combine(\array_values(\array_keys($bindProperty)), $values);
    }

    public function normalizeAttributes(array $attributes): array
    {
        return array_map(fn($attr) => \is_int($attr) ? $attributes[$attr] : $attr, array_keys($attributes));
    }

    public function mapColumnFromTypes(array $attributes,array $attributesTypes):array
    {
        $attributesTypesOnlySQLPrefix = array_filter(
            $attributesTypes, 
            fn($value) => str_starts_with(\is_array($value) ? $value[0] : $value, 'sql_'), 
            ARRAY_FILTER_USE_BOTH
        );
        
        foreach ($attributesTypesOnlySQLPrefix as $key => $value) {
            if ($attributes[$key]) {
                $attributes[$key] =
                    (substr($value, 4)
                    |> strtoupper(...)) .
                "({$attributes[$key]}) as {$attributes[$key]}";
            }
        }

        return $attributes;
    }

    public static function prioritizeTheMoppingArrayOfTypes (array $attributesTypes, bool $getEnd = false): array
    {
        return array_map(function ($types) use ($getEnd) {
            if (\is_array($types)) {
                if ($getEnd && \count($types) > 1) {
                    return $types[1];
                }

                return $types[0];
            }

            return $types;
        }, $attributesTypes);
    }

    public static function filterIgnoredTypes(array $attributes, array $attributesTypes): array
    {
        $temp = array_filter(
            $attributesTypes,
            fn($types) =>
                !stristr($types, "ignore") && $types !== null
        );

        $attributes = array_filter(
            $attributes,
            fn($key) =>
                !isset($attributesTypes[$key]) || (!stristr($attributesTypes[$key], "ignore") && $attributesTypes[$key] !== null)
            );

        return \array_merge($temp, $attributes);
    }

}
