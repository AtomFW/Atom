<?php

declare(strict_types=1);

namespace Tests\Atom\DataBase;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver\PDOConnection;
use Atom\DataBase\Database;

/**
 * Test suite for the Database component.
 */
class DatabaseTest extends TestCase
{
    private $pdo;
    private $connection;
    private $database;

    protected function setUp(): void
    {
        // Create a mock PDO connection for testing
        $this->pdo = $this->createMock(\PDO::class);
        $this->connection = $this->createMock(Connection::class);
        $this->database = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()->onlyMethods(['getDatabasePlatform', 'transactional', 'quoteSingleIdentifier'])
            ->getMock();
    }

    /**
     * Test switch database returns new instance with merged config.
     */
    public function test_switchDatabase_returns_new_instance_with_merged_config()
    {
        $config = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ];
        
        $db = Database::fromAppConfig($config);
        $result = $db->switchDatabase(['driver' => 'pdo_sqlite', 'memory' => false, 'path' => ':memory:']);
        
        $this->assertInstanceOf(Database::class, $result);
    }

    /**
     * Test table with empty prefix returns original name.
     */
    public function test_table_with_empty_prefix_returns_original_name()
    {
        $this->database->tablePrefix = '';
        
        $result = $this->database->table('users');
        $this->assertEquals('users', $result);
    }

    /**
     * Test table with existing prefix returns qualified name.
     */
    public function test_table_with_existing_prefix_returns_qualified_name()
    {
        $this->database->setTablePrefix('prefix_');
        
        $result = $this->database->table('users');
        $this->assertEquals('prefix_users', $result);
    }

    /**
     * Test table with override prefix uses custom prefix.
     */
    public function test_table_with_override_prefix_uses_custom_prefix()
    {
        $this->database->setTablePrefix('default_');
        
        $result = $this->database->table('users', 'custom_');
        $this->assertEquals('custom_users', $result);
    }

    /**
     * Test table with existing prefix already prefixed returns original.
     */
    public function test_table_with_existing_prefix_already_prefixed_returns_original()
    {
        $this->database->setTablePrefix('prefix_');
        
        $result = $this->database->table('prefix_users');
        $this->assertEquals('prefix_users', $result);
    }

    /**
     * Test table alias returns correct array.
     */
    public function test_tableAlias_returns_correct_array()
    {
        $result = $this->database->tableAlias('users', 'u');
        
        $expected = [
            'table' => 'users',
            'alias' => 'u'
        ];
        
        $this->assertEquals($expected, $result);
    }

    /**
     * Test table alias with prefix override.
     */
    public function test_tableAlias_with_prefix_override()
    {
        $result = $this->database->tableAlias('users', 'u', 'test_');
        $this->assertSame(['table' => 'test_users', 'alias' => 'u'], $result);
    }

    /**
     * Test transactional fast calls transactional.
     */
    public function test_transactionalFast_calls_transactional()
    {
        $callback = function() { return 'success'; };
        $this->database->expects($this->once())->method('transactional')->with($callback)->willReturn('success');
        $this->assertSame('success', $this->database->transactionalFast($callback));
    }

    /**
     * Test convert to database value with valid type.
     */
    public function test_convertToDatabaseValue_with_valid_type()
    {
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        $this->database->method('getDatabasePlatform')->willReturn($platform);
            
        $result = $this->database->convertToDatabaseValue('test', 'string');
        $this->assertEquals('test', $result);
    }

    /**
     * Test convert to php value with valid type.
     */
    public function test_convertToPhpValue_with_valid_type()
    {
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        $this->database->method('getDatabasePlatform')->willReturn($platform);
            
        $result = $this->database->convertToPhpValue('test', 'string');
        $this->assertEquals('test', $result);
    }

    /**
     * Test translate row to php processes all columns.
     */
    public function test_translateRowToPhp_processes_all_columns()
    {
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        $this->database->method('getDatabasePlatform')->willReturn($platform);
        
        $row = [
            'id' => '1',
            'name' => 'John'
        ];
        
        $resultTypes = [
            'id' => 'integer',
            'name' => 'string'
        ];
        
        $result = $this->database->translateRowToPhp($row, $resultTypes);
        $this->assertSame(1, $result['id']);
        $this->assertSame('John', $result['name']);
    }

    /**
     * Test quote identifier uses quote single identifier.
     */
    public function test_quoteIdentifier_uses_quoteSingleIdentifier()
    {
        $this->database->expects($this->once())
            ->method('quoteSingleIdentifier')
            ->with('test_identifier')
            ->willReturn('`test_identifier`'); // MySQL style quoting
            
        $result = $this->database->quoteIdentifier('test_identifier');
        $this->assertEquals('`test_identifier`', $result);
    }

    /**
     * Test property to column name with underscore separated.
     */
    public function test_propertyToColumnName_with_underscore_separated()
    {
        $properties = ['userName' => 'John', 'emailAddress' => 'john@example.com'];
        $result = $this->database->propertyToColumnName($properties);
        
        // Should convert to underscore format
        $expected = ['userName' => 'john', 'emailAddress' => 'john@example.com'];
        $this->assertEquals($expected, $result);
    }

    /**
     * Test column name to property with underscored column.
     */
    public function test_columnNameToProperty_with_underscored_column()
    {
        $columns = [
            'user_name' => 'John',
            'email_address' => 'john@example.com'
        ];
        
        $result = $this->database->columnNameToProperty($columns);
        
        // Should convert to camelCase
        $expected = [
            'userName' => 'John',
            'emailAddress' => 'john@example.com'
        ];
        
        $this->assertEquals($expected, $result);
    }

    /**
     * Test resolve keys with string keys.
     */
    public function test_resolveKeys_with_string_keys()
    {
        $input = ['cosMod' => 'table', 'obdS'];
        $result = $this->database->resolveKeys($input);
        
        // Should return the key as identifier
        $this->assertEquals(['cosMod' => 'table', 'obdS' => 'obdS'], $result);
    }

    /**
     * Test attributes to binds property creates correct array.
     */
    public function test_attributesToBindsProperty_creates_correct_array()
    {
        $attributes = ['name', 'email', 'age'];
        $result = $this->database->attributesToBindsProperty($attributes, '');
        
        $expected = [
            'name' => ':0',
            'email' => ':1',
            'age' => ':2'
        ];
        
        $this->assertEquals($expected, $result);
    }

    /**
     * Test bind values to property creates correct array.
     */
    public function test_bindValuesToProperty_creates_correct_array()
    {
        $bindProperty = ['name' => null, 'email' => null];
        $values = ['John', 'john@example.com'];
        
        $result = $this->database->bindValuesToProperty($bindProperty, $values);
        
        $expected = [
            'name' => 'John',
            'email' => 'john@example.com'
        ];
        
        $this->assertEquals($expected, $result);
    }

    /**
     * Test prioritize the mopping array of types returns first elements.
     */
    public function test_prioritizeTheMoppingArrayOfTypes_returns_first_elements()
    {
        $attributesTypes = [
            ['string', 'VARCHAR(255)'],
            ['integer', 1],
            'boolean'
        ];
        
        $result = Database::prioritizeTheMoppingArrayOfTypes($attributesTypes, false);
        $this->assertEquals(['string', 'integer', 'boolean'], $result);
    }

    /**
     * Test filter ignored types filters out ignored types.
     */
    public function test_filterIgnoredTypes_filters_out_ignored_types()
    {
        $attributes = [
            'name' => 'John',
            'email' => 'john@example.com',
            'ignore_me' => 'should_be_filtered'
        ];
        
        $attributeTypes = [
            'name' => 'string',
            'email' => 'string',
            'ignore_me' => 'ignore:all' // This should be filtered out
        ];
        
        $result = Database::filterIgnoredTypes($attributes, $attributeTypes);
        
        // Should filter out ignored type and return only valid attributes
        $this->assertArrayNotHasKey('ignore_me', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('email', $result);
    }

    /**
     * Test from existing pdo creates instance.
     */
    public function test_fromExistingPdo_creates_instance()
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')
            ->willReturn('mysql'); // Mock MySQL driver
            
        $instance = Database::fromExistingPdo($pdo);
        $this->assertInstanceOf(Database::class, $instance);
    }
}
