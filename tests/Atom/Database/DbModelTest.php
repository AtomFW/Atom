<?php

declare(strict_types=1);

namespace Tests\Atom\DataBase;

use PHPUnit\Framework\TestCase;
use Atom\DataBase\DbModel;

/**
 * Test suite for the Model component.
 */
class TestModel extends DbModel
{
    public string $id;
    public string $name;

    public static function tableName(): string { return 'test_table'; }
    public static function attributesTypes(): array { return ['id' => 'int', 'name' => 'string']; }
}

final class DbModelTest extends TestCase
{
    /**
     * Test table name is correct.
     */
    public function test_tableName_is_correct()
    {
        $this->assertEquals('test_table', TestModel::tableName());
    }

    /**
     * Test attributes types are correct.
     */
    public function test_attributesTypes_are_correct()
    {
        $expected = ['id' => 'int', 'name' => 'string'];
        $this->assertEquals($expected, TestModel::attributesTypes());
    }

    /**
     * Test primary key defaults to id.
     */
    public function test_primaryKey_defaults_to_id()
    {
        $this->assertEquals('id', TestModel::primaryKey());
    }
}
