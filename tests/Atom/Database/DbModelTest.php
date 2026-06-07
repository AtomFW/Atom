<?php

declare(strict_types=1);

namespace Tests\Atom\DataBase;

use PHPUnit\Framework\TestCase;

use Atom\Atom;
use Atom\DataBase\DbModel;

// Minimal stub classes to enable testing without a real DB
class DummyPdoStatement
{
    public function bindValue($param, $value) {}
    public function execute() { return true; }
    public function fetchObject($class = null) { return null; }
}

class DummyPdo
{
    public function prepare($sql) { return new DummyPdoStatement(); }
}

class DummyApp
{
    public $db;
    public function __construct() { $this->db = new DummyPdo(); }
}

class SafeFieldsModel extends DbModel
{
    public string $id;
    public string $name;
    public string $email;

    public static function tableName(): string { return 'users'; }

    public static function tableKeys(): array
    {
        return [
            'id' => 'id',
            'name' => 'name',
            'email' => 'email',
            // extra db columns not declared as properties
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
    }
}

final class DbModelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Set up minimal Atom app container with dummy PDO
        Atom::$app = new DummyApp();
    }

    public function test_getSafeSqlFields_filters_to_declared_properties_when_auto_verify_true(): void
    {
        $model = new SafeFieldsModel();
        $fields = $model->getSafeSqlFields(SafeFieldsModel::tableKeys(), true);
        $this->assertSame('id, name, email', $fields);
    }

    public function test_getSafeSqlFields_includes_all_table_keys_when_auto_verify_false(): void
    {
        $model = new SafeFieldsModel();
        $fields = $model->getSafeSqlFields(SafeFieldsModel::tableKeys(), false);
        $this->assertSame('id, name, email, created_at, updated_at', $fields);
    }

    public function test_getSafeSqlFields_handles_numeric_indexed_keys(): void
    {
        $model = new class extends DbModel {
            public string $id;
            public string $title;
            public static function tableName(): string { return 'posts'; }
            public static function tableKeys(): array { return ['id', 'title', 'slug']; }
        };

        // auto verify on: slug should be dropped because it is not a declared property
        $fields = $model->getSafeSqlFields($model::tableKeys(), true);
        $this->assertSame('id, title', $fields);
    }

    public function test_getSafeSqlFields_ignores_alias_mappings_in_auto_verify_true(): void
    {
        $model = new class extends DbModel {
            public string $id;
            public string $first_name;
            public string $last_name;
            public static function tableName(): string { return 'people'; }
            public static function tableKeys(): array { return [
                'id' => 'id',
                'first_name' => 'firstName',
                'last_name' => 'lastName',
                'is_active' => 'isActive', // not a declared property
            ]; }
        };

        $fields = $model->getSafeSqlFields($model::tableKeys(), true);
        // only declared properties should remain; alias values are irrelevant here
        $this->assertSame('id, first_name, last_name', $fields);
    }

    public function test_getSafeSqlFields_returns_empty_string_when_no_overlap(): void
    {
        $model = new class extends DbModel {
            public string $only_prop;
            public static function tableName(): string { return 't'; }
            public static function tableKeys(): array { return ['db_col1', 'db_col2']; }
        };

        $fields = $model->getSafeSqlFields($model::tableKeys(), true);
        $this->assertSame('', $fields);
    }
}
