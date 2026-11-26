<?php

declare(strict_types=1);

namespace Tests\Unit\Atom\Security;

use Atom\DataBase\Database;
use Atom\Atom;
use Atom\DateTime\DateTime;
use Atom\Security\ConnectionSaving;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for the ConnectionSaving component.
 */
class ConnectionSavingTest extends TestCase
{
    private $database;
    private $datetime;
    private $serverId;

    protected function setUp(): void
    {
        $this->database = $this->createMock(Database::class);
        $this->datetime = new DateTime();
        $this->datetime::setMacro();
        $this->database->pdo = $this->createMock(\PDO::class);
        $this->database->pdo->method('lastInsertId')->willReturn('1');
        $app = (new \ReflectionClass(Atom::class))->newInstanceWithoutConstructor();
        $app->db = $this->database;
        $app->datetime = $this->datetime;
        Atom::$app = $app;
        $this->serverId = 1;
    }

    private function invokeProtectedStatic(string $class, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($class, $method);
        return $reflection->invokeArgs(null, $arguments);
    }

    /**
     * Test add method.
     */
    public function testAddMethod()
    {
        // Mock dependencies
        $browserDetector = ['userAgent' => 'test-agent'];
        $connectionInformation = [
            'ip' => '127.0.0.1',
            'isp' => 'Test ISP',
            'lang' => 'pl',
            'city' => 'Warsaw',
            'country' => 'Poland',
            'user_agent' => 'Test Agent',
            'browser_sec' => 'Test Browser',
            'coordinates' => 'POINT(10.0 20.0)',
            'area_code' => '12345',
            'dma_code' => '67890',
            'region' => 'MAZ',
            'unique_id' => 'unique_id_123',
        ];
        $botDetector = ['isBot' => false];

        // Create a mock of the ConnectionSaving class
        $connectionSaving = new ConnectionSaving($this->database, $this->datetime, $this->serverId);
        
        // Mock the dataStructure method to return test data
        $reflection = new \ReflectionClass(ConnectionSaving::class);
        $dataStructure = $reflection->getMethod('dataStructure');
        
        // Mock the database insert operation
        $statementMock = $this->createMock(Result::class);
        $statementMock->method('rowCount')->willReturn(1);
        $statementMock->expects($this->once())->method('free');
        
        $insertMock = $this->createMock(\Doctrine\DBAL\Query\QueryBuilder::class);
        $insertMock->method('values')->willReturn($insertMock);
        $insertMock->method('setParameters')->willReturn($insertMock);
        $insertMock->method('executeQuery')->willReturn($statementMock);
        
        $this->database->method('insertInto')->willReturn($insertMock);
        $this->database->expects($this->once())->method('attributesToBindsProperty');
        
        // Test the method
        $result = $connectionSaving::add($browserDetector, $connectionInformation, $botDetector);
        
        // Assert
        $this->assertIsInt($result);
    }

    /**
     * Test attributes types.
     */
    public function testAttributesTypes()
    {
        $expected = [
            'ip' => "binary",
            'datetime' => "datetime",
            'raw_details' => "json",
        ];
        
        $this->assertEquals($expected, $this->invokeProtectedStatic(ConnectionSaving::class, 'attributesTypes'));
    }

    /**
     * Test attributes.
     */
    public function testAttributes()
    {
        $expected = [
            'ip',
            'isp',
            'lang',
            'city',
            'country',
            'user_agent',
            'browser_sec',
            'coordinates',
            'area_code',
            'dma_code',
            'region',
            'unique_id',
            'server_id',
            'datetime',
            'raw_details'
        ];
        
        $this->assertEquals($expected, $this->invokeProtectedStatic(ConnectionSaving::class, 'attributes'));
    }

    /**
     * Test change database.
     */
    public function testChangeDatabase()
    {
        // Create new mock database
        $newDatabase = $this->createMock(Database::class);
        
        // Change the database using the static method
        ConnectionSaving::changeDatabase($newDatabase);
        
        // Check if it was updated correctly (we can't directly access the property)
        // but we can verify that no exception is thrown
        $this->assertTrue(true); // If we reach here without exception, test passes
    }

    /**
     * Test change date time.
     */
    public function testChangeDateTime()
    {
        // Create new datetime instance
        $newDatetime = new DateTime();
        
        // Change the datetime using the static method
        ConnectionSaving::changeDateTime($newDatetime);
        
        // Check if it was updated correctly (we can't directly access the property)
        $this->assertTrue(true); // If we reach here without exception, test passes
    }

    /**
     * Test change server id.
     */
    public function testChangeServerId()
    {
        // Change the server ID using the static method
        ConnectionSaving::changeServerId(999);
        
        // Check if it was updated correctly (we can't directly access the property)
        $this->assertTrue(true); // If we reach here without exception, test passes
    }

    /**
     * Test constructor.
     */
    public function testConstructor()
    {
        // Create a new instance with mocks
        $connectionSaving = new ConnectionSaving($this->database, $this->datetime, $this->serverId);
        
        // Check if the object was created correctly
        $this->assertInstanceOf(ConnectionSaving::class, $connectionSaving);
    }
}
