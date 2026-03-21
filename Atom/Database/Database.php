<?php

namespace Atom\DataBase;

use Atom\Atom;

/**
 * Database class
 *
 * This class is responsible for connecting to the database and
 * running migrations.
 *
 * @final
 */
final class Database
{
    /**
     * The PDO instance for the database connection.
     *
     * @var \PDO
     */
    public \PDO $pdo;

    /**
     * Constructs a new Database object.
     *
     * This object is responsible for connecting to the database and
     * running migrations.
     *
     * @param array $dbConfig The configuration for the database connection.
     *     The configuration should contain the following keys:
     *     - dsn: The DSN for the database connection.
     *     - user: The username for the database connection.
     *     - password: The password for the database connection.
     */
    public function __construct(array $dbConfig = [])
    {
        $dbDsn = $dbConfig['dsn'] ?? '';
        $username = $dbConfig['user'] ?? '';
        $password = $dbConfig['password'] ?? '';

        $this->pdo = new \PDO($dbDsn, $username, $password);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
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
        foreach ($toApplyMigrations as $migration) {
            if ($migration === '.' || $migration === '..') {
                continue;
            }

            require_once Atom::$ROOT_DIR . '/migrations/' . $migration;
            $className = pathinfo($migration, PATHINFO_FILENAME);
            $instance = new $className();
            $this->log("Applying migration $migration");
            $instance->up();
            $this->log("Applied migration $migration");
            $newMigrations[] = $migration;
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
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )  ENGINE=INNODB;");
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
        $statement = $this->pdo->prepare("SELECT migration FROM migrations");
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
        $str = implode(',', array_map(fn($m) => "('$m')", $newMigrations));
        $statement = $this->pdo->prepare("INSERT INTO migrations (migration) VALUES 
            $str
        ");
        $statement->execute();
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
        return $this->pdo->prepare($sql);
    }

    private function log($message)
    {
        echo "[" . date("Y-m-d H:i:s") . "] - " . $message . PHP_EOL;
    }
}
