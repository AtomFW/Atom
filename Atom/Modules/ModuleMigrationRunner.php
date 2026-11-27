<?php

declare(strict_types=1);

namespace Atom\Modules;

use PDO;
use ReflectionClass;
use RuntimeException;

use function array_keys;
use function array_reverse;
use function array_slice;
use function clamp;
use function count;
use function file_get_contents;
use function gmdate;
use function hash;
use function is_dir;
use function is_file;
use function ksort;
use function pathinfo;
use function preg_match;
use function preg_replace;
use function preg_split;
use function scandir;
use function str_ends_with;
use function str_replace;
use function strtolower;
use function substr;
use function trim;

/**
 * Executes and rolls back SQL (`up`/`down`) and PHP migrations located inside
 * `Atom/Modules/<ModuleName>/migration` (or `migrations`).
 */
final readonly class ModuleMigrationRunner
{
    private string $safePrefix;
    private string $modulesBaseDir;
    private string $domainUri;

    public function __construct(
        private PDO $pdo,
        string $tablePrefix = '',
        ?string $modulesBaseDir = null,
        string $domainUri = 'http://localhost',
    ) {
        $this->safePrefix = preg_replace('/[^a-zA-Z0-9_]/', '', $tablePrefix) ?? '';
        $this->modulesBaseDir = $modulesBaseDir !== null
            ? \rtrim($modulesBaseDir, '/\\')
            : __DIR__;
        $this->domainUri = $domainUri;
    }

    public function tableName(): string
    {
        return $this->safePrefix . 'module_migrations';
    }

    /**
     * Ensures the module migrations tracking table exists (compatible with MySQL/MariaDB and SQLite).
     */
    public function ensureMigrationsTable(): void
    {
        $table = $this->tableName();
        $driver = strtolower((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        if ($driver === 'sqlite') {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS \"{$table}\" (
                    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                    \"module\" TEXT NOT NULL,
                    \"migration\" TEXT NOT NULL,
                    \"migration_hash\" TEXT NOT NULL,
                    \"applied_at\" TEXT NOT NULL,
                    UNIQUE(\"module\", \"migration\")
                )
            ");
            return;
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `module` VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `migration` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `migration_hash` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `applied_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                UNIQUE KEY `uniq_module_migration` (`module`, `migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin
        ");
    }

    /**
     * Applies pending migrations (`up`) for a specific module or all modules in `$modulesBaseDir`.
     *
     * @param callable(int $current, int $total, string $label): void|null $onProgress
     * @return list<string> List of applied `"Module:migration"` identifiers
     */
    public function migrateUp(?string $moduleName = null, ?int $steps = null, ?callable $onProgress = null): array
    {
        $this->ensureMigrationsTable();
        $modules = $moduleName !== null ? [$this->sanitizeModuleName($moduleName)] : $this->discoverModulesWithMigrations();

        $pendingQueue = [];
        foreach ($modules as $mod) {
            $available = $this->discoverModuleMigrations($mod);
            $applied = $this->getAppliedMap($mod);

            foreach ($available as $name => $spec) {
                if (!isset($applied[$name])) {
                    $pendingQueue[] = [$mod, $name, $spec];
                }
            }
        }

        if ($steps !== null) {
            $maxSteps = clamp($steps, 1, 100_000);
            $pendingQueue = array_slice($pendingQueue, 0, $maxSteps);
        }

        $total = count($pendingQueue);
        $appliedLabels = [];

        foreach ($pendingQueue as $idx => [$mod, $name, $spec]) {
            $label = "{$mod}:{$name}";
            $this->executeUp($mod, $name, $spec);
            $this->recordApplied($mod, $name, $spec['hash']);
            $appliedLabels[] = $label;

            if ($onProgress !== null) {
                $onProgress($idx + 1, $total, $label);
            }
        }

        return $appliedLabels;
    }

    /**
     * Rolls back applied migrations (`down`) in reverse chronological order for a module (or all modules).
     *
     * @param callable(int $current, int $total, string $label): void|null $onProgress
     * @return list<string> List of downgraded `"Module:migration"` identifiers
     */
    public function migrateDown(?string $moduleName = null, int $steps = 1, ?callable $onProgress = null): array
    {
        $this->ensureMigrationsTable();
        $safeSteps = clamp($steps, 1, 100_000);

        $appliedRows = $this->fetchAppliedHistoryDesc($moduleName !== null ? $this->sanitizeModuleName($moduleName) : null);
        $toRollback = array_slice($appliedRows, 0, $safeSteps);

        $total = count($toRollback);
        $rolledBack = [];

        foreach ($toRollback as $idx => $row) {
            $mod = $row['module'];
            $name = $row['migration'];
            $available = $this->discoverModuleMigrations($mod);
            $label = "{$mod}:{$name}";

            if (!isset($available[$name])) {
                throw new RuntimeException("Cannot downgrade missing migration definition '{$label}'.");
            }

            $this->executeDown($mod, $name, $available[$name]);
            $this->deleteAppliedRecord($mod, $name);
            $rolledBack[] = $label;

            if ($onProgress !== null) {
                $onProgress($idx + 1, $total, $label);
            }
        }

        return $rolledBack;
    }

    /**
     * Returns migration status rows for one module or all discovered modules.
     *
     * @return list<array{module: string, migration: string, applied: bool, applied_at: ?string}>
     */
    public function status(?string $moduleName = null): array
    {
        $this->ensureMigrationsTable();
        $modules = $moduleName !== null ? [$this->sanitizeModuleName($moduleName)] : $this->discoverModulesWithMigrations();
        $rows = [];

        foreach ($modules as $mod) {
            $available = $this->discoverModuleMigrations($mod);
            $applied = $this->getAppliedMap($mod);

            foreach (array_keys($available) as $name) {
                $rows[] = [
                    'module' => $mod,
                    'migration' => $name,
                    'applied' => isset($applied[$name]),
                    'applied_at' => $applied[$name] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * Discovers migration definitions inside `<ModuleDir>/migration` (or `migrations`).
     *
     * Supported files:
     * - `<name>.up.sql` + optional `<name>.down.sql`
     * - `<name>.sql` (with optional `-- @up` / `-- @down` markers)
     * - `<name>.php` (class with `up(PDO)` / `down(PDO)` methods)
     *
     * @return array<string, array{upSql: ?string, downSql: ?string, phpFile: ?string, hash: string}>
     */
    public function discoverModuleMigrations(string $moduleName): array
    {
        $safeModule = $this->sanitizeModuleName($moduleName);
        $migrationDir = $this->resolveMigrationDirectory($safeModule);
        if ($migrationDir === null) {
            return [];
        }

        $entries = scandir($migrationDir);
        if ($entries === false) {
            return [];
        }

        /** @var array<string, array{upSql: ?string, downSql: ?string, phpFile: ?string, hash: string}> $migrations */
        $migrations = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $migrationDir . \DIRECTORY_SEPARATOR . $entry;
            if (!is_file($fullPath)) {
                continue;
            }

            if (str_ends_with($entry, '.up.sql')) {
                $baseName = substr($entry, 0, -7);
                $sql = $this->readFileStrict($fullPath);
                $existing = $migrations[$baseName] ?? ['upSql' => null, 'downSql' => null, 'phpFile' => null, 'hash' => ''];
                $existing['upSql'] = $sql;
                $existing['hash'] = hash('sha256', $sql . '|' . ($existing['downSql'] ?? ''));
                $migrations[$baseName] = $existing;
                continue;
            }

            if (str_ends_with($entry, '.down.sql')) {
                $baseName = substr($entry, 0, -9);
                $sql = $this->readFileStrict($fullPath);
                $existing = $migrations[$baseName] ?? ['upSql' => null, 'downSql' => null, 'phpFile' => null, 'hash' => ''];
                $existing['downSql'] = $sql;
                $existing['hash'] = hash('sha256', ($existing['upSql'] ?? '') . '|' . $sql);
                $migrations[$baseName] = $existing;
                continue;
            }

            if (str_ends_with($entry, '.sql')) {
                $baseName = pathinfo($entry, \PATHINFO_FILENAME);
                $rawSql = $this->readFileStrict($fullPath);
                [$upSql, $downSql] = $this->parseCombinedSql($rawSql);
                $migrations[$baseName] = [
                    'upSql' => $upSql,
                    'downSql' => $downSql,
                    'phpFile' => null,
                    'hash' => hash('sha256', $rawSql),
                ];
                continue;
            }

            if (str_ends_with($entry, '.php')) {
                $baseName = pathinfo($entry, \PATHINFO_FILENAME);
                $rawPhp = $this->readFileStrict($fullPath);
                $migrations[$baseName] = [
                    'upSql' => null,
                    'downSql' => null,
                    'phpFile' => $fullPath,
                    'hash' => hash('sha256', $rawPhp),
                ];
            }
        }

        ksort($migrations, \SORT_NATURAL);
        return $migrations;
    }

    public function resolveMigrationDirectory(string $moduleName): ?string
    {
        $safeModule = $this->sanitizeModuleName($moduleName);
        $base = $this->modulesBaseDir . \DIRECTORY_SEPARATOR . $safeModule . \DIRECTORY_SEPARATOR;

        if (is_dir($base . 'migration')) {
            return $base . 'migration';
        }

        if (is_dir($base . 'migrations')) {
            return $base . 'migrations';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function discoverModulesWithMigrations(): array
    {
        if (!is_dir($this->modulesBaseDir)) {
            return [];
        }

        $entries = scandir($this->modulesBaseDir);
        if ($entries === false) {
            return [];
        }

        $modules = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $this->modulesBaseDir . \DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full) && preg_match('/^[a-zA-Z0-9_\-]+$/', $entry) === 1 && $this->resolveMigrationDirectory($entry) !== null) {
                $modules[] = $entry;
            }
        }

        \sort($modules, \SORT_NATURAL);
        return $modules;
    }

    /**
     * @param array{upSql: ?string, downSql: ?string, phpFile: ?string, hash: string} $spec
     */
    private function executeUp(string $moduleName, string $migrationName, array $spec): void
    {
        if ($spec['phpFile'] !== null) {
            $instance = $this->instantiatePhpMigration($spec['phpFile']);
            if (!\method_exists($instance, 'up')) {
                throw new RuntimeException("Module migration '{$moduleName}:{$migrationName}' is missing an up() method.");
            }
            $instance->up($this->pdo);
            return;
        }

        if ($spec['upSql'] === null || trim($spec['upSql']) === '') {
            throw new RuntimeException("Module migration '{$moduleName}:{$migrationName}' has no UP SQL statements.");
        }

        $this->executeSqlBatch($this->adaptSql($spec['upSql'], $moduleName));
    }

    /**
     * @param array{upSql: ?string, downSql: ?string, phpFile: ?string, hash: string} $spec
     */
    private function executeDown(string $moduleName, string $migrationName, array $spec): void
    {
        if ($spec['phpFile'] !== null) {
            $instance = $this->instantiatePhpMigration($spec['phpFile']);
            if (!\method_exists($instance, 'down')) {
                throw new RuntimeException("Module migration '{$moduleName}:{$migrationName}' does not define a down() method for downgrade.");
            }
            $instance->down($this->pdo);
            return;
        }

        if ($spec['downSql'] === null || trim($spec['downSql']) === '') {
            throw new RuntimeException("Module migration '{$moduleName}:{$migrationName}' does not have downgrade SQL (.down.sql or -- @down).");
        }

        $this->executeSqlBatch($this->adaptSql($spec['downSql'], $moduleName));
    }

    private function executeSqlBatch(string $sqlBatch): void
    {
        $statements = preg_split('/;\s*(?:\r?\n|$)/', trim($sqlBatch)) ?: [];
        foreach ($statements as $statement) {
            $trimmed = trim($statement);
            if ($trimmed === '') {
                continue;
            }
            $this->pdo->exec($trimmed);
        }
    }

    private function adaptSql(string $sql, string $moduleName): string
    {
        return str_replace(
            [
                '{{prefix}}',
                '{{module}}',
                '{{datetimeutcsql}}',
                '{{domain}}',
                '{{hostname}}',
                '{{osname}}',
                '{{osversion}}',
                '{{osip}}',
            ],
            [
                $this->safePrefix,
                strtolower($moduleName),
                gmdate('Y-m-d H:i:s'),
                $this->domainUri,
                (string) (\gethostname() ?: 'localhost'),
                \php_uname('s'),
                \php_uname('r'),
                (string) ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1'),
            ],
            $sql,
        );
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function parseCombinedSql(string $rawSql): array
    {
        if (preg_match('/^\s*--\s*@down\b/im', $rawSql, $downMatch, \PREG_OFFSET_CAPTURE) === 1) {
            $downOffset = $downMatch[0][1];
            $upPart = substr($rawSql, 0, $downOffset);
            $downPart = substr($rawSql, $downOffset + \strlen($downMatch[0][0]));
            $upClean = preg_replace('/^\s*--\s*@up\b[^\r\n]*\r?\n?/im', '', $upPart) ?? $upPart;

            return [trim($upClean), trim($downPart)];
        }

        $upOnly = preg_replace('/^\s*--\s*@up\b[^\r\n]*\r?\n?/im', '', $rawSql) ?? $rawSql;
        return [trim($upOnly), null];
    }

    private function instantiatePhpMigration(string $filePath): object
    {
        $returned = require $filePath;
        if (\is_object($returned)) {
            return $returned;
        }

        $className = pathinfo($filePath, \PATHINFO_FILENAME);
        if (\class_exists($className)) {
            $ref = new ReflectionClass($className);
            return $ref->newInstance();
        }

        throw new RuntimeException("Unable to resolve migration class or anonymous object from '{$filePath}'.");
    }

    /**
     * @return array<string, string>
     */
    private function getAppliedMap(string $moduleName): array
    {
        $table = $this->tableName();
        $stmt = $this->pdo->prepare("SELECT migration, applied_at FROM {$table} WHERE module = :module ORDER BY id ASC");
        $stmt->execute(['module' => $moduleName]);

        $map = [];
        try {
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (\is_array($row) && isset($row['migration'])) {
                    $map[(string) $row['migration']] = (string) ($row['applied_at'] ?? '');
                }
            }
        } finally {
            $stmt->closeCursor();
        }

        return $map;
    }

    /**
     * @return list<array{module: string, migration: string}>
     */
    private function fetchAppliedHistoryDesc(?string $moduleName): array
    {
        $table = $this->tableName();
        if ($moduleName !== null) {
            $stmt = $this->pdo->prepare("SELECT module, migration FROM {$table} WHERE module = :module ORDER BY id DESC");
            $stmt->execute(['module' => $moduleName]);
        } else {
            $stmt = $this->pdo->prepare("SELECT module, migration FROM {$table} ORDER BY id DESC");
            $stmt->execute();
        }

        $rows = [];
        try {
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (\is_array($row) && isset($row['module'], $row['migration'])) {
                    $rows[] = [
                        'module' => (string) $row['module'],
                        'migration' => (string) $row['migration'],
                    ];
                }
            }
        } finally {
            $stmt->closeCursor();
        }

        return $rows;
    }

    private function recordApplied(string $moduleName, string $migrationName, string $hash): void
    {
        $table = $this->tableName();
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$table} (module, migration, migration_hash, applied_at) VALUES (:module, :migration, :hash, :applied_at)"
        );
        $stmt->execute([
            'module' => $moduleName,
            'migration' => $migrationName,
            'hash' => $hash,
            'applied_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function deleteAppliedRecord(string $moduleName, string $migrationName): void
    {
        $table = $this->tableName();
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE module = :module AND migration = :migration");
        $stmt->execute([
            'module' => $moduleName,
            'migration' => $migrationName,
        ]);
    }

    private function readFileStrict(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Failed to read migration file: {$path}");
        }

        return $contents;
    }

    private function sanitizeModuleName(string $moduleName): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($moduleName)) ?? '';
        if ($safe === '' || $safe !== trim($moduleName)) {
            throw new RuntimeException("Invalid module name '{$moduleName}'.");
        }

        return $safe;
    }
}
