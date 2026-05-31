<?php

declare(strict_types=1);

namespace Atom\Security;

use Atom\DataBase\AutoMapped;
use Atom\DataBase\Database;
use Atom\DateTime\DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\SimpleCache\CacheInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Class ServerRepository
 *
 * Provides read/write access to server records stored by binary IP address.
 * Supports PSR-16 caching, dynamic metric updates, and automatic timestamp management.
 */
final class ServerDataVariable
{
    private const CACHE_TTL_SECONDS = 86400; // 24 hours

    /**
     * @param Database $connection Doctrine DBAL connection instance.
     * @param CacheInterface $cache PSR-16 cache implementation.
     * @param DateTime $datetime Atom Datetime class suit
     * @param string $tableName Database table name.
     * @param string $ipBinaryColumn Column name that stores the binary IP address.
     * @param string $ipOutputKey Array key used for the human-readable IP address.
     * @param string $lastActiveAtColumn Column name storing the last activity timestamp.
     * @param string $lastUpdateAtColumn Column name storing the last data update timestamp.
     * @param string[] $mutableColumns List of columns that can be updated dynamically.
     */
    public function __construct(
        private readonly Database $connection,
        private readonly CacheInterface $cache,
        private readonly DateTime $datetime,
        private readonly string $tableName = 'servers',
        private readonly string $ipBinaryColumn = 'ip_address',
        private readonly string $ipOutputKey = 'ip',
        private readonly string $lastActiveAtColumn = 'last_online_at',
        private readonly string $lastUpdateAtColumn = 'updated_at',
        private readonly array $mutableColumns = [
            'hostname',
            'os_name',
            'os_version',
            'ip_address',
            'is_active',
            'cpu_cores',
            'total_ram_mb',
            'total_disk_gb',
            'cpu_load',
            'used_ram_mb',
            'used_disk_gb',
            'last_online_at',
            'metadata'
        ],
    ) {
    }

    /**
     * Returns full server data for the given IP address.
     *
     * The method reads from cache first and falls back to the database when
     * the cache is empty or a refresh is forced.
     *
     * @param string $ip Server IP address in IPv4 or IPv6 format.
     * @param bool $forceRefresh When true, bypasses cache and reloads data from the database.
     * @return array<string, mixed> Server record data.
     */
    public function get(string $ip, bool $forceRefresh = false): array
    {
        $cacheKey = $this->getCacheKey($ip);

        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if (\is_array($cached)) {
                return $cached;
            }
        }

        $row = $this->fetchByIp($ip);

        if ($row !== []) {
            $this->cache->set($cacheKey, $row, self::CACHE_TTL_SECONDS);
        }

