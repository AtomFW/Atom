<?php

declare(strict_types=1);

namespace Atom\Modules\AtomCluster;

use Atom\DataBase\Database;
use Atom\DateTime\DateTime;
use PDO;
use RuntimeException;
use SensitiveParameter;

/**
 * Provides a unified `Atom\DataBase\Database` interface over a remote shared cluster database
 * (or injected PDO/Database connection) so every Atom node in the cluster interacts with SQL
 * through the exact same `Database` contract.
 */
final class ClusterDatabaseManager
{
    private ?Database $database = null;

    /** @var array<string, mixed> */
    private array $remoteConfig;

    /**
     * @param array<string, mixed> $remoteConfig
     */
    public function __construct(
        #[SensitiveParameter]
        array $remoteConfig = [],
        ?Database $prebuiltDatabase = null,
    ) {
        $this->remoteConfig = $remoteConfig;
        $this->database = $prebuiltDatabase;
    }

    public function isEnabled(): bool
    {
        return $this->database !== null || !empty($this->remoteConfig['on']);
    }

    public function shouldBindAsPrimary(): bool
    {
        return !empty($this->remoteConfig['bindAsPrimary']);
    }

    /**
     * Attaches an existing `PDO` connection wrapped inside the unified `Atom\DataBase\Database` facade.
     */
    public function bindPdo(PDO $pdo, string $prefix = ''): Database
    {
        $db = Database::fromExistingPdo($pdo);
        $db->setTablePrefix($prefix !== '' ? $prefix : (string) ($this->remoteConfig['prefix'] ?? ''));
        $this->database = $db;

        return $db;
    }

    /**
     * Lazily resolves and returns the unified remote cluster `Database` instance.
     */
    public function connection(?DateTime $datetime = null, string $uri = 'http://localhost'): Database
    {
        if ($this->database !== null) {
            return $this->database;
        }

        if (!$this->isEnabled()) {
            throw new RuntimeException('Remote cluster database is disabled in config/cluster.php.');
        }

        $dt = $datetime ?? new DateTime();
        $dsn = (string) ($this->remoteConfig['dsn'] ?? '');
        if ($dsn === '') {
            $host = (string) ($this->remoteConfig['host'] ?? '127.0.0.1');
            $port = (int) ($this->remoteConfig['port'] ?? 3306);
            $dbname = (string) ($this->remoteConfig['dbname'] ?? 'AtomCluster');
            $charset = (string) ($this->remoteConfig['charset'] ?? 'utf8mb4');
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
        }

        $timeout = \clamp((int) ($this->remoteConfig['timeoutSeconds'] ?? 5), 1, 60);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => $timeout,
        ];

        $this->database = new Database($dt, [
            'dsn' => $dsn,
            'driver' => (string) ($this->remoteConfig['driver'] ?? 'mysql'),
            'user' => (string) ($this->remoteConfig['user'] ?? 'root'),
            'password' => (string) ($this->remoteConfig['password'] ?? ''),
            'prefix' => (string) ($this->remoteConfig['prefix'] ?? ''),
            'options' => $options,
            'uri' => $uri,
        ]);

        return $this->database;
    }
}
