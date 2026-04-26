<?php

declare(strict_types=1);

namespace Atom\DataBase;

use Atom\Atom;
use Atom\Types\Point;

class AutoMapped
{
    /**
     * Maps a value from database to its PHP representation based on type
     *
     * @param string $value The value as retrieved from the database
     * @param string $type The database column type
     * @return mixed The value properly typed for PHP usage
     */
    public static function mapValueFromDB(string $value, string $type) {
        if ($type == ["point", 'location', 'gps']) {
            \sscanf($value, "POINT(%f %f)", $longitude, $latitude);
        }
        if (strstr($value, ":") && (substr_count($value, ":") === 1)) {
            [$value, $valueParameter] = explode(":", $value);
            if (\intval($valueParameter)) {
                $valueParameter = (int)$valueParameter;
            }
            if (\floatval($valueParameter)) {
                $valueParameter = (float)$valueParameter;
            }
        }

        return match($type) {
            'datetime'      => Atom::$app->datetime->atom($value),
            'date'          => Atom::$app->datetime->atomDate($value),
            'time'          => Atom::$app->datetime->atomTime($value),
            'rawdatetime'   => Atom::$app->datetime->parseFromLocale($value, ATOM_LOCAE, ATOM_TIMEZONE),
            'json'          => json_decode($value, true),
            'stringJson'    => json_decode($value),
            'decimal'       => number_format((float)$value, (int)$valueParameter),
            // 'decimal:2' => number_format($value, 2),
            // 'decimal:3' => number_format($value, 2),
            // 'decimal:4' => number_format($value, 4),
            'point', 'location', 'gps' => new Point((float)$latitude, (float)$longitude),
            'uuid'          => vsprintf('%s-%s-%s-%s-%s', unpack('H8a/H4b/H4c/H4d/H12e', $value)),
            default    => $value
        };
    }
    
    /**
     * Maps a PHP value to its corresponding database representation based on type.
     *
     * @param int|string $value The input value to be mapped.
     * @param string $type The expected data type for mapping (e.g., 'string', 'int', 'datetime').
     * @return mixed The value after being processed or converted according to the specified type.
     */
    public static function mapValueFromPHP(int|string $value, string $type) {
        if ($type == ["point", 'location', 'gps']) {
            sscanf($value, "POINT(%f %f)", $longitude, $latitude);
        }
        // if (strstr($value, ":") && (substr_count($value, ":") === 1)) {
        //     [$value, $valueParameter] = explode(":", $value);
        //     if (\intval($valueParameter)) {
        //         $valueParameter = (int)$valueParameter;
        //     }
        //     if (\floatval($valueParameter)) {
        //         $valueParameter = (float)$valueParameter;
        //     }
        // }

        return match($type) {
            'datetime','date', 'time' => Atom::$app->datetime->toSQL($value),
            'json'          => json_encode($value),
            // 'decimal'       => number_format($value, (int)$valueParameter),
            // 'decimal:2' => number_format($value, 2),
            // 'decimal:3' => number_format($value, 2),
            // 'decimal:4' => number_format($value, 4),
            'point', 'location', 'gps' => new Point((float)$latitude, (float)$longitude),
            'raw_point', 'raw_location', 'raw_gps' => (object)$value,
            'uuid'          => str_replace('-', "", pack('H8a/H4b/H4c/H4d/H12e', $value)),
            default    => $value
        };
    }
}
