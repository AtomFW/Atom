<?php

declare(strict_types=1);

namespace Atom\DataBase;

use Atom\Filter\TypeCaster;

/**
 * Stateless database <-> PHP value mapper delegating to the unified TypeCaster engine.
 */
final readonly class AutoMapped
{
    /**
     * Maps a value retrieved from the database to its typed PHP representation.
     */
    public static function mapValueFromDB(mixed $value, ?string $type): mixed
    {
        return TypeCaster::castFromDatabase($value, $type);
    }

    /**
     * Maps a PHP value to its database representation based on target type.
     */
    public static function mapValueFromPHP(mixed $value, ?string $type): mixed
    {
        return TypeCaster::castToDatabase($value, $type);
    }

    /**
     * Maps a raw form/HTTP input value to its typed PHP representation.
     */
    public static function mapValueFromForm(
        mixed $value,
        ?string $type,
        bool $allowsNull = false,
        int|float|null $min = null,
        int|float|null $max = null,
    ): mixed {
        return TypeCaster::castFromForm(
            value: $value,
            type: $type,
            phpType: null,
            allowsNull: $allowsNull,
            min: $min,
            max: $max,
        );
    }

    /**
     * Maps an entire associative database row using a column => type map.
     *
     * @param array<string, mixed> $row
     * @param array<string, string|array<int, string>|callable(mixed): mixed> $types
     * @return array<string, mixed>
     */
    public static function mapRowFromDB(array $row, array $types): array
    {
        $mapped = [];
        foreach ($row as $column => $value) {
            if (!isset($types[$column])) {
                $mapped[$column] = $value;
                continue;
            }

            $spec = $types[$column];
            if (\is_callable($spec) && !\is_string($spec) && !\is_array($spec)) {
                $mapped[$column] = $spec($value);
                continue;
            }

            $typeName = TypeCaster::extractTypeSpec($spec, forWrite: false);
            $mapped[$column] = $value === null ? null : TypeCaster::castFromDatabase($value, $typeName);
        }

        return $mapped;
    }

    /**
     * Maps an entire associative PHP array to database-ready values using a column => type map.
     *
     * @param array<string, mixed> $row
     * @param array<string, string|array<int, string>|callable(mixed): mixed> $types
     * @return array<string, mixed>
     */
    public static function mapRowFromPHP(array $row, array $types): array
    {
        $mapped = [];
        foreach ($row as $column => $value) {
            if (!isset($types[$column])) {
                $mapped[$column] = $value;
                continue;
            }

            $spec = $types[$column];
            if (\is_callable($spec) && !\is_string($spec) && !\is_array($spec)) {
                $mapped[$column] = $spec($value);
                continue;
            }

            $typeName = TypeCaster::extractTypeSpec($spec, forWrite: true);
            $mapped[$column] = TypeCaster::castToDatabase($value, $typeName);
        }

        return $mapped;
    }

    /**
     * Returns all built-in auto-mapping type identifiers supported without custom classes.
     *
     * @return list<string>
     */
    public static function supportedTypes(): array
    {
        return TypeCaster::supportedTypes();
    }
}
