<?php

declare(strict_types=1);

namespace Atom\Security;

use Atom\DataBase\AutoMapped;
use Atom\DataBase\Database;
use Atom\DateTime\DateTime;
use Atom\Types\Point;
use Doctrine\DBAL\ParameterType;

/**
 * Class ConnectionSaving
 * Class for saving connection data
 * @package Atom\Security
 */
final class ConnectionSaving {
    private static Database $database;
    private static DateTime $datetime;
    private static int $serverId;

    /**
     * Returns an array of column names mapped to their respective
     * @return array
     */
    protected static function attributesTypes(): array
    {
        return [
            'ip' => "binary",
            'datetime' => "datetime",
            'raw_details' => "json",
        ];
    }

    /**
     * Returns an array of column names mapped to their respective
     * @return array
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
            'raw_details'
        ];
    }

    /**
     * ConnectionSaving constructor.
     * @param Database $database
     * @param DateTime $datetime    
     */
    public function __construct(
        Database $database,
        DateTime $datetime,
        int $serverId
    ) {
        self::$database = $database;
        self::$datetime = $datetime;
        self::$serverId = $serverId;
    }

    /**
     * @param Database $database
     */
    public static function changeDatabase (Database $database) {
        self::$database = $database;
    }

    /**
     * @param DateTime $datetime
     */
    public static function changeDateTime (DateTime $datetime) {
        self::$datetime = $datetime;
    }
    
    /**
     * @param int $serverId
     */
    public static function changeServerId (int $serverId) {
        self::$serverId = $serverId;
    }

    /**
     * @param array $browserDetector
     * @param array $connectionInformation
     * @param array $botDetector
     * @return int
     * @throws \Exception
     */
    public static function add (array $browserDetector, array $connectionInformation, array $botDetector): int
    {

        $normalizeData = self::dataStructure($browserDetector, $connectionInformation, $botDetector);

        $mapKeysToValues = [];
        foreach ($normalizeData as $key => $value) {
            if (\is_string($value) && strstr((string)$value, "POINT")) {
                $mapKeysToValues[$value] = $key;
                continue;
            }
            $mapKeysToValues[$key] = $key;
        }

        $attributesToBindsProperty = self::$database->attributesToBindsProperty($mapKeysToValues);
        unset($attributesToBindsProperty['x']);
        unset($attributesToBindsProperty['y']);
        $attributesToBindsProperty = array_map(function ($value) {
            return str_replace(":POINT", "POINT", $value);
        }, $attributesToBindsProperty);

        foreach ($normalizeData as $key => $value) {
            if (!isset(self::attributesTypes()[$key])) {
                continue;
            }

            $normalizeData[$key] = AutoMapped::mapValueFromPHP($value, self::attributesTypes()[$key]);
        }

        $insert = self::$database->insertInto("connections")->values($attributesToBindsProperty)->setParameters($normalizeData);

        $statement = $insert->executeQuery();

        if ($statement->rowCount() === 0) {
            throw new \Exception("Connection saving failed");
        }

        $statement->free();

        return (int)self::$database->pdo->lastInsertId();
    }

    /**
     * @param array $browserDetector
     * @param array $connectionInformation
     * @param array $botDetector
     * @return array
     */
    private static function dataStructure(array $browserDetector, array $connectionInformation, array $botDetector) {
        $geolocation = "POINT(:x, :y)";

        $data = [
            'browser' => $browserDetector,
            'connection' => $connectionInformation,
            'bot' => $botDetector,
        ];

        $datetime = self::$datetime->now()->toSQL();

        $idServer = self::getIdServerByIp();

        return [
            'ip' => inet_pton($_SERVER["REMOTE_ADDR"]),
            'isp' => gethostbyaddr($_SERVER["REMOTE_ADDR"]),
            'lang' => $_SERVER["GEOIP_COUNTRY_ISO_CODE"],
            'city' => $_SERVER["GEOIP_CITY"],
            'country' => $_SERVER["GEOIP_COUNTRY_NAME"],
            'user_agent' => $_SERVER["HTTP_USER_AGENT"],
            'browser_sec' => $_SERVER["HTTP_SEC_CH_UA"],
            'coordinates' => $geolocation,
            'area_code' => $_SERVER["GEOIP_AREA_CODE"] ?? $_SERVER["GEOIP_SUBDIVISION_GEONAME_ID"],
            'dma_code' => $_SERVER["GEOIP_DMA_CODE"] ?? $_SERVER["GEOIP_CONTINENT_GEONAME_ID"],
            'region' => $_SERVER["GEOIP_REGION"] ?? $_SERVER["GEOIP_REGISTERED_COUNTRY_GEONAME_ID"],
            'unique_id' => $_SERVER["GEOIP_UNIQUE_ID"] ?? $_SERVER["REQUEST_TIME"],
            'server_id' => $idServer,
            'datetime' => $datetime,
            'raw_details' => $data,
            'x' => $_SERVER["GEOIP_LOCATION_LATITUDE"],
            'y' => $_SERVER["GEOIP_LOCATION_LONGITUDE"]
        ];
    }

    /**
     * @return int
     */
    private static function getIdServerByIp (): int
    {
        return self::$serverId;
    }
}
