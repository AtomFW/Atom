<?php

declare(strict_types=1);

namespace Atom\DataBase;

use Atom\Atom;
use Atom\Types\Point;

/**
 * The AutoMapped class is a database abstraction layer that provides:
 * - Database connection management with configuration support
 * - Table name handling with prefix functionality
 * - Type conversion utilities for database operations
 * - Query building assistance through method chaining
 * - Transaction handling capabilities
 * - Identifier quoting and escaping
 * 
 * This class serves as a foundation for database interactions in PHP applications,
 * abstracting away low-level database specifics while providing convenient utility methods.
 * It's designed to work with Doctrine DBAL for database operations and provides
 * additional helpful methods for common database tasks.
 * 
 * Key features include:
 * - Dynamic table name resolution with prefix support
 * - Type conversion between PHP and database formats
 * - Automated transaction handling
 * - Flexible column/property name mapping
 * - Support for different database drivers through Doctrine DBAL
 * 
 * Usage example:
 * ```php
 * $db = new AutoMapped($config);
 * $users = $db->table('users')->where('active', 1)->fetchAll();
 * ```
 */
class AutoMapped
{
    /**
     * Maps a value from database to its PHP representation based on type
     *
     * @param int|string $value The value as retrieved from the database
     * @param string $type The database column type
     * @return mixed The value properly typed for PHP usage
     */
    public static function mapValueFromDB(int|string $value, string $type) {
        if ($type == ["point", 'location', 'gps']) {
            \sscanf($value, "POINT(%f %f)", $longitude, $latitude);
        }
        if (strstr((string)$value, ":") && (substr_count($value, ":") === 1)) {
            [$value, $valueParameter] = explode(":", $value);
            if (\intval($valueParameter)) {
                $valueParameter = (int)$valueParameter;
            }
            if (\floatval($valueParameter)) {
                $valueParameter = (float)$valueParameter;
            }
        }
        if (substr_count($type, "\\") >= 3) {
            if (class_exists($type)) {
                $class = new $type();
                $type = "class";
            }
        }

        return match($type) {
            'int'           => (int)$value,
            'float'         => (float)$value,
            'boolean'       => (bool)$value,
            'string'        => (string)$value,
            'array'         => (array)$value,
            'object'        => (object)$value,
            'null'          => null,
            'datetime'      => Atom::$app->datetime->atom($value),
            'date'          => Atom::$app->datetime->atomDate($value),
            'time'          => Atom::$app->datetime->atomTime($value),
            'rawdatetime'   => Atom::$app->datetime->parseFromLocale($value, ATOM_LOCAE, ATOM_TIMEZONE),
            'json'          => json_decode($value, true),
            'stringJson'    => json_decode($value),
            'decimal'       => number_format((float)$value, (int)$valueParameter),
            'point', 'location', 'gps' => new Point((float)$latitude, (float)$longitude),
            'uuid'          => vsprintf('%s-%s-%s-%s-%s', unpack('H8a/H4b/H4c/H4d/H12e', $value)),
            'class' => $class->toPHP($value),
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
    public static function mapValueFromPHP(int|string|object|array|null $value, string $type): mixed
    {
        if ($value == null) {
            return $value;
        }

        if (\in_array($type, ['point', 'location', 'gps'])) {
            sscanf($value, "%f,%f", $longitude, $latitude);
        }

        if (substr_count($type, "\\") >= 3) {
            if (class_exists($type)) {
                $class = new $type();
                $type = "class";
            }
        }

        return match($type) {
            'datetime','date', 'time'               => Atom::$app->datetime->toSQL($value),
            'json'                                  => json_encode($value),
            'point', 'location', 'gps'              => new Point((float)$latitude, (float)$longitude)->toSql(),
            'raw_point', 'raw_location', 'raw_gps'  => (object)$value,
            'uuid'                                  => str_replace('-', "", pack('H8a/H4b/H4c/H4d/H12e', $value)),
            'class'                                 => $class->toSQL($value),
            default                                 => $value
        };
    }
}
