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

    /**
     * Retrieves the current configuration array
     * 
     * This public method returns the internal configuration array that contains
     * all the configuration settings for this database connection. The configuration
     * typically includes database connection parameters, table prefixes, type mappings,
     * and other environment-specific settings.
     * 
     * @return array Returns the complete configuration array
     */
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

    /**
     * Magic getter method that provides dynamic access to properties with lazy loading
     * 
     * This public method implements the __get magic method to create and return specific
     * objects or values when certain property names are accessed. It acts as a simple
     * factory or proxy method for commonly used database operations.
     * 
     * The method handles these special cases:
     * - 'bulider', 'build', 'sql', 'query' all return the same QueryBuilder instance
     * - Any other property returns null by default
     * 
     * This pattern is useful for creating convenient aliases to frequently used objects
     * without requiring explicit property declarations or complex getter logic.
     * 
     * @param string $name The name of the property being accessed
     * @return mixed Returns either a QueryBuilder instance, connection object, or null
     */
    public function __get($name)
    {
        return match ($name) {
            'bulider', 'build', 'sql', 'query' => $this->smartConnection->createQueryBuilder(),
            'connect' => $this->smartConnection,
            default => null
        };
    }

    /**
     * Creates a new database connection instance from application configuration
     * 
     * This public static method serves as a factory method for creating database connection
     * instances. It takes a configuration array and uses it to establish a connection
     * with the appropriate database driver, then boots the connection with the provided config.
     * 
     * @param array $config The application configuration containing database settings
     * @return static Returns a new instance of the current class with the configuration applied
     */
    public static function fromAppConfig(array $config): static
    {
        $params = $config;

        $params['wrapperClass'] = static::class;

        $dbalConfig = new Configuration();

        /** @var static $connection */
        $connection = DriverManager::getConnection($params, $dbalConfig);

        return $connection->boot($config);
    }
    /**
     * Initializes the database connection with application configuration
     * 
     * This public method bootstraps the database connection by:
     * - Storing the application configuration for future reference
     * - Setting up table prefix from config or defaulting to empty string
     * - Configuring auto-translation of types based on config or defaulting to true
     * - Merging any type mappings from configuration with existing type maps
     * 
     * @param array $appConfig The application configuration to use for initialization
     * @return static Returns $this to allow for method chaining
     */
    public function boot(array $appConfig): static
    {
        $this->appConfig = $appConfig;

        $this->tablePrefix = $appConfig['table_prefix'] ?? '';
        $this->autoTranslateTypes = $appConfig['auto_translate_types'] ?? true;

        $this->typeMap = [...$this->typeMap, ...$appConfig['type_map'] ?? []];

        return $this;
    }

    /**
     * Retrieves the current application configuration
     * 
     * This public method returns the stored application configuration array that was used
     * to initialize the database connection. It provides access to all configuration values
     * that were set during bootstrapping.
     * 
     * @return array Returns the current application configuration
     */
    public function getAppConfig(): array
    {
        return $this->appConfig;
    }

    /**
     * Retrieves the current table prefix value
     * 
     * This public method returns the table prefix that is currently configured for this connection.
     * The table prefix is used to namespace tables in the database and prevent naming conflicts.
     * 
     * @return string Returns the current table prefix as a string
     */
    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Sets a new table prefix value and returns the instance for method chaining
     * 
     * This public method allows for dynamically changing the table prefix after object initialization.
     * It's useful when you need to switch between different database schemas or namespaces.
     * 
     * @param string $prefix The new table prefix to use
     * @return static Returns $this to allow for method chaining
     */
    public function setTablePrefix(string $prefix): static
    {
        $this->tablePrefix = $prefix;
        return $this;
    }

    /**
     * Enables or disables automatic type translation for database operations
     * 
     * This public method controls whether the system should automatically translate types
     * when performing database operations. This is particularly useful for handling
     * different data type mappings between databases.
     * 
     * @param bool $enabled Whether to enable (true) or disable (false) auto-type translation
     * @return static Returns $this to allow for method chaining
     */
    public function enableAutoTypeTranslation(bool $enabled = true): static
    {
        $this->autoTranslateTypes = $enabled;
        return $this;
    }

    /**
     * Checks if automatic type translation is currently enabled
     * 
     * This public method returns the current state of the auto-type translation setting.
     * It's used internally by the system to determine whether to apply type conversions
     * during database operations.
     * 
     * @return bool Returns true if auto-type translation is enabled, false otherwise
     */
    public function isAutoTypeTranslationEnabled(): bool
    {
        return $this->autoTranslateTypes;
    }

    /**
     * Returns a new instance with overridden DB params.
     * Creates a new instance with overridden database configuration parameters
     * 
     * This public method allows for temporary switching of database configurations
     * by creating a new instance with modified parameters while preserving the
     * original instance. It's particularly useful for:
     * - Testing database connections with different credentials
     * - Switching between multiple databases dynamically
     * - Applying environment-specific overrides
     * - Creating temporary configuration changes
     * 
     * The method works by:
     * 1. Retrieving the current application configuration
     * 2. Merging it with override parameters using array_replace_recursive
     * 3. Creating a new instance with the merged configuration
     * 4. Returning the newly created instance with the updated configuration
     * 
     * @param array $overrideParams An associative array of configuration parameters to override
     * @return static Returns a new instance of the current class with the merged configuration
     */
    public function switchDatabase(array $overrideParams): static
    {
        $config = $this->appConfig;

        $currentParams = [];

        $merged = array_replace_recursive($currentParams, $overrideParams);
        $config = $merged;

        return self::fromAppConfig($config);
    }

    /**
     * Generates a qualified table name with optional prefix handling
     * 
     * This public method takes a table name and appends the appropriate prefix based on
     * the current configuration. It ensures that table names are properly namespaced
     * and handles edge cases like empty prefixes or already-prefixed table names.
     * 
     * @param string $tableName The original table name
     * @param string|null $prefixOverride Optional custom prefix to use instead of default
     * @return string Returns the fully qualified table name with prefix
     */
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

    /**
     * Creates an associative array with table name and alias for use in queries
     * 
     * This public method generates a standardized array containing both the processed
     * table name (with proper prefixing) and an alias. It's particularly useful when
     * building complex queries that require both table references and aliases.
     * 
     * @param string $tableName The original table name to be aliased
     * @param string $alias The alias to associate with the table
     * @param string|null $prefixOverride Optional custom prefix to use instead of default
     * @return array Returns an associative array with 'table' and 'alias' keys
     */
    public function tableAlias(string $tableName, string $alias, ?string $prefixOverride = null): array
    {
        return [
            'table' => $this->table($tableName, $prefixOverride),
            'alias' => $alias,
        ];
    }

    /**
     * Creates and returns a new QueryBuilder instance
     * 
     * This public method provides a convenient way to get a configured QueryBuilder
     * instance for building database queries. It leverages the existing connection
     * configuration and ensures proper query building capabilities.
     * 
     * @return QueryBuilder Returns a new QueryBuilder instance
     */
    public function qb(): QueryBuilder
    {
        return $this->smartConnection->createQueryBuilder();
    }

    /**
     * Prepares a SELECT query builder for a specific table
     * 
     * This public method creates a SELECT query builder configured with the specified
     * table, columns, and optional alias. It's designed to simplify the most common
     * database read operations by providing a standardized way to start SELECT queries.
     * 
     * @param string $table The table name to select from
     * @param array|string $columns The column(s) to select (default: all columns)
     * @param string|null $alias Optional alias for the table
     * @param string|null $prefixOverride Optional custom prefix to use instead of default
     * @return QueryBuilder Returns a configured QueryBuilder instance
     */
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

    /**
     * Prepares an INSERT query builder for a specific table
     * 
     * This public method creates an INSERT query builder configured with the specified
     * table name. It's designed to simplify the process of building INSERT statements
     * by setting up the proper table context and returning a ready-to-use QueryBuilder.
     * 
     * @param string $table The table name to insert into
     * @param string|null $prefixOverride Optional custom prefix to use instead of default
     * @return QueryBuilder Returns a configured QueryBuilder instance for INSERT operations
     */
    public function insertInto(string $table, ?string $prefixOverride = null): QueryBuilder
    {
        $gb = $this->qb();
        $gb->insert($this->table($table, $prefixOverride));

        return $gb;
    }

    /**
     * Prepares an UPDATE query builder for a specific table
     * 
     * This public method creates an UPDATE query builder configured with the specified
     * table name. It's designed to simplify the process of building UPDATE statements
     * by setting up the proper table context and returning a ready-to-use QueryBuilder.
     * 
     * @param string $table The table name to update
     * @param string|null $prefixOverride Optional custom prefix to use instead of default
     * @return QueryBuilder Returns a configured QueryBuilder instance for UPDATE operations
     */
    public function updateTable(
        string $table,
        ?string $prefixOverride = null
    ): QueryBuilder {
        $gb = $this->qb();
        $gb->update($this->table($table, $prefixOverride));

        return $gb;
    }

    /**
     * Prepares a DELETE query builder for a specific table
     * 
     * This public method creates a DELETE query builder configured with the specified
     * table name. It's designed to simplify the process of building DELETE statements
     * by setting up the proper table context and returning a ready-to-use QueryBuilder.
     * 
     * @param string $table The table name to delete from
     * @param string|null $prefixOverride Optional custom prefix to use instead of default
     * @return QueryBuilder Returns a configured QueryBuilder instance for DELETE operations
     */
    public function deleteFrom(
        string $table,
        ?string $prefixOverride = null
    ): QueryBuilder {
        $gb = $this->qb();
        $gb->delete($this->table($table, $prefixOverride));

        return $gb;
    }

    /**
     * Executes a callback within a database transaction context
     * 
     * This public method provides a convenient way to execute database operations
     * within a transactional context. It delegates the actual transaction handling
     * to the underlying transactional method, providing a clean interface for
     * executing multiple database operations that should either all succeed or all fail.
     * 
     * @param callable $callback The callback function to execute within the transaction
     * @return mixed Returns the result of the callback execution
     */
    public function transactionalFast(callable $callback): mixed
    {
        return $this->transactional($callback);
    }

    /**
     * Converts a value from its PHP representation to its database representation
     * 
     * This public method handles the conversion of values between PHP space and database space.
     * It's particularly useful when working with custom data types where special conversion logic
     * is required. The method checks if the type exists in Doctrine's Type system before attempting
     * conversion.
     * 
     * @param mixed $value The value to be converted
     * @param string $type The database type to convert to
     * @return mixed Returns the converted value or original value if type doesn't exist
     */
    public function convertToDatabaseValue(mixed $value, string $type): mixed
    {
        if (!Type::hasType($type)) {
            return $value;
        }

        return Type::getType($type)->convertToDatabaseValue($value, $this->getDatabasePlatform());
    }

    /**
     * Converts a value from its database representation to its PHP representation
     * 
     * This public method handles the conversion of values from the database back to PHP space.
     * It's used when retrieving data from the database and needs to be properly typed or formatted
     * according to the application's requirements. The method validates the existence of the type
     * before attempting conversion.
     * 
     * @param mixed $value The value to be converted from database format
     * @param string $type The PHP type to convert from
     * @return mixed Returns the converted value or original value if type doesn't exist
     */
    public function convertToPhpValue(mixed $value, string $type): mixed
    {
        if (!Type::hasType($type)) {
            return $value;
        }

        return Type::getType($type)->convertToPHPValue($value, $this->getDatabasePlatform());
    }

    /**
     * Translates database row values to their PHP representation based on expected result types
     * 
     * This public method processes a row of data by converting each column's value from its
     * database representation to the appropriate PHP type as specified in the resultTypes mapping.
     * It's particularly useful when working with custom result sets where columns may need
     * special handling based on their expected PHP types.
     * 
     * @param array $row The row of data to be translated
     * @param array $resultTypes An array mapping column names to their expected PHP types
     * @return array Returns the translated row with properly typed values
     */
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

    /**
     * Registers a mapping between a PHP class or scalar type and a database abstraction layer type
     * 
     * This public method allows for custom type mappings to be defined, enabling the system to
     * automatically handle conversion between specific PHP types and their database representations.
     * It's particularly useful when working with custom data types or when extending existing
     * type systems with new mapping capabilities.
     * 
     * @param string $phpClassOrScalar The PHP class name or scalar type to map
     * @param string $dbalType The database abstraction layer type name
     * @return static Returns self for method chaining
     */
    public function registerPhpTypeMap(string $phpClassOrScalar, string $dbalType): static
    {
        $this->typeMap[$phpClassOrScalar] = $dbalType;
        return $this;
    }

    /**
     * Quotes a single identifier for use in SQL queries
     * 
     * This public method properly escapes and quotes an identifier (such as table names,
     * column names, or database names) to ensure it's safe for use in SQL statements.
     * It's part of the database abstraction layer's identifier handling capabilities.
     * 
     * @param string $identifier The identifier to be quoted
     * @return string Returns the properly quoted identifier
     */
    public function quoteIdentifier(string $identifier): string
    {
        return $this->quoteSingleIdentifier($identifier);
    }

    /**
     * Creates a Database instance from an existing PDO connection
     * 
     * This public static method creates and configures a Database instance using an existing
     * PDO connection object. It detects the appropriate database driver based on the PDO's
     * attribute settings and sets up the connection properly.
     * 
     * @param \PDO $pdo The existing PDO connection to use
     * @return Database Returns a new Database instance configured with the provided PDO
     * @throws \Exception If an unsupported database driver is detected
     */
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

    /**
     * Converts an array of attributes into a bind parameters array with optional comparison prefix
     * 
     * This public method takes an array of attribute names and creates a new associative array
     * where each key is an attribute name and each value is a string in the format:
     * "$comparison:{attribute_name}" (e.g., " = :username").
     * 
     * @param array $attributes Array of attribute names to be converted
     * @param string $comparison Optional comparison operator to prefix (default: "")
     * @return array Returns an associative array with attributes as keys and formatted bind strings as values
     */
    public function attributesToBindsProperty(array $attributes, string $comparison = ""): array
    {
        $binds = [];
        foreach ($attributes as $key => $attribute) {
            $binds[$attribute] = $comparison . ":$key";
        }

        return $binds;
    }

    /**
     * Creates a comparison-based bind parameters array for SQL WHERE clauses
     * 
     * This method generates bind parameters specifically for equality comparisons by:
     * 1. First calling attributesToBindsProperty to get the basic bind structure
     * 2. Then modifying each value to include the attribute name as a prefix
     * 
     * This is useful for building WHERE clauses where you need both the column reference
     * and parameter placeholder (e.g., "users.id = :id").
     * 
     * @param array $attributes Array of attribute names to be converted
     * @return array Returns an associative array with modified bind strings
     */
    public function attributesToBindsComparisonsProperty(array $attributes): array
    {
        foreach (self::attributesToBindsProperty($attributes, ' = ') as $key => $value) {
            $attributes[$key] = ($key . $value);
        }

        return $attributes;
    }

    /**
     * Creates a comparison-based bind parameters array for "other" or "otherwise" conditions
     * 
     * This method generates bind parameters that combine attribute names with their comparison values
     * without any additional prefixes. It's designed to work with OR clauses in WHERE conditions.
     * 
     * @param array $attributes Array of attribute names to be processed
     * @return array Returns an associative array where keys and values are combined
     */
    public function attributesToBindsOtherwisesProperty(array $attributes): array
    {
        foreach (self::attributesToBindsProperty($attributes, ' = ') as $key => $value) {
            $attributes[$key] = $key . $value;
        }

        return $attributes;
    }

    
    /**
     * Builds WHERE clauses in a QueryBuilder using provided attributes and their comparisons
     * 
     * This method takes an existing QueryBuilder instance and appends WHERE conditions to it,
     * handling the first condition as a simple WHERE and subsequent ones as AND conditions.
     * It's designed to build complex WHERE clauses programmatically from attribute arrays.
     * 
     * @param QueryBuilder $qb The QueryBuilder instance to modify
     * @param array $attributes Array of attributes to use in WHERE conditions
     * @return QueryBuilder Returns the modified QueryBuilder instance for chaining
     */
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
    /**
     * Builds OR conditions in a QueryBuilder using provided attributes
     * 
     * This method takes an existing QueryBuilder instance and appends OR conditions to it,
     * handling the first condition as an OR clause and subsequent ones as additional OR conditions.
     * It's designed for building flexible WHERE clauses with OR logic.
     * 
     * @param QueryBuilder $qb The QueryBuilder instance to modify
     * @param array $attributes Array of attributes to use in OR conditions
     * @return QueryBuilder Returns the modified QueryBuilder instance for chaining
     */
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

    /**
     * Binds an array of property names to their corresponding values by creating a new array
     * with property names as keys and provided values as values.
     * 
     * This method takes a keyed array of properties and an array of values, then combines them
     * into a new associative array where each property name is paired with its corresponding value
     * based on their position in the original arrays. It's commonly used for mapping data to
     * class properties or preparing data for database operations.
     * 
     * @param array $bindProperty Associative array of property names and their values
     * @param array $values Array of values to be bound to properties
     * @return array Returns a new associative array with property names as keys and values as values
     */
    public function bindValuesToProperty(array $bindProperty, array $values): array
    {
        return \array_combine(\array_values(\array_keys($bindProperty)), $values);
    }

    /**
     * Normalizes an array of attributes by either returning the attribute value directly
     * or retrieving it from the original attributes array if it's an integer key.
     * 
     * This method processes an array of attributes and handles two cases:
     * 1. When the attribute is not an integer, returns it as-is
     * 2. When the attribute is an integer, treats it as an index and retrieves the corresponding value
     *    from the original attributes array
     * 
     * This pattern is useful when working with mixed arrays where some elements are indices
     * and others are actual attribute names, allowing for flexible attribute handling.
     * 
     * @param array $attributes The original array of attributes to reference
     * @return array Returns a new array with normalized attributes
     */
    public function normalizeAttributes(array $attributes): array
    {
        return array_map(fn($attr) => \is_int($attr) ? $attributes[$attr] : $attr, array_keys($attributes));
    }

    /**
     * Maps column definitions from type specifications by enhancing SQL-style column definitions
     * 
     * This public method takes attribute definitions and enhances them with SQL-specific formatting
     * by prefixing certain column types with "sql_" and applying proper SQL syntax. It's designed
     * to process attributes that have SQL column type specifications and format them accordingly.
     * 
     * The method:
     * 1. Filters the attributesTypes array to find entries that start with 'sql_' prefix
     * 2. For each matching attribute, it processes the value to remove the 'sql_' prefix
     * 3. Converts the remaining part to uppercase
     * 4. Wraps the original attribute value in parentheses
     * 5. Appends an alias using the original attribute name
     * 
     * This is particularly useful for database schema generation where you need to format
     * column definitions with proper SQL syntax and aliases.
     * 
     * @param array $attributes Associative array of attribute names and their values
     * @param array $attributesTypes Array of attribute type definitions that may contain SQL prefixes
     * @return array Returns the modified attributes array with enhanced SQL column definitions
     */
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

    /**
     * Prioritizes and extracts specific elements from arrays of type definitions
     * 
     * This public static method processes an array of attribute types and extracts either
     * the first element or second element (if available) from each array value, depending
     * on the getEnd parameter. It's designed to handle nested arrays of type information
     * and extract the most relevant type specification.
     * 
     * The method is particularly useful in scenarios where multiple type options exist for
     * a property and you need to select the appropriate one based the $getEnd flag:
     * - When $getEnd is false (default): Returns the first element of each array (index 0)
     * - When $getEnd is true: Returns the second element of each array (index 1), if it exists
     * 
     * @param array $attributesTypes Array of attribute type definitions (can contain nested arrays)
     * @param bool $getEnd If true, returns the second element of arrays; if false, returns the first element
     * @return array Returns a new array with processed type information
     */
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

    /**
     * Filters out attributes and their types that are marked for ignoring
     * 
     * This public static method takes two arrays - one containing attribute names and their values,
     * and another containing corresponding type information. It filters out any attributes that have
     * "ignore" in their type definition or are null, returning a merged array of the filtered results.
     * 
     * The method performs two main filtering operations:
     * 1. Filters the $attributesTypes array to exclude types containing "ignore" or being null
     * 2. Filters the $attributes array to exclude keys where the corresponding type is ignored or null
     * 
     * This is particularly useful in data mapping scenarios where certain fields should be excluded
     * from processing based on their ignore flags, such as when generating database queries or API responses.
     * 
     * @param array $attributes Associative array of attribute names and their values
     * @param array $attributesTypes Array mapping attribute names to their type definitions
     * @return array Returns a merged array with ignored types filtered out
     */
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
