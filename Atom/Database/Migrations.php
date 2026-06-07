<?php

declare(strict_types=1);

namespace Atom\DataBase;

use Atom\Atom;
use Atom\DataBase\Database;
use Atom\DateTime\DateTime;

/**
 * Class Migrations
 * 
 * The Migrations class is responsible for handling database migration operations.
 * It provides functionality to adapt SQL migration queries by replacing placeholders
 * with actual values and manages the underlying database configuration and datetime
 * operations required for migration processes.
 * 
 * This class serves as a utility for database schema evolution, allowing developers
 * to version control database changes and apply them systematically across different
 * environments through placeholder replacement and configuration management.
 * 
 * @package Atom\DataBase\Migrations
 */
final class Migrations
{
    public array $config;

    /**
     * Class constructor
     * 
     * Initializes a new instance of the class and stores references to the database and datetime objects.
     * This constructor accepts two dependencies through the parameters and assigns them to
     * their respective class properties, ensuring that the class has access to database
     * configuration and datetime functionality.
     * 
     * @param Database $database The database instance used for database operations
     * @param DateTime $datetime The datetime instance used for date/time operations
     * @return void
     */
    public function __construct(public Database $database, public DateTime $datetime) {
        $this->config = $this->database->getConfig();
    }

    /**
     * Applies all migrations that haven't been applied yet.
     *
     * This method checks which migrations have been applied and then
     * applies all migrations that haven't been applied yet.
     *
     * @return void
     */
    public function applyMigrations()
    {
        $this->createMigrationsTable();
        
        $appliedMigrations = $this->getAppliedMigrations();

        $newMigrations = [];
        $files = scandir(Atom::$ROOT_DIR . '/migrations');
        $toApplyMigrations = array_diff($files, $appliedMigrations);
        
        natsort($toApplyMigrations);
        $toApplyMigrations = array_values($toApplyMigrations);
        
        foreach ($toApplyMigrations as $migration) {
            if ($migration === '.' || $migration === '..') {
                continue;
            }

            require_once Atom::$ROOT_DIR . '/migrations/' . $migration;
            $className = pathinfo($migration, PATHINFO_FILENAME);
            $instance = new $className();
            $instance->db = $this;
            $this->log("Applying migration $migration");
            $instance->up();
            $this->log("Applied migration $migration");
            $newMigrations[] = [
                "name" => $migration,
                "hash" => hash_file('sha512', Atom::$ROOT_DIR . '/migrations/' . $migration, false, ['length' => 256])
            ];
        }

        if (!empty($newMigrations)) {
            $this->saveMigrations($newMigrations);
        } else {
            $this->log("There are no migrations to apply");
        }
    }

