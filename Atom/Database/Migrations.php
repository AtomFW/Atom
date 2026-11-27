<?php

declare(strict_types=1);

namespace Atom\DataBase;

use Atom\Atom;
use Atom\DateTime\DateTime;
use PDO;
use PDOStatement;
use RuntimeException;

use function array_keys;
use function array_slice;
use function clamp;
use function count;
use function date;
use function file_get_contents;
use function gethostname;
use function hash;
use function hash_file;
use function is_array;
use function is_dir;
use function is_file;
use function ksort;
use function pathinfo;
use function php_uname;
use function preg_match;
use function preg_replace;
use function preg_split;
use function rtrim;
use function scandir;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * Database schema migration runner supporting SQL (`.up.sql` / `.down.sql` / `.sql`)
 * and PHP migrations across Atom's internal `Atom/DataBase/migrations` directory
 * and the application's `/migrations` directory, with SHA-512/256 integrity hashing.
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

    public function coreMigrationsDir(): string
    {
        return __DIR__ . \DIRECTORY_SEPARATOR . 'migrations';
    }

    public function appMigrationsDir(): string
    {
        $root = Atom::$ROOT_DIR !== '' ? rtrim(Atom::$ROOT_DIR, '/\\') : \dirname(__DIR__, 2);

        return $root . \DIRECTORY_SEPARATOR . 'migrations';
    }

    /**
     * Applies pending Atom core SQL migrations (`Atom/DataBase/migrations`) and/or
     * application migrations (`/migrations`).
     *
     * @return list<string> Applied migration identifiers
     */
    public function applyMigrations(
        bool $includeCore = true,
        bool $includeApp = true,
        ?string $coreDir = null,
        ?string $appDir = null,
    ): array {
        $this->assertDatabaseAllowed();
        $this->createMigrationsTable();

        $applied = [];

        if ($includeCore) {
            $applied = [...$applied, ...$this->applyDirectoryMigrations($coreDir ?? $this->coreMigrationsDir(), requiredDir: false)];
        }

        if ($includeApp) {
            $applied = [...$applied, ...$this->applyDirectoryMigrations($appDir ?? $this->appMigrationsDir(), requiredDir: false)];
        }

        if ($applied === []) {
            $this->log('There are no migrations to apply');
        }

        return $applied;
    }

    /**
     * Applies only Atom's internal SQL migrations located in `Atom/DataBase/migrations`.
     *
     * @return list<string>
     */
    public function applyCoreMigrations(?string $coreDir = null): array
    {
        return $this->applyMigrations(includeCore: true, includeApp: false, coreDir: $coreDir);
    }

    /**
     * Applies only application-level migrations located in `/migrations`.
     *
     * @return list<string>
     */
    public function applyAppMigrations(?string $appDir = null): array
    {
        return $this->applyMigrations(includeCore: false, includeApp: true, appDir: $appDir);
    }

    /**
     * Rolls back applied migrations (`down`) in reverse chronological order.
     *
     * @return list<string>
     */
    public function rollbackMigrations(
        int $steps = 1,
        ?string $coreDir = null,
        ?string $appDir = null,
    ): array {
        $this->assertDatabaseAllowed();
        $this->createMigrationsTable();

        $safeSteps = clamp($steps, 1, 100_000);
        $historyDesc = $this->fetchAppliedDesc();
        $toRollback = array_slice($historyDesc, 0, $safeSteps);

        if ($toRollback === []) {
            return [];
        }

        $catalog = [
            ...$this->discoverMigrationsInDirectory($coreDir ?? $this->coreMigrationsDir()),
            ...$this->discoverMigrationsInDirectory($appDir ?? $this->appMigrationsDir()),
        ];

        $rolledBack = [];
        foreach ($toRollback as $name) {
            if (!isset($catalog[$name])) {
                throw new RuntimeException("Cannot downgrade missing migration '{$name}'.");
            }

            $this->executeDown($name, $catalog[$name]);
            $this->deleteMigrationRecord($name);
            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    /**
     * Discovers SQL and PHP migrations inside a directory sorted naturally by base identifier.
     *
     * @return array<string, array{upSql: ?string, downSql: ?string, phpFile: ?string, hash: string}>
     */
    public function discoverMigrationsInDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return [];
        }

        /** @var array<string, array{upSql: ?string, downSql: ?string, phpFile: ?string, hash: string}> $discovered */
        $discovered = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }

            $fullPath = $directory . \DIRECTORY_SEPARATOR . $entry;
            if (!is_file($fullPath)) {
                continue;
            }

            if (str_ends_with($entry, '.up.sql')) {
                $baseName = substr($entry, 0, -7);
                $sql = $this->readFileStrict($fullPath);
                $existing = $discovered[$baseName] ?? ['upSql' => null, 'downSql' => null, 'phpFile' => null, 'hash' => ''];
                $existing['upSql'] = $sql;
                $existing['hash'] = hash('sha512/256', $sql . '|' . ($existing['downSql'] ?? ''));
                $discovered[$baseName] = $existing;
                continue;
            }

            if (str_ends_with($entry, '.down.sql')) {
                $baseName = substr($entry, 0, -9);
                $sql = $this->readFileStrict($fullPath);
                $existing = $discovered[$baseName] ?? ['upSql' => null, 'downSql' => null, 'phpFile' => null, 'hash' => ''];
                $existing['downSql'] = $sql;
                $existing['hash'] = hash('sha512/256', ($existing['upSql'] ?? '') . '|' . $sql);
                $discovered[$baseName] = $existing;
                continue;
            }

            if (str_ends_with($entry, '.sql')) {
                $baseName = pathinfo($entry, \PATHINFO_FILENAME);
                $rawSql = $this->readFileStrict($fullPath);
                [$upSql, $downSql] = $this->parseCombinedSql($rawSql);
                $discovered[$baseName] = [
                    'upSql' => $upSql,
                    'downSql' => $downSql,
                    'phpFile' => null,
                    'hash' => hash('sha512/256', $rawSql),
                ];
                continue;
            }

            if (str_ends_with($entry, '.php')) {
                $fileHash = hash_file('sha512/256', $fullPath);
                $discovered[$entry] = [
                    'upSql' => null,
                    'downSql' => null,
                    'phpFile' => $fullPath,
                    'hash' => $fileHash !== false ? $fileHash : '',
                ];
            }
        }

        ksort($discovered, \SORT_NATURAL);

        return $discovered;
    }

    /**
     * @return list<string>
     */
    private function applyDirectoryMigrations(string $migrationsDir, bool $requiredDir): array
    {
        if (!is_dir($migrationsDir)) {
            if ($requiredDir) {
                throw new RuntimeException("Migrations directory does not exist: {$migrationsDir}");
            }

            return [];
        }

        $available = $this->discoverMigrationsInDirectory($migrationsDir);
        if ($available === []) {
            return [];
        }

        $appliedSet = [];
        foreach ($this->getAppliedMigrations() as $name) {
            $appliedSet[$name] = true;
        }

        $newRecords = [];
        $appliedNames = [];

        foreach ($available as $name => $spec) {
            if (isset($appliedSet[$name])) {
                continue;
            }

            $this->log("Applying migration {$name}");
            $this->executeUp($name, $spec);
            $this->log("Applied migration {$name}");

            $newRecords[] = [
                'name' => $name,
                'hash' => $spec['hash'],
            ];
            $appliedNames[] = $name;
        }

        if ($newRecords !== []) {
            $this->saveMigrations($newRecords);
        }

        return $appliedNames;
    }

    /**
     * @param array{upSql: ?string, downSql: ?string, phpFile: ?string, hash: string} $spec
     */
    private function executeUp(string $name, array $spec): void
    {
        if ($spec['phpFile'] !== null) {
            require_once $spec['phpFile'];
            $className = pathinfo($spec['phpFile'], \PATHINFO_FILENAME);
            $instance = new $className();
            $instance->db = $this;
            $instance->up();
            return;
        }

        if ($spec['upSql'] === null || trim($spec['upSql']) === '') {
            throw new RuntimeException("Migration '{$name}' does not define UP SQL statements.");
        }

        $this->executeSqlBatch($this->adaptMigration($spec['upSql']));
    }

    /**
     * @param array{upSql: ?string, downSql: ?string, phpFile: ?string, hash: string} $spec
     */
    private function executeDown(string $name, array $spec): void
    {
        if ($spec['phpFile'] !== null) {
            require_once $spec['phpFile'];
            $className = pathinfo($spec['phpFile'], \PATHINFO_FILENAME);
            $instance = new $className();
            $instance->db = $this;
            if (!\method_exists($instance, 'down')) {
                throw new RuntimeException("PHP migration '{$name}' does not define a down() method.");
            }
            $instance->down();
            return;
        }

        if ($spec['downSql'] === null || trim($spec['downSql']) === '') {
            throw new RuntimeException("SQL migration '{$name}' does not define DOWN SQL statements.");
        }

        $this->executeSqlBatch($this->adaptMigration($spec['downSql']));
    }

    private function executeSqlBatch(string $sqlBatch): void
    {
        $statements = preg_split('/;\s*(?:\r?\n|$)/', trim($sqlBatch)) ?: [];
        foreach ($statements as $statement) {
            $trimmed = trim($statement);
            if ($trimmed === '') {
                continue;
            }
            $this->database->pdo->exec($trimmed);
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function parseCombinedSql(string $rawSql): array
    {
        if (preg_match('/^\s*--\s*@down\b/im', $rawSql, $downMatch, \PREG_OFFSET_CAPTURE) === 1) {
            $downOffset = $downMatch[0][1];
            $upPart = substr($rawSql, 0, $downOffset);
            $downPart = substr($rawSql, $downOffset + strlen($downMatch[0][0]));
            $upClean = preg_replace('/^\s*--\s*@up\b[^\r\n]*\r?\n?/im', '', $upPart) ?? $upPart;

            return [trim($upClean), trim($downPart)];
        }

        $upOnly = preg_replace('/^\s*--\s*@up\b[^\r\n]*\r?\n?/im', '', $rawSql) ?? $rawSql;

        return [trim($upOnly), null];
    }

    protected function createMigrationsTable(): void
    {
        $table = $this->migrationsTableName();

        if ($this->isSqlite()) {
            $this->database->pdo->exec("
                CREATE TABLE IF NOT EXISTS \"{$table}\" (
                    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                    \"migration\" TEXT NOT NULL UNIQUE,
                    \"migration_hash_file\" TEXT NOT NULL,
                    \"withdrawn\" INTEGER NOT NULL DEFAULT 0,
                    \"server_id\" INTEGER NOT NULL DEFAULT 1,
                    \"updated_at\" TEXT NOT NULL,
                    \"created_at\" TEXT NOT NULL
                )
            ");
            return;
        }

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
        try {
            $exists = (int) $stmt->fetchColumn() > 0;
        } finally {
            $stmt->closeCursor();
        }

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
        $sql = $this->isSqlite()
            ? "SELECT \"migration\" FROM \"{$table}\" WHERE \"withdrawn\" = 0 ORDER BY \"id\" ASC"
            : "SELECT `migration` FROM `{$table}` WHERE `withdrawn` = 0 ORDER BY `id` ASC";

        $statement = $this->database->pdo->prepare($sql);
        $statement->execute();

        $items = [];
        try {
            while (($col = $statement->fetchColumn()) !== false) {
                $items[] = (string) $col;
            }
        } finally {
            $statement->closeCursor();
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function fetchAppliedDesc(): array
    {
        $table = $this->migrationsTableName();
        $sql = $this->isSqlite()
            ? "SELECT \"migration\" FROM \"{$table}\" WHERE \"withdrawn\" = 0 ORDER BY \"id\" DESC"
            : "SELECT `migration` FROM `{$table}` WHERE `withdrawn` = 0 ORDER BY `id` DESC";

        $statement = $this->database->pdo->prepare($sql);
        $statement->execute();

        $items = [];
        try {
            while (($col = $statement->fetchColumn()) !== false) {
                $items[] = (string) $col;
            }
        } finally {
            $statement->closeCursor();
        }

        return $items;
    }

    /**
     * @param list<array{name: string, hash: string}> $newMigrations
     */
    protected function saveMigrations(array $newMigrations): void
    {
        $table = $this->migrationsTableName();
        $datetimeSQL = $this->datetime->utc()->now()->format('Y-m-d H:i:s.u');

        $insertSql = $this->isSqlite()
            ? "INSERT INTO \"{$table}\" (\"migration\", \"migration_hash_file\", \"withdrawn\", \"server_id\", \"updated_at\", \"created_at\")
               VALUES (:name, :hash, 0, 1, :updated_at, :created_at)"
            : "INSERT INTO `{$table}` (`migration`, `migration_hash_file`, `withdrawn`, `server_id`, `updated_at`, `created_at`)
               VALUES (:name, :hash, 0, 1, :updated_at, :created_at)";

        $statement = $this->database->pdo->prepare($insertSql);

        foreach ($newMigrations as $migration) {
            $statement->execute([
                'name' => $migration['name'],
                'hash' => $migration['hash'],
                'updated_at' => $datetimeSQL,
                'created_at' => $datetimeSQL,
            ]);
        }

        if ($this->isSqlite()) {
            return;
        }

        $serversTable = $this->safePrefix() . 'servers';
        $serversTableCheck = $this->database->pdo->prepare('
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :servers_table
        ');
        $serversTableCheck->execute(['servers_table' => $serversTable]);
        try {
            $hasServersTable = (int) $serversTableCheck->fetchColumn() > 0;
        } finally {
            $serversTableCheck->closeCursor();
        }

        if (!$hasServersTable) {
            return;
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
        try {
            $hasConstraint = (int) $constraintCheck->fetchColumn() > 0;
        } finally {
            $constraintCheck->closeCursor();
        }

        if (!$hasConstraint) {
            $this->database->pdo->exec(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `migrations_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `{$serversTable}` (`id`);"
            );
        }
    }

    private function deleteMigrationRecord(string $migrationName): void
    {
        $table = $this->migrationsTableName();
        $sql = $this->isSqlite()
            ? "DELETE FROM \"{$table}\" WHERE \"migration\" = :name"
            : "DELETE FROM `{$table}` WHERE `migration` = :name";

        $stmt = $this->database->pdo->prepare($sql);
        $stmt->execute(['name' => $migrationName]);
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

    private function assertDatabaseAllowed(): void
    {
        if (isset(Atom::$app) && Atom::$app->isNoDbMode()) {
            throw new RuntimeException('Database SQL migrations cannot run while Atom is in noDB or microservice mode.');
        }
    }

    private function isSqlite(): bool
    {
        return strtolower((string) $this->database->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite';
    }

    private function readFileStrict(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Failed to read migration file: {$path}");
        }

        return $contents;
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
        if (\PHP_SAPI === 'cli' && !\defined('PHPUNIT_COMPOSER_INSTALL')) {
            \fwrite(\STDOUT, '[' . date('Y-m-d H:i:s') . '] - ' . $message . \PHP_EOL);
        }
    }
}
