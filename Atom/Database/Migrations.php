<?php

declare(strict_types=1);

namespace Atom\DataBase;

use Atom\Atom;
use Atom\DateTime\DateTime;
use PDO;
use PDOStatement;
use RuntimeException;

use function array_diff;
use function array_values;
use function basename;
use function date;
use function gethostname;
use function hash_file;
use function is_dir;
use function natsort;
use function pathinfo;
use function php_uname;
use function preg_replace;
use function scandir;
use function str_ends_with;
use function str_replace;

/**
 * Database schema migration runner with SHA-512/256 integrity hashing and parameterized persistence.
 */
final class Migrations
{
    /** @var array<string, mixed> */
    public array $config;

    public function __construct(
        public readonly Database $database,
        public readonly DateTime $datetime,
    ) {
        $this->config = $this->database->getConfig();
    }

    /**
     * Applies all pending migrations from the `/migrations` directory.
     */
    public function applyMigrations(): void
    {
        $this->createMigrationsTable();

        $appliedMigrations = $this->getAppliedMigrations();
        $migrationsDir = Atom::$ROOT_DIR . '/migrations';

        if (!is_dir($migrationsDir)) {
            throw new RuntimeException("Migrations directory does not exist: {$migrationsDir}");
        }

        $files = scandir($migrationsDir);
        if ($files === false) {
            throw new RuntimeException("Unable to read migrations directory: {$migrationsDir}");
        }

        $toApplyMigrations = array_diff($files, $appliedMigrations);
        natsort($toApplyMigrations);
        $toApplyMigrations = array_values($toApplyMigrations);

        $newMigrations = [];

        foreach ($toApplyMigrations as $migration) {
            $safeFile = basename($migration);
            if ($safeFile === '.' || $safeFile === '..' || !str_ends_with($safeFile, '.php')) {
                continue;
            }

            $fullPath = $migrationsDir . \DIRECTORY_SEPARATOR . $safeFile;
            require_once $fullPath;

            $className = pathinfo($safeFile, \PATHINFO_FILENAME);
            $instance = new $className();
            $instance->db = $this;

            $this->log("Applying migration {$safeFile}");
            $instance->up();
            $this->log("Applied migration {$safeFile}");

            $hash = hash_file('sha512/256', $fullPath);
            $newMigrations[] = [
                'name' => $safeFile,
                'hash' => $hash !== false ? $hash : '',
            ];
        }

        if ($newMigrations !== []) {
            $this->saveMigrations($newMigrations);
        } else {
            $this->log('There are no migrations to apply');
        }
    }

    protected function createMigrationsTable(): void
    {
        $table = $this->migrationsTableName();

        $this->database->pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `migration` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'migration name file',
                `migration_hash_file` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'to verify existing migrations',
                `withdrawn` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 = used, 1 = withdrawn',
                `server_id` mediumint UNSIGNED NOT NULL COMMENT 'from which server it was added | id from servers table',
                `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT 'time when the data was updated',
                `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT 'time when migration was added'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin COMMENT='control of all migrations in the instance';
        ");

        $stmt = $this->database->pdo->prepare('
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
              AND INDEX_NAME = :idx
        ');
        $stmt->execute(['table' => $table, 'idx' => 'server_id']);
        $exists = (int) $stmt->fetchColumn() > 0;

        if (!$exists) {
            $this->database->pdo->exec("ALTER TABLE `{$table}` ADD KEY `server_id` (`server_id`);");
        }
    }

    /**
     * @return list<string>
     */
    protected function getAppliedMigrations(): array
    {
        $table = $this->migrationsTableName();
        $statement = $this->database->pdo->prepare("SELECT `migration` FROM `{$table}`");
        $statement->execute();

        /** @var list<string> */
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param list<array{name: string, hash: string}> $newMigrations
     */
    protected function saveMigrations(array $newMigrations): void
    {
        $table = $this->migrationsTableName();
        $datetimeSQL = $this->datetime->utc()->now()->format('Y-m-d H:i:s.u');

        $statement = $this->database->pdo->prepare(
            "INSERT INTO `{$table}` (`migration`, `migration_hash_file`, `withdrawn`, `server_id`, `updated_at`, `created_at`)
             VALUES (:name, :hash, 0, 1, :updated_at, :created_at)"
        );

        foreach ($newMigrations as $migration) {
            $statement->execute([
                'name' => $migration['name'],
                'hash' => $migration['hash'],
                'updated_at' => $datetimeSQL,
                'created_at' => $datetimeSQL,
            ]);
        }

        $constraintCheck = $this->database->pdo->prepare('
            SELECT COUNT(*)
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
              AND CONSTRAINT_NAME = :constraint
        ');
        $constraintCheck->execute([
            'table' => $table,
            'constraint' => 'migrations_ibfk_1',
        ]);

        if ((int) $constraintCheck->fetchColumn() === 0) {
            $serversTable = $this->safePrefix() . 'servers';
            $this->database->pdo->exec(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `migrations_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `{$serversTable}` (`id`);"
            );
        }
    }

    public function prepare(string $sql): PDOStatement
    {
        return $this->database->pdo->prepare($sql);
    }

    public function adaptMigration(string $sql): string
    {
        return str_replace(
            [
                '{{prefix}}',
                '{{hostname}}',
                '{{osname}}',
                '{{osversion}}',
                '{{osip}}',
                '{{datetimeutcsql}}',
                '{{domain}}',
            ],
            [
                $this->safePrefix(),
                (string) (gethostname() ?: 'localhost'),
                php_uname('s'),
                php_uname('r'),
                (string) ($this->config['server_addr'] ?? '127.0.0.1'),
                $this->datetime->utc()->now()->format('Y-m-d H:i:s.u'),
                (string) ($this->config['uri'] ?? 'http://localhost'),
            ],
            $sql,
        );
    }

    private function safePrefix(): string
    {
        $raw = (string) ($this->config['prefix'] ?? '');
        return preg_replace('/[^a-zA-Z0-9_]/', '', $raw) ?? '';
    }

    private function migrationsTableName(): string
    {
        return $this->safePrefix() . 'migrations';
    }

    private function log(string $message): void
    {
        if (\PHP_SAPI === 'cli') {
            \fwrite(\STDOUT, '[' . date('Y-m-d H:i:s') . '] - ' . $message . \PHP_EOL);
        }
    }
}
