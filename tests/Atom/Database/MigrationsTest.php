<?php

declare(strict_types=1);

namespace Tests\Atom\DataBase;

use Atom\Atom;
use Atom\DataBase\Database;
use Atom\DataBase\Migrations;
use Atom\DateTime\DateTime;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

final class MigrationsTest extends TestCase
{
    private string $tempDir;
    private PDO $pdo;
    private Database $db;
    private DateTime $datetime;
    private ?Atom $previousApp = null;

    protected function setUp(): void
    {
        $this->tempDir = \dirname(__DIR__, 3) . '/runtime/temp/core_migrations_test_' . \uniqid();
        \mkdir($this->tempDir . '/core', 0777, true);
        \mkdir($this->tempDir . '/app', 0777, true);

        if (isset(Atom::$app)) {
            $this->previousApp = Atom::$app;
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db = Database::fromExistingPdo($this->pdo);
        $this->db->pdo = $this->pdo;
        $this->datetime = new DateTime();
        $this->datetime::setMacro();
    }

    protected function tearDown(): void
    {
        if ($this->previousApp instanceof Atom) {
            Atom::$app = $this->previousApp;
        }

        if (\is_dir($this->tempDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $p = $file->getPathname();
                $file->isDir() ? \rmdir($p) : \unlink($p);
            }
            \rmdir($this->tempDir);
        }
    }

    public function testDiscoversAtomCoreSqlFilesInsideAtomDataBaseMigrations(): void
    {
        $migrations = new Migrations($this->db, $this->datetime);
        $coreDir = $migrations->coreMigrationsDir();

        self::assertDirectoryExists($coreDir);
        $discovered = $migrations->discoverMigrationsInDirectory($coreDir);

        self::assertCount(15, $discovered);
        self::assertArrayHasKey('at0001_create_servers', $discovered);
        self::assertArrayHasKey('at0002_create_allowed_values', $discovered);
        self::assertArrayHasKey('at0003_create_connections', $discovered);
        self::assertArrayHasKey('at0005_create_system_events', $discovered);
        self::assertArrayHasKey('at0015_constraint_system_events', $discovered);
        self::assertArrayNotHasKey('ua0001_create_users', $discovered);
    }

    public function testAppliesAndRollsBackSqlMigrationsInCoreAndAppDirectories(): void
    {
        \file_put_contents(
            $this->tempDir . '/core/at0001_nodes.up.sql',
            'CREATE TABLE {{prefix}}nodes (id INTEGER PRIMARY KEY, name TEXT NOT NULL);'
        );
        \file_put_contents(
            $this->tempDir . '/core/at0001_nodes.down.sql',
            'DROP TABLE {{prefix}}nodes;'
        );
        \file_put_contents(
            $this->tempDir . '/app/app0001_items.up.sql',
            "CREATE TABLE {{prefix}}items (id INTEGER PRIMARY KEY, title TEXT NOT NULL);\nINSERT INTO {{prefix}}items (id, title) VALUES (1, 'First');"
        );
        \file_put_contents(
            $this->tempDir . '/app/app0001_items.down.sql',
            'DROP TABLE {{prefix}}items;'
        );

        /** @var Atom $normalApp */
        $normalApp = (new ReflectionClass(Atom::class))->newInstanceWithoutConstructor();
        Atom::$app = $normalApp;

        $migrations = new Migrations($this->db, $this->datetime);
        $applied = $migrations->applyMigrations(
            includeCore: true,
            includeApp: true,
            coreDir: $this->tempDir . '/core',
            appDir: $this->tempDir . '/app',
        );

        self::assertSame(['at0001_nodes', 'app0001_items'], $applied);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
        self::assertSame(1, $count);

        // Idempotent second run applies nothing
        self::assertSame([], $migrations->applyMigrations(
            includeCore: true,
            includeApp: true,
            coreDir: $this->tempDir . '/core',
            appDir: $this->tempDir . '/app',
        ));

        $rolledBack = $migrations->rollbackMigrations(
            steps: 2,
            coreDir: $this->tempDir . '/core',
            appDir: $this->tempDir . '/app',
        );
        self::assertSame(['app0001_items', 'at0001_nodes'], $rolledBack);
    }

    public function testRefusesMigrationsInNoDbMode(): void
    {
        /** @var Atom $noDbApp */
        $noDbApp = (new ReflectionClass(Atom::class))->newInstanceWithoutConstructor();
        $prop = (new ReflectionClass(Atom::class))->getProperty('isNoDb');
        $prop->setValue($noDbApp, true);
        Atom::$app = $noDbApp;

        $migrations = new Migrations($this->db, $this->datetime);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('noDB or microservice mode');
        $migrations->applyMigrations();
    }
}
