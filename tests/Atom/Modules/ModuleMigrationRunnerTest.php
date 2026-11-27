<?php

declare(strict_types=1);

namespace Tests\Atom\Modules;

use Atom\Modules\ModuleMigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ModuleMigrationRunnerTest extends TestCase
{
    private string $tempModulesDir;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->tempModulesDir = \dirname(__DIR__, 3) . '/runtime/temp/mod_migrations_' . \uniqid();
        \mkdir($this->tempModulesDir . '/Blog/migration', 0777, true);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        \file_put_contents(
            $this->tempModulesDir . '/Blog/migration/bl0001_create_posts.up.sql',
            'CREATE TABLE {{prefix}}blog_posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL);'
        );
        \file_put_contents(
            $this->tempModulesDir . '/Blog/migration/bl0001_create_posts.down.sql',
            'DROP TABLE {{prefix}}blog_posts;'
        );

        $combined = <<<'SQL'
-- @up
INSERT INTO {{prefix}}blog_posts (id, title) VALUES (1, 'Hello Module');
-- @down
DELETE FROM {{prefix}}blog_posts WHERE id = 1;
SQL;
        \file_put_contents(
            $this->tempModulesDir . '/Blog/migration/bl0002_seed_post.sql',
            $combined
        );
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tempModulesDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempModulesDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $p = $file->getPathname();
                $file->isDir() ? \rmdir($p) : \unlink($p);
            }
            \rmdir($this->tempModulesDir);
        }
    }

    public function testMigrateUpStatusAndMigrateDownSql(): void
    {
        $runner = new ModuleMigrationRunner($this->pdo, 'atom_', $this->tempModulesDir);

        $applied = $runner->migrateUp('Blog');
        self::assertSame(['Blog:bl0001_create_posts', 'Blog:bl0002_seed_post'], $applied);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM atom_blog_posts WHERE title = 'Hello Module'")->fetchColumn();
        self::assertSame(1, $count);

        $statusAfterUp = $runner->status('Blog');
        self::assertCount(2, $statusAfterUp);
        self::assertTrue($statusAfterUp[0]['applied']);
        self::assertTrue($statusAfterUp[1]['applied']);

        $rolledBackOne = $runner->migrateDown('Blog', steps: 1);
        self::assertSame(['Blog:bl0002_seed_post'], $rolledBackOne);

        $remainingRows = (int) $this->pdo->query('SELECT COUNT(*) FROM atom_blog_posts')->fetchColumn();
        self::assertSame(0, $remainingRows);

        $rolledBackTwo = $runner->migrateDown('Blog', steps: 1);
        self::assertSame(['Blog:bl0001_create_posts'], $rolledBackTwo);

        $tableExists = (int) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='atom_blog_posts'")->fetchColumn();
        self::assertSame(0, $tableExists);
    }
}
