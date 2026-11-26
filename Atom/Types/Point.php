<?php

declare(strict_types=1);

namespace Atom\Types;

use Atom\Filter\TypeConvertibleInterface;
use InvalidArgumentException;
use Stringable;

use function clamp;
use function explode;
use function is_array;
use function is_string;
use function preg_replace;
use function sprintf;
use function sscanf;
use function str_contains;
use function stripos;
use function trim;

/**
 * Immutable 2D geographic/cartesian coordinate value object.
 *
 * Implements TypeConvertibleInterface so DbModel and Form/Model::loadData()
 * automatically hydrate and serialize coordinates without manual boilerplate.
 */
final readonly class Point implements TypeConvertibleInterface, Stringable
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    /**
     * Creates a geographic Point with latitude clamped to [-90, 90] and longitude to [-180, 180].
     */
    public static function fromGps(float $latitude, float $longitude): self
    {
        return new self(
            clamp($latitude, -90.0, 90.0),
            clamp($longitude, -180.0, 180.0),
        );
    }

    #[\Override]
    public static function fromDatabase(mixed $value): static
    {
        return self::parse($value);
    }

    #[\Override]
    public static function fromForm(mixed $value): static
    {
        return self::parse($value);
    }

    #[\Override]
    public function toDatabase(): string
    {
        return $this->toSql();
    }

    /**
     * Converts the point to a "lat,lng" string representation.
     */
    public function toString(): string
    {
        return "{$this->latitude},{$this->longitude}";
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Formats the point coordinates according to the provided printf format string.
     */
    public function toFormat(string $format): string
    {
        return sprintf($format, $this->latitude, $this->longitude);
    }

    /**
     * Returns the SQL POINT representation: POINT(lat, lng).
     */
    public function toSql(): string
    {
        return "POINT({$this->latitude}, {$this->longitude})";
    }

    /**
     * Returns standard OGC Well-Known Text (WKT) representation: POINT(lng lat).
     */
    public function toWkt(): string
    {
        return "POINT({$this->longitude} {$this->latitude})";
    }

    /**
     * Builds a parameterized SQL POINT(:x, :y) expression with strict identifier sanitization.
     */
    public function toPrepareSql(string $xName, string $yName): string
    {
        $safeX = preg_replace('/[^a-zA-Z0-9_]/', '', $xName) ?? '';
        $safeY = preg_replace('/[^a-zA-Z0-9_]/', '', $yName) ?? '';

        if ($safeX === '' || $safeY === '') {
            throw new InvalidArgumentException('SQL placeholder names for Point must be non-empty alphanumeric identifiers.');
        }

        return "POINT(:{$safeX}, :{$safeY})";
    }

    private static function parse(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_array($value)) {
            $lat = (float) ($value['latitude'] ?? $value['lat'] ?? $value[0] ?? 0.0);
            $lng = (float) ($value['longitude'] ?? $value['lng'] ?? $value['lon'] ?? $value[1] ?? 0.0);
            return new self($lat, $lng);
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
                return new self((float) $first, (float) $second);
            }

            if (str_contains($trimmed, ',')) {
                [$lat, $lng] = array_map(trim(...), explode(',', $trimmed, 2));
                return new self((float) $lat, (float) $lng);
            }
        }

        return new self(0.0, 0.0);
    }
}
