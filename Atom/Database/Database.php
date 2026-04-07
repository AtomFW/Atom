<?php

namespace Atom\DataBase;

use Atom\Atom;
use Atom\DateTime\DateTime;
use SebastianBergmann\CodeCoverage\Report\PHP;
use Atom\Database\SmartConnection;

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
     * The configuration for the database connection.
     *
     * @var array
     */
    private array $config;


    /**
     * The SmartConnection instance for the database connection.
     *
     * @var SmartConnection
     */
    public SmartConnection $smartConnection;

    public function getConfig (): array
    {
        return $this->config;
    }

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
    public function __construct(private DateTime $datetime, array $dbConfig = [])
    {
        $this->config = $dbConfig;

        $dbDsn = $dbConfig['dsn'] ?? '';
        $username = $dbConfig['user'] ?? '';
        $password = $dbConfig['password'] ?? '';

        $option = [
            \PDO::ATTR_PERSISTENT => true,
        ];

        $this->pdo = new \PDO($dbDsn, $username, $password, $option);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->smartConnection = SmartConnection::fromExistingPdo($this->pdo);
    }
}
