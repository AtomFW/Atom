<?php

declare(strict_types=1);

namespace Atom\Filter;

use Atom\Atom;
use Atom\Component\Uid\Ulid;
use Atom\Component\Uid\Uuid;
use Atom\Structure\Cast;
use Atom\Types\Point;
use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use SortDirection;
use Time\Duration;

use function array_key_exists;
use function bin2hex;
use function clamp;
use function class_exists;
use function count;
use function enum_exists;
use function explode;
use function filter_var;
use function hex2bin;
use function in_array;
use function inet_ntop;
use function inet_pton;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_object;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function ltrim;
use function method_exists;
use function number_format;
use function pack;
use function preg_match;
use function sscanf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unpack;
use function vsprintf;

/**
 * Unified, reflection-cached type filtering and casting engine for:
 * 1. Database -> PHP Model hydration (castFromDatabase / hydrateModel)
 * 2. PHP Model -> Database persistence (castToDatabase / extractForDatabase)
 * 3. HTTP Form / JSON -> PHP Model binding (castFromForm / populateFromForm)
 *
 * Eliminates repetitive manual casting, prevents strict_types TypeError crashes on form input,
 * avoids mutating live model properties on save(), and keeps bounded metadata per class.
 */
final class TypeCaster
{
    private const int MAX_SCHEMA_CACHE_CLASSES = 512;

    /**
     * Bounded per-class property metadata cache (finite class definitions, safe for Swoole workers).
     *
     * @var array<class-string, array<string, array{
     *     phpType: ?string,
     *     allowsNull: bool,
     *     isBuiltin: bool,
     *     isBackedEnum: bool,
     *     castFromDb: ?string,
     *     castToDb: ?string,
     *     castForm: ?string,
     *     min: int|float|null,
     *     max: int|float|null,
     *     sensitive: bool
     * }>>
     */
    private static array $schemaCache = [];

    /**
     * Inspects and caches reflection metadata for a model/DTO class.
     *
     * @param class-string $className
     * @return array<string, array{
     *     phpType: ?string,
     *     allowsNull: bool,
     *     isBuiltin: bool,
     *     isBackedEnum: bool,
     *     castFromDb: ?string,
     *     castToDb: ?string,
     *     castForm: ?string,
     *     min: int|float|null,
     *     max: int|float|null,
     *     sensitive: bool
     * }>
     */
    public static function schemaFor(string $className): array
    {
        if (isset(self::$schemaCache[$className])) {
            return self::$schemaCache[$className];
        }

        if (count(self::$schemaCache) >= self::MAX_SCHEMA_CACHE_CLASSES) {
            self::$schemaCache = [];
        }

        $reflection = new ReflectionClass($className);
        $schema = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            $name = $prop->getName();
            [$phpType, $allowsNull, $isBuiltin] = self::resolvePropertyType($prop);
            $isBackedEnum = $phpType !== null && !$isBuiltin && enum_exists($phpType) && \is_subclass_of($phpType, BackedEnum::class);

            $castFromDb = null;
            $castToDb = null;
            $castForm = null;
            $min = null;
            $max = null;
            $sensitive = InputFilter::isSensitiveField($name);

            foreach ($prop->getAttributes() as $attr) {
                $attrName = $attr->getName();
                if ($attrName === Cast::class || str_ends_with($attrName, '\\Cast') || $attrName === 'Cast') {
                    if ($attrName === Cast::class) {
                        /** @var Cast $castInstance */
                        $castInstance = $attr->newInstance();
                        $castFromDb = $castInstance->type;
                        $castToDb = $castInstance->detype ?? $castInstance->type;
                        $castForm = $castInstance->formType ?? $castInstance->type;
                        $min = $castInstance->min;
                        $max = $castInstance->max;
                    } else {
                        $args = $attr->getArguments();
                        $castFromDb = isset($args[0]) ? (string) $args[0] : null;
                        $castToDb = array_key_exists(1, $args) && $args[1] !== null ? (string) $args[1] : $castFromDb;
                        $castForm = $castFromDb;
                    }
                } elseif ($attrName === \SensitiveParameter::class) {
                    $sensitive = true;
                }
            }

            $schema[$name] = [
                'phpType' => $phpType,
                'allowsNull' => $allowsNull,
                'isBuiltin' => $isBuiltin,
                'isBackedEnum' => $isBackedEnum,
                'castFromDb' => $castFromDb,
                'castToDb' => $castToDb,
                'castForm' => $castForm,
                'min' => $min,
                'max' => $max,
                'sensitive' => $sensitive,
            ];
        }

        self::$schemaCache[$className] = $schema;