        return $row;
    }

    /**
     * Returns a single value from the server record.
     *
     * Dot notation is supported for nested arrays, for example: "meta.cpu.model".
     *
     * @param string $ip Server IP address in IPv4 or IPv6 format.
     * @param string $key Value key or dot-notated path.
     * @param mixed $default Default value returned when the key does not exist.
     * @return mixed
     */
    public function getValue(string $ip, string $key, mixed $default = null): mixed
    {
        $row = $this->get($ip);

        return $this->arrayGetByDotPath($row, $key, $default);
    }

    /**
     * Inserts or updates a server record.
     *
     * Only columns listed in $mutableColumns are accepted. When any mutable
     * value changes, last_update_at is refreshed automatically. When $touchLastActive
     * is true, last_active_at is also updated.
     *
     * @param string $ip Server IP address in IPv4 or IPv6 format.
     * @param array<string, mixed> $data Associative array of columns and values.
     * @param bool $touchLastActive Whether to update last_active_at as well.
     * @return array<string, mixed> Fresh server record after persistence.
     */
    public function set(string $ip, array $data, bool $touchLastActive = true): array
    {
        $binaryIp = $this->ipToBinary($ip);
        $current = $this->fetchByBinaryIp($binaryIp);

        $payload = $this->normalizePayload($data);

        unset(
            $payload[$this->ipBinaryColumn],
            $payload[$this->lastActiveAtColumn],
            $payload[$this->lastUpdateAtColumn]
        );

        $changed = $current === [] ? true : $this->hasChanges($current, $payload);

        if ($current === []) {
            $now = $this->now();

            $insertData = array_merge(
                [$this->ipBinaryColumn => $binaryIp],
                $payload,
                [$this->lastUpdateAtColumn => $now]
            );

            if ($touchLastActive) {
                $insertData[$this->lastActiveAtColumn] = $now;
            }

            $this->insertRow($insertData);
            $this->clearCache($ip);

            return $this->get($ip, true);
        }

        if (!$changed) {
            return $this->cacheServerRow($ip, $current);
        }

        $updateData = $payload;
        $updateData[$this->lastUpdateAtColumn] = $this->now();

        if ($touchLastActive) {
            $updateData[$this->lastActiveAtColumn] = $this->now();
        }

        $this->updateRowByBinaryIp($binaryIp, $updateData);
        $this->clearCache($ip);

        return $this->get($ip, true);
    }

    /**
     * Updates live server metrics such as CPU, RAM, disk usage, load, or network statistics.
     *
     * This is a semantic wrapper around set() intended for real-time monitoring data.
     *
     * @param string $ip Server IP address in IPv4 or IPv6 format.
     * @param array<string, mixed> $metrics Associative array of live metrics.
     * @param bool $touchLastActive Whether to update last_active_at as well.
     * @return array<string, mixed> Fresh server record after persistence.
     */
    public function updateLiveStats(string $ip, array $metrics, bool $touchLastActive = true): array
    {
        return $this->set($ip, $metrics, $touchLastActive);
    }

    /**
     * Updates only the last_active_at timestamp.
     *
     * @param string $ip Server IP address in IPv4 or IPv6 format.
     * @return bool True on successful update, false otherwise.
     */
    public function touchLastActiveAt(string $ip): bool
    {
        $binaryIp = $this->ipToBinary($ip);

        $affected = $this->updateRowByBinaryIp($binaryIp, [
            $this->lastActiveAtColumn => $this->now(),
        ]);

        $this->clearCache($ip);

        return $affected > 0;
    }

    /**
     * Updates only the last_update_at timestamp.
     *
     * @param string $ip Server IP address in IPv4 or IPv6 format.
     * @return bool True on successful update, false otherwise.
     */
    public function touchLastUpdateAt(string $ip): bool
    {
        $binaryIp = $this->ipToBinary($ip);

        $affected = $this->updateRowByBinaryIp($binaryIp, [
            $this->lastUpdateAtColumn => $this->now(),
        ]);

        $this->clearCache($ip);

        return $affected > 0;
    }

    /**
     * Checks whether a server record exists for the given IP address.
     *
     * @param string $ip Server IP address in IPv4 or IPv6 format.
     * @return bool True if the record exists, false otherwise.
     */
    public function exists(string $ip): bool
    {
        $binaryIp = $this->ipToBinary($ip);

        $qb = $this->connection->selectFrom($this->tableName)
            ->where($this->ipBinaryColumn . ' = :ip')
            ->setParameter('ip', $binaryIp, ParameterType::BINARY)
            ->setMaxResults(1);

        return (bool) $qb->executeQuery()->fetchOne();
    }

    /**
     * Forces a fresh database read and stores the result in cache.
     *
     * @param string $ip Server IP address in IPv4 or IPv6 format.
     * @return array<string, mixed> Fresh server record.
     */
    public function refresh(string $ip): array
    {
        $this->clearCache($ip);

        return $this->get($ip, true);
    }

    /**
     * Removes the cached entry for the given IP address.
     *
     * @param string $ip Server IP address in IPv4 or IPv6 format.
     */
    public function clearCache(string $ip): void
    {
        $this->cache->delete($this->getCacheKey($ip));
    }

    /**
     * Converts a textual IP address to binary representation.
     *
     * @param string $ip Server IP address in IPv4 or IPv6 format.
     * @return string Binary IP representation.
     */
    public function ipToBinary(string $ip): string
    {
        $binary = inet_pton($ip);

        if ($binary === false) {
            throw new InvalidArgumentException(sprintf('Invalid IP address: "%s".', $ip));
        }

        return $binary;
    }

    /**
     * Converts a binary IP representation to its textual form.
     *
     * @param string $binaryIp Binary IP value.
     * @return string IPv4 or IPv6 string representation.
     */
    public function binaryToIp(string $binaryIp): string
    {
        $ip = inet_ntop($binaryIp);

        if ($ip === false) {
            throw new InvalidArgumentException('Invalid binary IP value.');
        }

        return $ip;
    }

    /**
     * Fetches a server record by textual IP address.
     *
     * @param string $ip Server IP address in IPv4 or IPv6 format.
     * @return array<string, mixed> Server record or an empty array when not found.
     */
    private function fetchByIp(string $ip): array
    {
        return $this->fetchByBinaryIp($this->ipToBinary($ip));
    }

    /**
     * Fetches a server record by binary IP address.
     *
     * @param string $binaryIp Binary IP representation.
     * @return array<string, mixed> Server record or an empty array when not found.
     */
    private function fetchByBinaryIp(string $binaryIp): array
    {
        $qb = $this->connection->selectFrom($this->tableName)
            ->where($this->ipBinaryColumn . ' = :ip')
            ->setParameter('ip', $binaryIp, ParameterType::BINARY)
            ->setMaxResults(1);

        $row = $qb->executeQuery()->fetchAssociative();

        if ($row === false) {
            return [];
        }

        $row = $this->mapDataValues($row);

        return $this->normalizeFetchedRow($row, $binaryIp);
    }

    /**
     * Inserts a new database row.
     *
     * @param array<string, mixed> $data Associative array of column names and values.
     */
    private function insertRow(array $data): void
    {
        $qb = $this->connection->insertInto($this->tableName);

        foreach ($data as $column => $value) {
            $qb->setValue($column, ':' . $column);
            $qb->setParameter(
                $column,
                $value,
                $column === $this->ipBinaryColumn ? ParameterType::BINARY : null
            );
        }

        $qb->executeStatement();
    }

    /**
     * Updates a database row identified by binary IP address.
     *
     * @param string $binaryIp Binary IP representation.
     * @param array<string, mixed> $data Associative array of column names and values.
     * @return int Number of affected rows.
     */
    private function updateRowByBinaryIp(string $binaryIp, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $qb = $this->connection->updateTable($this->tableName);

        foreach ($data as $column => $value) {
            $qb->set($column, ':' . $column);
            $qb->setParameter($column, $value);
        }

        $qb->where($this->ipBinaryColumn . ' = :ip')
            ->setParameter('ip', $binaryIp, ParameterType::BINARY);

        return $qb->executeStatement();
    }

    /**
     * Adds the human-readable IP address to a fetched database row.
     *
     * @param array<string, mixed> $row Raw database row.
     * @param string $binaryIp Binary IP representation.
     * @return array<string, mixed> Normalized row.
     */
    private function normalizeFetchedRow(array $row, string $binaryIp): array
    {
        $row[$this->ipOutputKey] = $this->binaryToIp($binaryIp);

        return $row;
    }

    /**
     * Maps database values to their corresponding PHP types.
     *
     * @param array<string, mixed> $row Raw database row.
     * @return array<string, mixed> Mapped row.
     */
    private function mapDataValues(array $row): array
    {
        $row["server_uuid"] = AutoMapped::mapValueFromDB($row["server_uuid"], "uuid");

        return $row;
    }

    /**
     * Filters and normalizes incoming payload values.
     *
     * @param array<string, mixed> $data Input data.
     * @return array<string, mixed> Normalized payload.
     */
    private function normalizePayload(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }

            if (!$this->isMutableColumn($key)) {
                continue;
            }

            $normalized[$key] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    /**
     * Determines whether the payload differs from the current database row.
     *
     * @param array<string, mixed> $current Current database row.
     * @param array<string, mixed> $payload Incoming payload.
     * @return bool True when at least one value differs.
     */
    private function hasChanges(array $current, array $payload): bool
    {
        foreach ($payload as $column => $newValue) {
            if (!\array_key_exists($column, $current)) {
                return true;
            }

            $oldValue = $this->normalizeValue($current[$column]);

            if ($oldValue !== $newValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizes a value before database storage or comparison.
     *
     * @param mixed $value Input value.
     * @return mixed Normalized value.
     */
    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (\is_array($value) || \is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    /**
     * Reads a nested value from an array using dot notation.
     *
     * @param array<string, mixed> $data Source array.
     * @param string $path Dot-notated key path.
     * @param mixed $default Default value when the path does not exist.
     * @return mixed
     */
    private function arrayGetByDotPath(array $data, string $path, mixed $default = null): mixed
    {
        if ($path === '') {
            return $default;
        }

        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Checks whether the given column is allowed to be updated dynamically.
     *
     * @param string $column Column name.
     * @return bool True when the column is mutable.
     */
    private function isMutableColumn(string $column): bool
    {
        return \in_array($column, $this->mutableColumns, true);
    }

    /**
     * Returns the current timestamp string used for persistence.
     *
     * @return string Current datetime in Y-m-d H:i:s format.
     */
    private function now(): string
    {
        return $this->datetime->now()->toSQL();
    }

    /**
     * Builds the cache key for a given IP address.
     *
     * @param string $ip Server IP address in IPv4 or IPv6 format.
     * @return string Cache key.
     */
    private function getCacheKey(string $ip): string
    {
        return 'server_' . bin2hex($this->ipToBinary($ip));
    }

    /**
     * Stores a row in cache and returns it unchanged.
     *
     * @param string $ip Server IP address in IPv4 or IPv6 format.
     * @param array<string, mixed> $row Server record.
     * @return array<string, mixed> Cached server record.
     */
    private function cacheServerRow(string $ip, array $row): array
    {
        $this->cache->set($this->getCacheKey($ip), $row, self::CACHE_TTL_SECONDS);

        return $row;
    }
}
