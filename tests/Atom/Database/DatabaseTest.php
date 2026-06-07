<?php

declare(strict_types=1);

namespace Tests\Atom\DataBase;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver\PDOConnection;
use Atom\DataBase\Database;

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
            ->setMethods(['appConfig', 'getDatabasePlatform', 'transactional', 'quoteSingleIdentifier'])
            ->getMock();
    }

    public function test_switchDatabase_returns_new_instance_with_merged_config()
    {
        // Create a mock config
        $config = [
            'driver' => 'pdo_mysql',
            'host' => 'localhost',
            'dbname' => 'testdb'
        ];
        
        // Set up the mock for appConfig property
        $this->database->method('appConfig')
            ->willReturn($config);
            
        // Mock the database platform to avoid calling actual DB
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        $this->database->method('getDatabasePlatform')
            ->willReturn($platform);
        
        // Test the method
        $result = $this->database->switchDatabase(['host' => 'newhost']);
        
        // Assertions
        $this->assertInstanceOf(Database::class, $result);
    }

    public function test_table_with_empty_prefix_returns_original_name()
    {
        $this->database->tablePrefix = '';
        
        $result = $this->database->table('users');
        $this->assertEquals('users', $result);
    }

    public function test_table_with_existing_prefix_returns_qualified_name()
    {
        $this->database->tablePrefix = 'prefix_';
        
        $result = $this->database->table('users');
        $this->assertEquals('prefix_users', $result);
    }

    public function test_table_with_override_prefix_uses_custom_prefix()
    {
        $this->database->tablePrefix = 'default_';
        
        $result = $this->database->table('users', 'custom_');
        $this->assertEquals('custom_users', $result);
    }

    public function test_table_with_existing_prefix_already_prefixed_returns_original()
    {
        $this->database->tablePrefix = 'prefix_';
        
        $result = $this->database->table('prefix_users');
        $this->assertEquals('prefix_users', $result);
    }

    public function test_tableAlias_returns_correct_array()
    {
        $result = $this->database->tableAlias('users', 'u');
        
        $expected = [
            'table' => 'users',
            'alias' => 'u'
        ];
        
        $this->assertEquals($expected, $result);
    }

    public function test_tableAlias_with_prefix_override()
    {
        $this->database->method('table')
            ->will($this->returnCallback(function ($table, $prefixOverride) {
                return $prefixOverride ? $prefixOverride . $table : $table;
            }));
            
        $result = $this->database->tableAlias('users', 'u', 'test_');
        
        $this->assertEquals([
            'table' => 'test_users',
            'alias' => 'u'
        ], $result);
    }

    public function test_transactionalFast_calls_transactional()
    {
        $callback = function() { return 'success'; };
        
        $this->database->expects($this->once())
            ->method('transactional')
            ->with($callback)
            ->willReturn('success');
            
        $result = $this->database->transactionalFast($callback);
        $this->assertEquals('success', $result);
    }

    public function test_convertToDatabaseValue_with_valid_type()
    {
        // Create a mock type
        $mockType = $this->createMock(\Doctrine\DBAL\Types\Type::class);
        $mockType->expects($this->once())
            ->method('convertToDatabaseValue')
            ->willReturn('converted_value');
            
        // Mock hasType to return true
        \Doctrine\DBAL\Types\Type::staticExpects($this->any())
            ->method('hasType')
            ->willReturn(true);
            
        // Mock getting the type
        \Doctrine\DBAL\Types\Type::staticExpects($this->any())
            ->method('getType')
            ->willReturn($mockType);
            
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        
        // Set up the database platform on the mock
        $this->database->method('getDatabasePlatform')
            ->willReturn($platform);
            
        $result = $this->database->convertToDatabaseValue('test', 'string');
        $this->assertEquals('converted_value', $result);
    }

    public function test_convertToPhpValue_with_valid_type()
    {
        // Create a mock type
        $mockType = $this->createMock(\Doctrine\DBAL\Types\Type::class);
        $mockType->expects($this->once())
            ->method('convertToPHPValue')
            ->willReturn('converted_php_value');
            
        // Mock hasType to return true
        \Doctrine\DBAL\Types\Type::staticExpects($this->any())
            ->method('hasType')
            ->willReturn(true);
            
        // Mock getting the type
        \Doctrine\DBAL\Types\Type::staticExpects($this->any())
            ->method('getType')
            ->willReturn($mockType);
            
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        
        // Set up the database platform on the mock
        $this->database->method('getDatabasePlatform')
            ->willReturn($platform);
            
        $result = $this->database->convertToPhpValue('test', 'string');
        $this->assertEquals('converted_php_value', $result);
    }

    public function test_translateRowToPhp_processes_all_columns()
    {
        // Mock type conversion
        \Doctrine\DBAL\Types\Type::staticExpects($this->any())
            ->method('hasType')
            ->willReturn(true);
            
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        $this->database->method('getDatabasePlatform')
            ->willReturn($platform);
        
        // Mock the type conversion
        \Doctrine\DBAL\Types\Type::staticExpects($this->any())
            ->method('getType')
            ->willReturn(new \Doctrine\DBAL\Types\StringType());
            
        $row = [
            'id' => 1,
            'name' => 'John'
        ];
        
        $resultTypes = [
            'id' => 'integer',
            'name' => 'string'
        ];
        
        $result = $this->database->translateRowToPhp($row, $resultTypes);
        $this->assertEquals($row, $result);
    }

    public function test_quoteIdentifier_uses_quoteSingleIdentifier()
    {
        $this->database->expects($this->once())
            ->method('quoteSingleIdentifier')
            ->with('test_identifier')
            ->willReturn('`test_identifier`'); // MySQL style quoting
            
        $result = $this->database->quoteIdentifier('test_identifier');
        $this->assertEquals('`test_identifier`', $result);
    }

    public function test_propertyToColumnName_with_underscore_separated()
    {
        $properties = ['userName' => 'John', 'emailAddress' => 'john@example.com'];
        $result = $this->database->propertyToColumnName($properties);
        
        // Should convert to underscore format
        $expected = ['user_name' => 'John', 'email_address' => 'john@example.com'];
        $this->assertEquals($expected, $result);
    }

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

    public function test_resolveKeys_with_string_keys()
    {
        $input = ['cosMod' => 'table', 'obdS'];
        $result = $this->database->resolveKeys($input);
        
        // Should return the key as identifier
        $this->assertEquals(['cosMod' => 'table', 'obdS' => 'obdS'], $result);
    }

    public function test_attributesToBindsProperty_creates_correct_array()
    {
        $attributes = ['name', 'email', 'age'];
        $result = $this->database->attributesToBindsProperty($attributes, '');
        
        $expected = [
            'name' => ' = :name',
            'email' => ' = :email',
            'age' => ' = :age'
        ];
        
        $this->assertEquals($expected, $result);
    }

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

    public function test_fromExistingPdo_creates_instance()
    {
        // This would require a more complex mock setup
        // For now, just test the basic method structure
        
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')
            ->willReturn('mysql'); // Mock MySQL driver
            
        $this->expectException(Exception::class);
        Database::fromExistingPdo($pdo); // Will throw exception for unsupported PDO
    }
}
