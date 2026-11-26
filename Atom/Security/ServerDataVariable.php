<?php

declare(strict_types=1);

namespace Atom\Security;

use Atom\DataBase\AutoMapped;
use Atom\DataBase\Database;
use Atom\DateTime\DateTime;
use DateTimeInterface;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * Stateless repository for server metadata indexed by binary IPv4/IPv6 address with PSR-16 caching.
 */
final class ServerDataVariable
{
    private const int CACHE_TTL_SECONDS = 86400;

    /**
     * @param list<string> $mutableColumns
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
            'metadata',
        ],
    ) {}

    /**
     * @return array<string, mixed>
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

    public function getValue(string $ip, string $key, mixed $default = null): mixed
    {
        return $this->arrayGetByDotPath($this->get($ip), $key, $default);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function set(string $ip, array $data, bool $touchLastActive = true): array
    {
        $binaryIp = $this->ipToBinary($ip);
        $current = $this->fetchByBinaryIp($binaryIp);
        $payload = $this->normalizePayload($data);

        unset(
            $payload[$this->ipBinaryColumn],
            $payload[$this->lastActiveAtColumn],
            $payload[$this->lastUpdateAtColumn],
        );

        $changed = $current === [] || $this->hasChanges($current, $payload);

        if ($current === []) {
            $now = $this->now();
            $insertData = [
                $this->ipBinaryColumn => $binaryIp,
                ...$payload,
                $this->lastUpdateAtColumn => $now,
            ];

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

        $now = $this->now();
        $updateData = $payload;
        $updateData[$this->lastUpdateAtColumn] = $now;

        if ($touchLastActive) {
            $updateData[$this->lastActiveAtColumn] = $now;
        }

        $this->updateRowByBinaryIp($binaryIp, $updateData);
        $this->clearCache($ip);

        return $this->get($ip, true);
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    public function updateLiveStats(string $ip, array $metrics, bool $touchLastActive = true): array
    {
        return $this->set($ip, $metrics, $touchLastActive);
    }

    public function touchLastActiveAt(string $ip): bool
    {
        $binaryIp = $this->ipToBinary($ip);
        $affected = $this->updateRowByBinaryIp($binaryIp, [
            $this->lastActiveAtColumn => $this->now(),
        ]);
        $this->clearCache($ip);

        return $affected > 0;
    }

    public function touchLastUpdateAt(string $ip): bool
    {
        $binaryIp = $this->ipToBinary($ip);
        $affected = $this->updateRowByBinaryIp($binaryIp, [
            $this->lastUpdateAtColumn => $this->now(),
        ]);
        $this->clearCache($ip);

        return $affected > 0;
    }

    public function exists(string $ip): bool
    {
        $binaryIp = $this->ipToBinary($ip);

        $qb = $this->connection->selectFrom($this->tableName)
            ->where($this->ipBinaryColumn . ' = :ip')
            ->setParameter('ip', $binaryIp, ParameterType::BINARY)
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        try {
            return (bool) $result->fetchOne();
        } finally {
            $result->free();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(string $ip): array
    {
        $this->clearCache($ip);
        return $this->get($ip, true);
    }

    public function clearCache(string $ip): void
    {
        $this->cache->delete($this->getCacheKey($ip));
    }

    public function ipToBinary(string $ip): string
    {
        if (\filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException(\sprintf('Invalid IP address: "%s".', $ip));
        }

        $binary = \inet_pton($ip);
        if ($binary === false) {
            throw new InvalidArgumentException(\sprintf('Invalid IP address: "%s".', $ip));
        }

        return $binary;
    }

    public function binaryToIp(string $binaryIp): string
    {
        $len = \strlen($binaryIp);
        if ($len !== 4 && $len !== 16) {
            throw new InvalidArgumentException('Invalid binary IP value.');
        }

        $ip = \inet_ntop($binaryIp);
        if ($ip === false) {
            throw new InvalidArgumentException('Invalid binary IP value.');
        }

        return $ip;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchByIp(string $ip): array
    {
        return $this->fetchByBinaryIp($this->ipToBinary($ip));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchByBinaryIp(string $binaryIp): array
    {
        $qb = $this->connection->selectFrom($this->tableName)
            ->where($this->ipBinaryColumn . ' = :ip')
            ->setParameter('ip', $binaryIp, ParameterType::BINARY)
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        try {
            $row = $result->fetchAssociative();
        } finally {
            $result->free();
        }

        if ($row === false) {
            return [];
        }

        $row = $this->mapDataValues($row);
        return $this->normalizeFetchedRow($row, $binaryIp);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertRow(array $data): void
    {
        $qb = $this->connection->insertInto($this->tableName);

        foreach ($data as $column => $value) {
            $safeCol = \preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?? '';
            if ($safeCol === '') {
                continue;
            }
            $qb->setValue($safeCol, ':' . $safeCol);
            $qb->setParameter(
                $safeCol,
                $value,
                $safeCol === $this->ipBinaryColumn ? ParameterType::BINARY : ParameterType::STRING
            );
        }

        $qb->executeStatement();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updateRowByBinaryIp(string $binaryIp, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $qb = $this->connection->updateTable($this->tableName);

        foreach ($data as $column => $value) {
            $safeCol = \preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?? '';
            if ($safeCol === '') {
                continue;
            }
            $qb->set($safeCol, ':' . $safeCol);
            $qb->setParameter($safeCol, $value);
        }

        $qb->where($this->ipBinaryColumn . ' = :ip')
            ->setParameter('ip', $binaryIp, ParameterType::BINARY);

        return $qb->executeStatement();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeFetchedRow(array $row, string $binaryIp): array
    {
        $row[$this->ipOutputKey] = $this->binaryToIp($binaryIp);
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapDataValues(array $row): array
    {
        if (isset($row['server_uuid']) && (\is_string($row['server_uuid']) || \is_int($row['server_uuid']))) {
            $row['server_uuid'] = AutoMapped::mapValueFromDB($row['server_uuid'], 'uuid');
        }

        return $row;
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizePayload(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (\is_string($key) && $this->isMutableColumn($key)) {
                $normalized[$key] = $this->normalizeValue($value);
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $payload
     */
    private function hasChanges(array $current, array $payload): bool
    {
        foreach ($payload as $column => $newValue) {
            if (!\array_key_exists($column, $current)) {
                return true;
            }
            if ($this->normalizeValue($current[$column]) !== $newValue) {
                return true;
            }
        }

        return false;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (\is_array($value) || \is_object($value)) {
            return \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function arrayGetByDotPath(array $data, string $path, mixed $default = null): mixed
    {
        if ($path === '') {
            return $default;
        }

        $current = $data;
        foreach (\explode('.', $path) as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private function isMutableColumn(string $column): bool
    {
        return \in_array($column, $this->mutableColumns, true);
    }

    private function now(): string
    {
        return $this->datetime->now()->toSQL();
    }

    private function getCacheKey(string $ip): string
    {
        return 'server_' . \bin2hex($this->ipToBinary($ip));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function cacheServerRow(string $ip, array $row): array
    {
        $this->cache->set($this->getCacheKey($ip), $row, self::CACHE_TTL_SECONDS);
        return $row;
    }
}
