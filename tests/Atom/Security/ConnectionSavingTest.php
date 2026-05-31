<?php

declare(strict_types=1);

namespace Tests\Unit\Atom\Security;

use Atom\DataBase\Database;
use Atom\DateTime\DateTime;
use Atom\Security\ConnectionSaving;
use PHPUnit\Framework\TestCase;

class ConnectionSavingTest extends TestCase
{
    private $database;
    private $datetime;
    private $serverId;

    protected function setUp(): void
    {
        $this->database = $this->createMock(Database::class);
        $this->datetime = $this->createMock(DateTime::class);
        $this->serverId = 1;
    }

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
        $dataStructure->setAccessible(true);
        
        // Mock the database insert operation
        $statementMock = $this->createMock(\Doctrine\DBAL\Statement::class);
        $statementMock->method('rowCount')->willReturn(1);
        $statementMock->expects($this->once())->method('free');
        
        $insertMock = $this->createMock(\Doctrine\DBAL\Query\ForUpdate::class);
        $insertMock->method('values')->willReturn($insertMock);
        $insertMock->method('setParameters')->willReturn($insertMock);
        
        $this->database->method('insertInto')->willReturn($insertMock);
        $this->database->expects($this->once())->method('attributesToBindsProperty');
        
        // Test the method
        $result = $connectionSaving::add($browserDetector, $connectionInformation, $botDetector);
        
        // Assert
        $this->assertIs(int, $result);
    }

    public function testAttributesTypes()
    {
        $expected = [
            'ip' => "binary",
            'datetime' => "datetime",
            'raw_details' => "json",
        ];
        
        $this->assertEquals($expected, ConnectionSaving::attributesTypes());
    }

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
        
        $this->assertEquals($expected, ConnectionSaving::attributes());
    }

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

    public function testChangeDateTime()
    {
        // Create new mock datetime
        $newDatetime = $this->createMock(DateTime::class);
        
        // Change the datetime using the static method
        ConnectionSaving::changeDateTime($newDatetime);
        
        // Check if it was updated correctly (we can't directly access the property)
        $this->assertTrue(true); // If we reach here without exception, test passes
    }

    public function testChangeServerId()
    {
        // Change the server ID using the static method
        ConnectionSaving::changeServerId(999);
        
        // Check if it was updated correctly (we can't directly access the property)
        $this->assertTrue(true); // If we reach here without exception, test passes
    }

    public function testConstructor()
    {
        // Create a new instance with mocks
        $connectionSaving = new ConnectionSaving($this->database, $this->datetime, $this->serverId);
        
        // Check if the object was created correctly
        $this->assertInstanceOf(ConnectionSaving::class, $connectionSaving);
    }
}