        return $schema;
    }

    /**
     * Safely populates a model/DTO from form/query/JSON data with automatic type coercion.
     *
     * @param array<int|string, mixed> $input
     * @param array<string, string|array<int, string>> $explicitTypes
     */
    public static function populateFromForm(object $target, array $input, array $explicitTypes = []): void
    {
        $schema = self::schemaFor($target::class);

        foreach ($input as $key => $rawValue) {
            if (!is_string($key) || !isset($schema[$key])) {
                continue;
            }

            $meta = $schema[$key];
            $explicitType = isset($explicitTypes[$key]) ? self::extractTypeSpec($explicitTypes[$key], false) : null;
            $typeSpec = $meta['castForm'] ?? $explicitType ?? $meta['phpType'];

            if (is_string($rawValue) && !$meta['sensitive']) {
                $rawValue = InputFilter::cleanString($rawValue, trimWhitespace: true, encodeSpecialChars: false);
            }

            $coerced = self::castFromForm(
                value: $rawValue,
                type: $typeSpec,
                phpType: $meta['phpType'],
                allowsNull: $meta['allowsNull'],
                min: $meta['min'],
                max: $meta['max'],
            );

            $target->{$key} = $coerced;
        }
    }

    /**
     * Hydrates a model instance (or creates a new instance from a class name) from a database associative row.
     * Automatically maps `snake_case` SQL columns to `camelCase` PHP properties when needed.
     *
     * @template T of object
     * @param class-string<T>|T $classOrInstance
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $explicitTypes
     * @return T
     */
    public static function hydrateFromDatabase(
        string|object $classOrInstance,
        array $row,
        ?array $explicitTypes = null,
        bool $allowDynamicFallback = false,
    ): object {
        $instance = is_object($classOrInstance) ? $classOrInstance : new $classOrInstance();
        $className = $instance::class;
        $schema = self::schemaFor($className);

        foreach ($row as $rawKey => $rawValue) {
            $key = (string) $rawKey;
            if (!isset($schema[$key]) && str_contains($key, '_')) {
                $camelKey = \lcfirst(str_replace(' ', '', \ucwords(str_replace('_', ' ', $key))));
                if (isset($schema[$camelKey])) {
                    $key = $camelKey;
                }
            }

            if (!isset($schema[$key])) {
                if ($allowDynamicFallback && \property_exists($instance, $key)) {
                    $instance->{$key} = $rawValue;
                }
                continue;
            }

            $meta = $schema[$key];
            $explicitType = ($explicitTypes !== null && isset($explicitTypes[$key]))
                ? self::extractTypeSpec($explicitTypes[$key], false)
                : null;

            $effectiveType = $explicitType ?? $meta['castFromDb'] ?? $meta['phpType'] ?? 'default';

            if ($rawValue === null && $meta['allowsNull']) {
                $instance->{$key} = null;
                continue;
            }

            $mapped = self::castFromDatabase($rawValue, $effectiveType, $meta['phpType']);

            if ($meta['phpType'] !== null) {
                $mapped = self::coerceToPhpPropertyType($mapped, $meta['phpType'], $meta['allowsNull'], $meta['isBuiltin'], $meta['isBackedEnum']);
            }

            if ($meta['min'] !== null && $meta['max'] !== null && (is_int($mapped) || is_float($mapped))) {
                $mapped = clamp($mapped, $meta['min'], $meta['max']);
            }

            $instance->{$key} = $mapped;
        }

        return $instance;
    }

    /**
     * Extracts database-ready scalar parameters from a model WITHOUT mutating the model's live properties.
     *
     * @param array<int|string, mixed> $propertiesMap
     * @param array<string, mixed> $explicitTypes
     * @return array<string, mixed>
     */
    public static function extractForDatabase(
        object $model,
        array $propertiesMap,
        array $explicitTypes = [],
        bool $snakeCaseKeys = false,
    ): array {
        $schema = self::schemaFor($model::class);
        $bound = [];

        foreach ($propertiesMap as $propName => $columnSpec) {
            $key = is_int($propName) ? (string) $columnSpec : $propName;
            $outKey = !is_int($propName) && is_string($columnSpec)
                ? $columnSpec
                : ($snakeCaseKeys ? strtolower(\preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key) : $key);

            if (!isset($model->{$key}) && (!isset($schema[$key]) || !$schema[$key]['allowsNull'])) {
                continue;
            }

            $rawValue = $model->{$key} ?? null;
            $meta = $schema[$key] ?? null;

            $explicitType = isset($explicitTypes[$key])
                ? self::extractTypeSpec($explicitTypes[$key], true)
                : null;

            $effectiveType = $explicitType ?? $meta['castToDb'] ?? $meta['phpType'] ?? 'default';

            if (stripos($effectiveType, 'ignore') !== false) {
                continue;
            }

            $bound[$outKey] = self::castToDatabase($rawValue, $effectiveType);
        }

        return $bound;
    }

    /**
     * Converts a single raw form/HTTP value to its expected PHP type.
     */
    public static function castFromForm(
        mixed $value,
        ?string $type,
        ?string $phpType = null,
        bool $allowsNull = false,
        int|float|null $min = null,
        int|float|null $max = null,
    ): mixed {
        if ($value === null || ($value === '' && $allowsNull && $phpType !== 'string')) {
            return null;
        }

        $targetType = self::normalizeTypeAlias($type ?? $phpType ?? 'mixed');

        $result = match ($targetType) {
            'int' => self::toInt($value, $allowsNull),
            'float' => self::toFloat($value, $allowsNull),
            'bool' => self::toBool($value, $allowsNull),
            'bool_str' => self::toBool($value, $allowsNull),
            'string' => is_scalar($value) || $value instanceof \Stringable ? (string) $value : '',
            'trimmed' => is_scalar($value) || $value instanceof \Stringable ? trim((string) $value) : '',
            'lower' => is_scalar($value) || $value instanceof \Stringable ? \mb_strtolower(trim((string) $value)) : '',
            'upper' => is_scalar($value) || $value instanceof \Stringable ? \mb_strtoupper(trim((string) $value)) : '',
            'email' => self::normalizeEmail($value),
            'slug' => self::toSlug($value),
            'phone' => self::normalizePhone($value),
            'mac' => self::normalizeMac($value),
            'color', 'hex_color' => self::normalizeHexColor($value),
            'semver' => self::normalizeSemVer($value),
            'ip', 'ip_bin', 'ipv4', 'ipv4_int' => self::normalizeIp($value),
            'uri' => self::toRfc3986Uri($value, $allowsNull),
            'url' => self::toWhatWgUrl($value, $allowsNull),
            'array', 'json', 'json_pretty', 'gzjson' => self::toArray($value),
            'json_list' => \array_values(self::toArray($value)),
            'csv', 'list' => self::toCsvList($value),
            'set' => \array_values(\array_unique(self::toCsvList($value))),
            'int_list', 'csv_int' => self::toIntList($value),
            'float_list', 'csv_float' => self::toFloatList($value),
            'base64' => is_string($value) ? (\base64_decode($value, true) !== false ? \base64_decode($value, true) : $value) : '',
            'hex' => is_string($value) && \ctype_xdigit($value) && (strlen($value) % 2 === 0) ? (hex2bin($value) ?: $value) : (string) $value,
            'money', 'cents' => self::toFloat($value, $allowsNull),
            'percent', 'percentage' => ($pct = self::toFloat($value, $allowsNull)) !== null ? clamp($pct, 0.0, 100.0) : null,
            'point', 'location', 'gps' => self::toPoint($value),
            'uuid_object' => $value instanceof Uuid ? $value : (is_string($value) && $value !== '' ? new Uuid($value) : null),
            'ulid', 'ulid_bin', 'ulid_object' => $value instanceof Ulid ? $value : (is_string($value) && $value !== '' ? new Ulid($value) : null),
            'duration' => self::toDuration($value),
            'sort_direction' => self::toSortDirection($value),
            'timestamp', 'unix_time' => self::toDateTimeImmutable($value, $allowsNull),
            'datetime_immutable', 'date_immutable' => self::toDateTimeImmutable($value, $allowsNull),
            default => self::castCustomFromForm($value, $phpType ?? $type, $allowsNull),
        };

        if ($min !== null && $max !== null && (is_int($result) || is_float($result))) {
            return clamp($result, $min, $max);
        }

        return $result;
    }

    /**
     * Converts a single database value to its PHP representation.
     */
    public static function castFromDatabase(mixed $value, ?string $type, ?string $phpType = null): mixed
    {
        if ($type === null || $type === '' || $type === 'default') {
            return $value;
        }

        $parameter = 0;
        if (str_contains($type, ':')) {
            [$prefix, $paramRaw] = explode(':', $type, 2);
            if (!str_contains($prefix, '\\')) {
                $type = $prefix;
                $parameter = is_numeric($paramRaw)
                    ? (str_contains($paramRaw, '.') ? (float) $paramRaw : (int) $paramRaw)
                    : 0;
            }
        }

        if (str_starts_with($type, 'sql_')) {
            return match ($type) {
                'sql_bin_to_uuid' => is_string($value) && strlen($value) === 16 ? self::binaryToUuid($value) : (string) $value,
                default => $value,
            };
        }

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean', 'bool_str' => self::toBool($value, false),
            'string' => (string) $value,
            'trimmed' => trim((string) $value),
            'lower' => \mb_strtolower(trim((string) $value)),
            'upper' => \mb_strtoupper(trim((string) $value)),
            'email' => self::normalizeEmail($value),
            'slug' => self::toSlug($value),
            'phone' => self::normalizePhone($value),
            'mac' => self::normalizeMac($value),
            'color', 'hex_color' => self::normalizeHexColor($value),
            'semver' => self::normalizeSemVer($value),
            'array' => (array) $value,
            'object' => (object) $value,
            'null' => null,
            'json', 'json_pretty' => is_string($value) ? (json_decode($value, true) ?? []) : (is_array($value) ? $value : []),
            'json_list' => \array_values(is_string($value) ? (array) (json_decode($value, true) ?? []) : (is_array($value) ? $value : [])),
            'gzjson' => self::decodeGzJson($value),
            'stringJson' => is_string($value) ? json_decode($value, false) : (object) $value,
            'csv', 'list' => self::toCsvList($value),
            'set' => \array_values(\array_unique(self::toCsvList($value))),
            'int_list', 'csv_int' => self::toIntList($value),
            'float_list', 'csv_float' => self::toFloatList($value),
            'decimal' => number_format((float) $value, (int) $parameter, '.', ''),
            'money', 'cents' => \round(((float) $value) / 100.0, 2),
            'money_decimal' => number_format(((float) $value) / 100.0, 2, '.', ''),
            'percent', 'percentage' => clamp((float) $value, 0.0, 100.0),
            'base64' => is_string($value) ? (\base64_decode($value, true) !== false ? \base64_decode($value, true) : $value) : (string) $value,
            'hex' => is_string($value) && \ctype_xdigit($value) && (strlen($value) % 2 === 0) ? (hex2bin($value) ?: $value) : (string) $value,
            'binary', 'ip', 'ip_bin' => is_string($value) && (strlen($value) === 4 || strlen($value) === 16)
                ? (inet_ntop($value) ?: $value)
                : (string) $value,
            'ipv4', 'ipv4_int' => is_int($value) || (is_string($value) && \ctype_digit($value))
                ? (\long2ip((int) $value) ?: (string) $value)
                : (string) $value,
            'uri', \Uri\Rfc3986\Uri::class => self::toRfc3986Uri($value, false),
            'url', \Uri\WhatWg\Url::class => self::toWhatWgUrl($value, false),
            'datetime' => self::formatAtomDateTime($value, 'datetime'),
            'date' => self::formatAtomDateTime($value, 'date'),
            'time' => self::formatAtomDateTime($value, 'time'),
            'rawdatetime' => self::formatAtomDateTime($value, 'rawdatetime'),
            'timestamp', 'unix_time' => self::toDateTimeImmutable($value, false),
            'datetime_immutable', 'date_immutable', DateTimeImmutable::class => self::toDateTimeImmutable($value, false),
            'point', 'location', 'gps' => self::toPoint($value),
            'uuid' => is_string($value) ? self::unpackUuid($value) : $value,
            'uuid_bin', 'bin_uuid' => is_string($value) ? self::binaryToUuid($value) : $value,
            'ulid' => is_string($value) ? (strlen($value) === 16 ? Ulid::fromBinary($value)->toBase32() : $value) : $value,
            'ulid_bin', 'bin_ulid' => is_string($value) && strlen($value) === 16 ? Ulid::fromBinary($value)->toBase32() : (string) $value,
            Uuid::class => $value instanceof Uuid ? $value : new Uuid(self::binaryToUuid((string) $value)),
            Ulid::class => $value instanceof Ulid ? $value : (is_string($value) && strlen($value) === 16 ? Ulid::fromBinary($value) : new Ulid((string) $value)),
            Duration::class, 'duration' => self::toDuration($value),
            SortDirection::class, 'sort_direction' => self::toSortDirection($value),
            default => self::castCustomFromDatabase($value, $phpType ?? $type),
        };
    }

    /**
     * Converts a PHP value to its database-storable scalar representation.
     */
    public static function castToDatabase(mixed $value, ?string $type): mixed
    {
        if ($value === null || $type === null || $type === '' || $type === 'default') {
            return $value;
        }

        if ($value instanceof \Symfony\Component\Uid\AbstractUid) {
            if ($type === 'uuid_bin' || $type === 'bin_uuid' || $type === 'sql_uuid_to_bin' || $type === 'ulid_bin' || $type === 'bin_ulid') {
                return $value->toBinary();
            }
            if ($value instanceof Ulid || $type === 'ulid' || $type === Ulid::class) {
                return $value->toBase32();
            }
            return $value->toRfc4122();
        }

        if ($value instanceof \Uri\Rfc3986\Uri) {
            return $value->toString();
        }

        if ($value instanceof \Uri\WhatWg\Url) {
            return $value->toAsciiString();
        }

        if ($value instanceof TypeConvertibleInterface) {
            return $value->toDatabase();
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Duration) {
            $sign = $value->negative ? -1 : 1;
            return $value->nanoseconds === 0
                ? $sign * $value->seconds
                : $sign * ($value->seconds + ($value->nanoseconds / 1_000_000_000));
        }

        if ($value instanceof SortDirection) {
            return $value === SortDirection::Descending ? 'DESC' : 'ASC';
        }

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => $value ? 1 : 0,
            'bool_str' => self::toBool($value, false) ? 'true' : 'false',
            'string' => (string) $value,
            'trimmed' => trim((string) $value),
            'lower' => \mb_strtolower(trim((string) $value)),
            'upper' => \mb_strtoupper(trim((string) $value)),
            'email' => self::normalizeEmail($value),
            'slug' => self::toSlug($value),
            'phone' => self::normalizePhone($value),
            'mac' => self::normalizeMac($value),
            'color', 'hex_color' => self::normalizeHexColor($value),
            'semver' => self::normalizeSemVer($value),
            'uri', \Uri\Rfc3986\Uri::class, 'url', \Uri\WhatWg\Url::class => is_object($value) && method_exists($value, 'toString')
                ? $value->toString()
                : trim((string) $value),
            'datetime', 'date', 'time', 'datetime_immutable', DateTimeImmutable::class => self::formatSqlDateTime($value),
            'date_immutable' => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d')
                : (($dt = self::toDateTimeImmutable($value, true)) !== null ? $dt->format('Y-m-d') : (string) $value),
            'timestamp', 'unix_time' => $value instanceof DateTimeInterface
                ? $value->getTimestamp()
                : (is_numeric($value) ? (int) $value : (($dt = self::toDateTimeImmutable($value, true)) !== null ? $dt->getTimestamp() : 0)),
            'json' => json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            'json_list' => json_encode(\array_values(self::toArray($value)), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            'json_pretty' => json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            'gzjson' => self::encodeGzJson($value),
            'csv', 'list' => \implode(',', self::toCsvList($value)),
            'set' => \implode(',', \array_values(\array_unique(self::toCsvList($value)))),
            'int_list', 'csv_int' => \implode(',', \array_map(strval(...), self::toIntList($value))),
            'float_list', 'csv_float' => \implode(',', \array_map(strval(...), self::toFloatList($value))),
            'money', 'cents', 'money_decimal' => (int) \round(((float) $value) * 100.0),
            'percent', 'percentage' => clamp((float) $value, 0.0, 100.0),
            'base64' => \base64_encode((string) $value),
            'hex' => bin2hex((string) $value),
            'binary', 'ip_bin' => is_string($value) && filter_var($value, \FILTER_VALIDATE_IP) !== false
                ? (inet_pton($value) ?: $value)
                : $value,
            'ip' => self::normalizeIp($value),
            'ipv4', 'ipv4_int' => is_string($value) && filter_var($value, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false
                ? (int) \ip2long($value)
                : (int) $value,
            'point', 'location', 'gps' => self::toPoint($value)->toSql(),
            'raw_point', 'raw_location', 'raw_gps' => (object) $value,
            'uuid' => is_string($value) ? str_replace('-', '', pack('H8a/H4b/H4c/H4d/H12e', $value)) : $value,
            'uuid_bin', 'bin_uuid' => is_string($value) ? self::uuidToBinary($value) : $value,
            'ulid' => is_string($value) ? (new Ulid($value))->toBase32() : $value,
            'ulid_bin', 'bin_ulid' => is_string($value) ? (new Ulid($value))->toBinary() : $value,
            default => self::castCustomToDatabase($value, $type),
        };
    }

    /**
     * Infers the best HTML5 `<input type="...">` from a model's property type/rules/name.
     */
    public static function inferHtmlInputType(object $model, string $attribute): string
    {
        $lower = strtolower($attribute);

        if (str_contains($lower, 'password')) {
            return 'password';
        }
        if (str_contains($lower, 'email')) {
            return 'email';
        }
        if ($lower === 'url' || str_ends_with($lower, 'url') || str_ends_with($lower, 'uri')) {
            return 'url';
        }
        if ($lower === 'phone' || str_contains($lower, 'tel')) {
            return 'tel';
        }

        $schema = self::schemaFor($model::class);
        $meta = $schema[$attribute] ?? null;
        if ($meta === null) {
            return 'text';
        }

        return match ($meta['castForm'] ?? $meta['phpType']) {
            'int', 'integer', 'float', 'double', 'money', 'cents', 'percent', 'percentage' => 'number',
            'bool', 'boolean', 'bool_str' => 'checkbox',
            'email' => 'email',
            'url', 'uri', \Uri\Rfc3986\Uri::class, \Uri\WhatWg\Url::class => 'url',
            'phone' => 'tel',
            'color', 'hex_color' => 'color',
            'date', 'date_immutable' => 'date',
            'time' => 'time',
            'datetime', 'datetime_immutable', DateTimeImmutable::class, DateTimeInterface::class => 'datetime-local',
            default => 'text',
        };
    }

    /**
     * Lists all built-in auto-mapping type identifiers supported out of the box.
     *
     * @return list<string>
     */
    public static function supportedTypes(): array
    {
        return [
            'int',
            'float',
            'bool',
            'bool_str',
            'string',
            'trimmed',
            'lower',
            'upper',
            'email',
            'slug',
            'phone',
            'mac',
            'color',
            'hex_color',
            'semver',
            'ip',
            'ip_bin',
            'ipv4',
            'ipv4_int',
            'uri',
            'url',
            'array',
            'object',
            'json',
            'json_list',
            'json_pretty',
            'gzjson',
            'stringJson',
            'csv',
            'list',
            'set',
            'int_list',
            'float_list',
            'decimal',
            'money',
            'cents',
            'money_decimal',
            'percent',
            'base64',
            'hex',
            'binary',
            'datetime',
            'date',
            'time',
            'rawdatetime',
            'timestamp',
            'datetime_immutable',
            'date_immutable',
            'duration',
            'sort_direction',
            'point',
            'uuid',
            'uuid_bin',
            'ulid',
            'ulid_bin',
        ];
    }

    /**
     * Extracts either the read (index 0) or write (index 1) type from a mixed type specification.
     */
    public static function extractTypeSpec(mixed $spec, bool $forWrite = false): ?string
    {
        if (is_string($spec)) {
            return $spec;
        }

        if (is_array($spec) && $spec !== []) {
            if ($forWrite && array_key_exists(1, $spec)) {
                return $spec[1] !== null ? (string) $spec[1] : null;
            }
            $first = $spec[0] ?? null;
            return $first !== null ? (string) $first : null;
        }

        return null;
    }

    /**
     * Converts a 16-byte binary UUID or 36-char string into canonical RFC 4122 UUID format.
     */
    public static function binaryToUuid(string $value): string
    {
        if (strlen($value) === 36 && preg_match('/^[0-9a-fA-F-]{36}$/', $value) === 1) {
            return strtolower($value);
        }

        if (strlen($value) === 16) {
            $hex = bin2hex($value);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split($hex, 4));
        }

        return $value;
    }

    /**
     * Converts an RFC 4122 UUID string into a 16-byte binary string for `BINARY(16)` columns.
     */
    public static function uuidToBinary(string $uuid): string
    {
        $hex = str_replace('-', '', trim($uuid));
        if (strlen($hex) !== 32 || \ctype_xdigit($hex) === false) {
            throw new \ValueError("Invalid UUID string: {$uuid}");
        }

        $bin = hex2bin($hex);
        if ($bin === false) {
            throw new \ValueError("Failed to convert UUID to binary: {$uuid}");
        }

        return $bin;
    }

    private static function unpackUuid(string $value): string
    {
        if (strlen($value) === 16) {
            $parts = unpack('H8a/H4b/H4c/H4d/H12e', $value);
            return is_array($parts) ? vsprintf('%s-%s-%s-%s-%s', $parts) : $value;
        }

        $parts = unpack('H8a/H4b/H4c/H4d/H12e', $value);
        return is_array($parts) ? vsprintf('%s-%s-%s-%s-%s', $parts) : $value;
    }

    /**
     * @return array{0: ?string, 1: bool, 2: bool}
     */
    private static function resolvePropertyType(ReflectionProperty $prop): array
    {
        $type = $prop->getType();

        if ($type instanceof ReflectionNamedType) {
            return [$type->getName(), $type->allowsNull(), $type->isBuiltin()];
        }

        if ($type instanceof ReflectionUnionType) {
            $allowsNull = $type->allowsNull();
            foreach ($type->getTypes() as $subType) {
                if ($subType instanceof ReflectionNamedType && $subType->getName() !== 'null') {
                    return [$subType->getName(), $allowsNull, $subType->isBuiltin()];
                }
            }
        }

        return [null, true, true];
    }

    private static function normalizeTypeAlias(string $type): string
    {
        $clean = ltrim($type, '?\\');

        return match ($clean) {
            'int', 'integer' => 'int',
            'float', 'double', 'decimal' => 'float',
            'bool', 'boolean' => 'bool',
            'string' => 'string',
            'array' => 'array',
            'json' => 'json',
            'point', 'location', 'gps', Point::class => 'point',
            Uuid::class => 'uuid_object',
            Ulid::class => 'ulid_object',
            Duration::class, 'duration' => 'duration',
            SortDirection::class, 'sort_direction' => 'sort_direction',
            DateTimeImmutable::class => 'datetime_immutable',
            \Uri\Rfc3986\Uri::class => 'uri',
            \Uri\WhatWg\Url::class => 'url',
            default => $clean,
        };
    }

    private static function normalizeEmail(mixed $value): string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }

        return (string) $value
            |> trim(...)
            |> strtolower(...);
    }

    private static function toSlug(mixed $value): string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }

        $ascii = \mb_strtolower(trim((string) $value));
        $slug = \preg_replace('/[^a-z0-9]+/u', '-', $ascii) ?? $ascii;

        return trim($slug, '-');
    }

    private static function normalizePhone(mixed $value): string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }

        $raw = trim((string) $value);
        $hasPlus = str_starts_with($raw, '+');
        $digits = \preg_replace('/\D+/', '', $raw) ?? '';

        return $digits === '' ? '' : ($hasPlus ? '+' . $digits : $digits);
    }

    private static function normalizeMac(mixed $value): string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }

        $hex = strtolower(\preg_replace('/[^0-9a-fA-F]/', '', (string) $value) ?? '');
        if (strlen($hex) !== 12) {
            return trim((string) $value);
        }

        return \implode(':', \str_split($hex, 2));
    }

    private static function normalizeHexColor(mixed $value): string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '#000000';
        }

        $hex = strtolower(ltrim(trim((string) $value), '#'));
        if (strlen($hex) === 3 && \ctype_xdigit($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if ((strlen($hex) === 6 || strlen($hex) === 8) && \ctype_xdigit($hex)) {
            return '#' . $hex;
        }

        return trim((string) $value);
    }

    private static function normalizeSemVer(mixed $value): string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '0.0.0';
        }

        $raw = ltrim(trim((string) $value), 'vV');
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:[-+].*)?$/', $raw, $m) === 1) {
            $major = $m[1];
            $minor = $m[2] ?? '0';
            $patch = $m[3] ?? '0';
            $suffix = substr($raw, strlen($m[1]) + (isset($m[2]) ? 1 + strlen($m[2]) : 0) + (isset($m[3]) ? 1 + strlen($m[3]) : 0));
            return "{$major}.{$minor}.{$patch}{$suffix}";
        }

        return $raw;
    }

    private static function normalizeIp(mixed $value): string
    {
        if (is_int($value)) {
            return \long2ip($value) ?: '';
        }

        if (!is_string($value)) {
            return '';
        }

        if ((strlen($value) === 4 || strlen($value) === 16) && filter_var($value, \FILTER_VALIDATE_IP) === false) {
            $unpacked = inet_ntop($value);
            if ($unpacked !== false) {
                return $unpacked;
            }
        }

        $trimmed = trim($value);
        return filter_var($trimmed, \FILTER_VALIDATE_IP) !== false ? $trimmed : $trimmed;
    }

    private static function toRfc3986Uri(mixed $value, bool $allowsNull): \Uri\Rfc3986\Uri|string|null
    {
        if ($value instanceof \Uri\Rfc3986\Uri) {
            return $value;
        }

        $str = is_scalar($value) || $value instanceof \Stringable ? trim((string) $value) : '';
        if ($str === '') {
            return $allowsNull ? null : '';
        }

        if (class_exists(\Uri\Rfc3986\Uri::class)) {
            try {
                return new \Uri\Rfc3986\Uri($str);
            } catch (\Throwable) {
                return $allowsNull ? null : $str;
            }
        }

        return $str;
    }

    private static function toWhatWgUrl(mixed $value, bool $allowsNull): \Uri\WhatWg\Url|string|null
    {
        if ($value instanceof \Uri\WhatWg\Url) {
            return $value;
        }

        $str = is_scalar($value) || $value instanceof \Stringable ? trim((string) $value) : '';
        if ($str === '') {
            return $allowsNull ? null : '';
        }

        if (class_exists(\Uri\WhatWg\Url::class)) {
            try {
                return new \Uri\WhatWg\Url($str);
            } catch (\Throwable) {
                return $allowsNull ? null : $str;
            }
        }

        return $str;
    }

    /**
     * @return list<string>
     */
    private static function toCsvList(mixed $value): array
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                if (is_scalar($item) || $item instanceof \Stringable) {
                    $s = trim((string) $item);
                    if ($s !== '') {
                        $out[] = $s;
                    }
                }
            }
            return $out;
        }

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return [];
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return [];
        }

        $parts = \array_map(trim(...), explode(',', $trimmed));
        return \array_values(\array_filter($parts, static fn(string $item): bool => $item !== ''));
    }

    /**
     * @return list<int>
     */
    private static function toIntList(mixed $value): array
    {
        $items = self::toCsvList($value);
        return \array_map(intval(...), $items);
    }

    /**
     * @return list<float>
     */
    private static function toFloatList(mixed $value): array
    {
        $items = self::toCsvList($value);
        return \array_map(floatval(...), $items);
    }

    private static function toDateTimeImmutable(mixed $value, bool $allowsNull): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value) || (is_string($value) && $value !== '' && (\ctype_digit($value) || (str_starts_with($value, '-') && \ctype_digit(substr($value, 1)))))) {
            return (new DateTimeImmutable())->setTimestamp((int) $value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return $allowsNull ? null : new DateTimeImmutable('@0');
            }
            try {
                return new DateTimeImmutable($trimmed);
            } catch (\Throwable) {
                return $allowsNull ? null : new DateTimeImmutable('@0');
            }
        }

        return $allowsNull ? null : new DateTimeImmutable('@0');
    }

    private static function encodeGzJson(mixed $value): string
    {
        $json = json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }

        $compressed = \gzdeflate($json, 6);
        return $compressed !== false ? $compressed : $json;
    }

    /**
     * @return array<mixed>
     */
    private static function decodeGzJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
            $direct = json_decode($value, true);
            return is_array($direct) ? $direct : [];
        }

        \set_error_handler(static fn(): bool => true);
        try {
            $inflated = \gzinflate($value);
        } finally {
            \restore_error_handler();
        }

        if (!is_string($inflated)) {
            return [];
        }

        $decoded = json_decode($inflated, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function toInt(mixed $value, bool $allowsNull): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return $allowsNull ? null : 0;
            }
            $validated = filter_var($trimmed, \FILTER_VALIDATE_INT);
            if ($validated !== false) {
                return $validated;
            }
            if (is_numeric($trimmed)) {
                return (int) $trimmed;
            }
        }

        return $allowsNull ? null : 0;
    }

    private static function toFloat(mixed $value, bool $allowsNull): ?float
    {
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $normalized = str_replace(',', '.', trim($value));
            if ($normalized === '') {
                return $allowsNull ? null : 0.0;
            }
            $validated = filter_var($normalized, \FILTER_VALIDATE_FLOAT);
            if ($validated !== false) {
                return $validated;
            }
        }

        return $allowsNull ? null : 0.0;
    }

    private static function toBool(mixed $value, bool $allowsNull): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }
        if (is_string($value)) {
            $low = strtolower(trim($value));
            if ($low === '' && $allowsNull) {
                return null;
            }
            return in_array($low, ['1', 'true', 'on', 'yes', 'y'], true);
        }

        return $allowsNull ? null : (bool) $value;
    }

    /**
     * @return array<mixed>
     */
    private static function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            return [$value];
        }

        return (array) $value;
    }

    private static function toPoint(mixed $value): Point
    {
        if ($value instanceof Point) {
            return $value;
        }

        if (is_array($value)) {
            $lat = (float) ($value['latitude'] ?? $value['lat'] ?? $value[0] ?? 0.0);
            $lng = (float) ($value['longitude'] ?? $value['lng'] ?? $value['lon'] ?? $value[1] ?? 0.0);
            return new Point($lat, $lng);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (stripos($trimmed, 'POINT') === 0) {
                $first = 0.0;
                $second = 0.0;
                if (str_contains($trimmed, ',')) {
                    sscanf($trimmed, 'POINT(%f, %f)', $first, $second);
                } else {
                    sscanf($trimmed, 'POINT(%f %f)', $first, $second);
                }
                return new Point((float) $first, (float) $second);
            }

            if (str_contains($trimmed, ',')) {
                [$lat, $lng] = array_map(trim(...), explode(',', $trimmed, 2));
                return new Point((float) $lat, (float) $lng);
            }
        }

        return new Point(0.0, 0.0);
    }

    private static function toDuration(mixed $value): Duration
    {
        if ($value instanceof Duration) {
            return $value;
        }
        if (is_int($value)) {
            return Duration::fromSeconds($value);
        }
        if (is_float($value)) {
            return Duration::fromMicroseconds((int) \round($value * 1_000_000));
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if (str_starts_with($trimmed, 'P') || str_starts_with($trimmed, '-P')) {
                return Duration::fromIso8601DurationString($trimmed);
            }
            if (is_numeric($trimmed)) {
                return str_contains($trimmed, '.')
                    ? Duration::fromMicroseconds((int) \round(((float) $trimmed) * 1_000_000))
                    : Duration::fromSeconds((int) $trimmed);
            }
        }

        return Duration::fromSeconds(0);
    }

    private static function toSortDirection(mixed $value): SortDirection
    {
        if ($value instanceof SortDirection) {
            return $value;
        }

        if (is_string($value) && strtolower(trim($value)) === 'desc') {
            return SortDirection::Descending;
        }

        return SortDirection::Ascending;
    }

    private static function formatAtomDateTime(mixed $value, string $mode): mixed
    {
        if (!isset(Atom::$app->datetime)) {
            return (string) $value;
        }

        return match ($mode) {
            'datetime' => Atom::$app->datetime->atom($value),
            'date' => Atom::$app->datetime->atomDate($value),
            'time' => Atom::$app->datetime->atomTime($value),
            'rawdatetime' => Atom::$app->datetime->parseFromLocale(
                (string) $value,
                \defined('ATOM_LOCALE') ? \ATOM_LOCALE : (\defined('ATOM_LOCAE') ? \ATOM_LOCAE : 'en'),
                \defined('ATOM_TIMEZONE') ? \ATOM_TIMEZONE : 'UTC',
            ),
            default => (string) $value,
        };
    }

    private static function formatSqlDateTime(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }

        if (isset(Atom::$app->datetime)) {
            return (string) Atom::$app->datetime->toSQL($value);
        }

        return (string) $value;
    }

    private static function castCustomFromForm(mixed $value, ?string $className, bool $allowsNull): mixed
    {
        if ($className === null || $className === '') {
            return $value;
        }

        $cleanClass = ltrim($className, '?\\');

        if (enum_exists($cleanClass) && \is_subclass_of($cleanClass, BackedEnum::class)) {
            if ($value instanceof $cleanClass) {
                return $value;
            }
            if (is_int($value) || is_string($value)) {
                return $cleanClass::tryFrom($value) ?? ($allowsNull ? null : $cleanClass::cases()[0]);
            }
        }

        if (class_exists($cleanClass)) {
            if ($value instanceof $cleanClass) {
                return $value;
            }
            if (\is_subclass_of($cleanClass, TypeConvertibleInterface::class)) {
                return $cleanClass::fromForm($value);
            }
            if (method_exists($cleanClass, 'convertFromForm')) {
                return $cleanClass::convertFromForm($value);
            }
        }

        return $value;
    }

    private static function castCustomFromDatabase(mixed $value, string $className): mixed
    {
        $cleanClass = ltrim($className, '?\\');

        if (enum_exists($cleanClass) && \is_subclass_of($cleanClass, BackedEnum::class)) {
            if ($value instanceof $cleanClass) {
                return $value;
            }
            if (is_int($value) || is_string($value)) {
                return $cleanClass::tryFrom($value) ?? $value;
            }
        }

        if (class_exists($cleanClass)) {
            if ($value instanceof $cleanClass) {
                return $value;
            }
            if (\is_subclass_of($cleanClass, TypeConvertibleInterface::class)) {
                return $cleanClass::fromDatabase($value);
            }
            if (method_exists($cleanClass, 'convertFromDB')) {
                return $cleanClass::convertFromDB($value);
            }
            if (method_exists($cleanClass, 'toPHP')) {
                return (new $cleanClass())->toPHP($value);
            }
        }

        return $value;
    }

    private static function castCustomToDatabase(mixed $value, string $className): mixed
    {
        $cleanClass = ltrim($className, '?\\');

        if (is_object($value)) {
            if (method_exists($value, 'convertFromPHP')) {
                return $value->convertFromPHP();
            }
            if (method_exists($value, 'toSQL')) {
                return $value->toSQL($value);
            }
            if (method_exists($value, 'toSql')) {
                return $value->toSql();
            }
        }

        if (class_exists($cleanClass)) {
            if (method_exists($cleanClass, 'convertFromPHP')) {
                return $cleanClass::convertFromPHP($value);
            }
            if (method_exists($cleanClass, 'toSQL')) {
                return (new $cleanClass())->toSQL($value);
            }
        }

        return $value;
    }

    private static function coerceToPhpPropertyType(
        mixed $value,
        string $phpType,
        bool $allowsNull,
        bool $isBuiltin,
        bool $isBackedEnum,
    ): mixed {
        if ($value === null) {
            return $allowsNull ? null : match ($phpType) {
                'int' => 0,
                'float' => 0.0,
                'bool' => false,
                'string' => '',
                'array' => [],
                default => null,
            };
        }

        if ($isBuiltin) {
            return match ($phpType) {
                'int' => is_int($value) ? $value : (int) $value,
                'float' => is_float($value) ? $value : (float) $value,
                'bool' => is_bool($value) ? $value : self::toBool($value, false),
                'string' => is_string($value) ? $value : (is_scalar($value) || $value instanceof \Stringable ? (string) $value : ''),
                'array' => is_array($value) ? $value : (array) $value,
                default => $value,
            };
        }

        if ($isBackedEnum && !$value instanceof $phpType && (is_int($value) || is_string($value))) {
            /** @var class-string<BackedEnum> $phpType */
            return $phpType::tryFrom($value) ?? $value;
        }

        return $value;
    }
}
