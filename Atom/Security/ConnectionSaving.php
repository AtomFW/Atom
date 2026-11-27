<?php

declare(strict_types=1);

namespace Atom\Security;

use Atom\Atom;
use Atom\DataBase\AutoMapped;
use Atom\DataBase\Database;
use Atom\DateTime\DateTime;
use Atom\Log\T4LOG;
use RuntimeException;

use function array_map;
use function date;
use function filter_var;
use function is_string;
use function str_contains;
use function str_replace;
use function time;

/**
 * Persists client connection metadata into the `connections` table when DB is enabled,
 * or writes structured connection entries directly to `T4LOG` in `noDB` / `microservice` mode.
 */
final class ConnectionSaving
{
    private static ?Database $database = null;
    private static ?DateTime $datetime = null;
    private static int $serverId = 0;

    /**
     * @return array<string, string>
     */
    protected static function attributesTypes(): array
    {
        return [
            'ip' => 'binary',
            'datetime' => 'datetime',
            'raw_details' => 'json',
        ];
    }

    /**
     * @return list<string>
     */
    protected static function attributes(): array
    {
        return [
            'ip',
            'isp',
            'lang',
            'city',
            'country',
            'user_agent',
            'browser_sec',
            'coordinates',
            'area_code',
            'dma_code',
            'region',
            'unique_id',
            'server_id',
            'datetime',
            'raw_details',
        ];
    }

    public function __construct(
        ?Database $database,
        DateTime $datetime,
        int $serverId = 0,
    ) {
        self::$database = $database;
        self::$datetime = $datetime;
        self::$serverId = $serverId;
    }

    public static function changeDatabase(?Database $database): void
    {
        self::$database = $database;
    }

    public static function changeDateTime(DateTime $datetime): void
    {
        self::$datetime = $datetime;
    }

    public static function changeServerId(int $serverId): void
    {
        self::$serverId = $serverId;
    }

    /**
     * Records connection metadata to standard `T4LOG` files without any database access (`noDB` / `microservice` mode).
     *
     * @param array<string, mixed> $browserDetector
     * @param array<string, mixed> $connectionInformation
     * @param array<string, mixed> $botDetector
     * @return array<string, mixed>
     */
    public static function logOnly(
        T4LOG $logger,
        array $browserDetector,
        array $connectionInformation,
        array $botDetector,
        ?DateTime $datetime = null,
    ): array {
        if ($datetime !== null) {
            self::$datetime = $datetime;
        }

        $payload = self::dataStructure($browserDetector, $connectionInformation, $botDetector);
        $payload['mode'] = 'noDB';

        $logger->info('Client connection recorded (noDB/stateless mode).', $payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $browserDetector
     * @param array<string, mixed> $connectionInformation
     * @param array<string, mixed> $botDetector
     */
    public static function add(
        array $browserDetector,
        array $connectionInformation,
        array $botDetector,
    ): int {
        if (isset(Atom::$app) && Atom::$app->isNoDbMode()) {
            if (isset(Atom::$app->log)) {
                self::logOnly(Atom::$app->log, $browserDetector, $connectionInformation, $botDetector);
            }

            return 0;
        }

        if (self::$database === null) {
            throw new RuntimeException('Connection saving requires an active Database instance.');
        }

        $normalizeData = self::dataStructure($browserDetector, $connectionInformation, $botDetector);

        $mapKeysToValues = [];
        foreach ($normalizeData as $key => $value) {
            if (is_string($value) && str_contains($value, 'POINT')) {
                $mapKeysToValues[$value] = $key;
                continue;
            }
            $mapKeysToValues[$key] = $key;
        }

        $attributesToBindsProperty = self::$database->attributesToBindsProperty($mapKeysToValues);
        unset($attributesToBindsProperty['x'], $attributesToBindsProperty['y']);

        $replacePointPrefix = str_replace(':POINT', 'POINT', ?);
        $attributesToBindsProperty = array_map($replacePointPrefix, $attributesToBindsProperty);

        $types = self::attributesTypes();
        foreach ($normalizeData as $key => $value) {
            if (isset($types[$key])) {
                $normalizeData[$key] = AutoMapped::mapValueFromPHP($value, $types[$key]);
            }
        }

        $insert = self::$database
            ->insertInto('connections')
            ->values($attributesToBindsProperty)
            ->setParameters($normalizeData);

        $statement = $insert->executeQuery();
        try {
            if ($statement->rowCount() === 0) {
                throw new RuntimeException('Connection saving failed');
            }
        } finally {
            $statement->free();
        }

        return (int) self::$database->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $browserDetector
     * @param array<string, mixed> $connectionInformation
     * @param array<string, mixed> $botDetector
     * @return array<string, mixed>
     */
    private static function dataStructure(
        array $browserDetector,
        array $connectionInformation,
        array $botDetector,
    ): array {
        $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        if (filter_var($remoteIp, \FILTER_VALIDATE_IP) === false) {
            $remoteIp = '127.0.0.1';
        }

        $rawDetails = [
            'browser' => $browserDetector,
            'connection' => $connectionInformation,
            'bot' => $botDetector,
        ];

        $timestampSql = self::$datetime !== null
            ? self::$datetime->now()->toSQL()
            : date('Y-m-d H:i:s');

        return [
            'ip' => $remoteIp,
            'isp' => $remoteIp,
            'lang' => (string) ($_SERVER['GEOIP_COUNTRY_ISO_CODE'] ?? $_SERVER['GEOIP_COUNTRY_CODE'] ?? ''),
            'city' => (string) ($_SERVER['GEOIP_CITY'] ?? ''),
            'country' => (string) ($_SERVER['GEOIP_COUNTRY_NAME'] ?? ''),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'browser_sec' => $_SERVER['HTTP_SEC_CH_UA'] ?? null,
            'coordinates' => 'POINT(:x, :y)',
            'area_code' => $_SERVER['GEOIP_AREA_CODE'] ?? $_SERVER['GEOIP_SUBDIVISION_GEONAME_ID'] ?? null,
            'dma_code' => $_SERVER['GEOIP_DMA_CODE'] ?? $_SERVER['GEOIP_CONTINENT_GEONAME_ID'] ?? null,
            'region' => $_SERVER['GEOIP_REGION'] ?? $_SERVER['GEOIP_REGISTERED_COUNTRY_GEONAME_ID'] ?? 0,
            'unique_id' => $_SERVER['GEOIP_UNIQUE_ID'] ?? $_SERVER['UNIQUE_ID'] ?? ($_SERVER['REQUEST_TIME'] ?? time()),
            'server_id' => self::getIdServerByIp(),
            'datetime' => $timestampSql,
            'raw_details' => $rawDetails,
            'x' => (float) ($_SERVER['GEOIP_LOCATION_LATITUDE'] ?? $_SERVER['GEOIP_LATITUDE'] ?? 0.0),
            'y' => (float) ($_SERVER['GEOIP_LOCATION_LONGITUDE'] ?? $_SERVER['GEOIP_LONGITUDE'] ?? 0.0),
        ];
    }

    private static function getIdServerByIp(): int
    {
        return self::$serverId;
    }
}