    /**
     * Creates the migrations table in the database.
     *
     * This method creates the migrations table in the database if it
     * doesn't already exist. The table is used to keep track of which
     * migrations have been applied.
     *
     * @return void
     */
    protected function createMigrationsTable()
    {
        $this->database->pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$this->database->getConfig()['prefix']}migrations` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `migration` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'migration name file',
                `migration_hash_file` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'to verify existing migrations',
                `withdrawn` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 = used, 1 = with out | migration is withdrawn from used',
                `server_id` mediumint UNSIGNED NOT NULL COMMENT 'from which server it was added | id from servers table',
                `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT 'time when the data was updated',
                `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT 'time when add migration was added'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin COMMENT='control of all migrations in the instance';"
        );

        // check if the constraint exists
        $exists = $this->database->pdo->query("
            SELECT COUNT(*) 
            FROM information_schema.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$this->database->getConfig()['prefix']}migrations' 
            AND INDEX_NAME = 'server_id'
        ")->fetchColumn();

        if ($exists) {
            return;
        }

        $this->database->pdo->exec("
            ALTER TABLE `{$this->database->getConfig()['prefix']}migrations`
            ADD KEY `server_id` (`server_id`);"
        );
    }

    /**
     * Returns an array of all applied migrations.
     *
     * This method returns an array of all applied migrations. The
     * array contains the names of the migrations that have been
     * applied.
     *
     * @return array An array of all applied migrations.
     */
    protected function getAppliedMigrations(): array
    {
        $statement = $this->database->pdo->prepare("SELECT `migration` FROM `{$this->database->getConfig()['prefix']}migrations`");
        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Saves the new migrations to the database.
     *
     * This method saves the new migrations to the database. It takes an
     * array of new migrations as an argument.
     *
     * @param array $newMigrations An array of new migrations to save to the database.
     *
     * @return void
     */
    protected function saveMigrations(array $newMigrations): void
    {   
        $datetimeSQL = $this->datetime->utc()->now()->format('Y-m-d H:i:s.u');
        $str = implode(',', array_map(fn($m) => "('{$m["name"]}','{$m["hash"]}','0','1','{$datetimeSQL}','{$datetimeSQL}')", $newMigrations));

        $statement = $this->database->pdo->prepare("INSERT INTO `{$this->database->getConfig()['prefix']}migrations` (`migration`, `migration_hash_file`, `withdrawn`, `server_id`, `updated_at`, `created_at`) VALUES 
            $str
        ");

        $statement->execute();

        // check if the constraint exists
        $exists = $this->database->pdo->query("
            SELECT COUNT(*) 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE CONSTRAINT_SCHEMA = DATABASE() 
            AND CONSTRAINT_NAME = 'migrations' 
            AND TABLE_NAME = 'migrations_ibfk_1'
        ")->fetchColumn();

        if (!$exists) {
            $this->database->pdo->exec("ALTER TABLE `{$this->database->getConfig()['prefix']}migrations`
                ADD CONSTRAINT `migrations_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`);"
            );
        }
    }

    /**
     * Prepares a SQL statement via the application's PDO connection.
     *
     * This method takes a raw SQL string and binds it as a named parameter
     * via the application's PDO connection.
     *
     * @param string $sql Raw SQL with named placeholders.
     * @return \PDOStatement Prepared statement.
     */
    public function prepare($sql): \PDOStatement
    {
        return $this->database->pdo->prepare($sql);
    }

    /**
     * Adapts SQL migration queries by replacing placeholders with actual values
     * 
     * This public method takes an SQL statement as a string and replaces various
     * placeholders with actual database and system information. It's commonly used
     * in database migration scripts to make them dynamic and environment-specific.
     * 
     * Placeholders replaced:
     * - {{prefix}} - Database table prefix from configuration
     * - {{hostname}} - Hostname of the server
     * - {{osname}} - Operating system name
     * - {{osversion}} - Operating system version
     * - {{osip}} - Server IP address
     * - {{datetimeutcsql}} - Current UTC datetime in SQL format
     * - {{domain}} - Database URI from configuration
     * 
     * @param string $sql The SQL statement containing placeholders to be replaced
     * @return string The SQL statement with all placeholders replaced with actual values
     */
    public function adaptMigration (string $sql): string
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
                $this->database->getConfig()['prefix'],
                gethostname(),
                php_uname('s'),
                php_uname('r'),
                $_SERVER['SERVER_ADDR'],
                $this->datetime->utc()->now()->format('Y-m-d H:i:s.u'),
                $this->database->getConfig()['uri'],
            ]
        , $sql);
    }

    /**
     * Logs a message with a timestamp to the console
     * 
     * This private method takes a message as input and outputs it with a timestamp
     * in the format [YYYY-MM-DD HH:MM:SS] followed by a new line character.
     * The method uses PHP's built-in date() function to generate the current timestamp
     * and outputs the formatted message using echo.
     * 
     * @param string $message The message to be logged with timestamp
     * @return void
     */
    private function log($message)
    {
        echo "[" . date("Y-m-d H:i:s") . "] - " . $message . PHP_EOL;
    }
}
