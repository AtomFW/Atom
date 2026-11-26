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
     * Maps a scalar value retrieved from the database to its typed PHP representation.
     */
    public static function mapValueFromDB(int|string $value, ?string $type): mixed
    {
        return TypeCaster::castFromDatabase($value, $type);
    }

    /**
     * Maps a PHP value to its database representation based on target type.
     */
    public static function mapValueFromPHP(int|float|bool|string|object|array|null $value, ?string $type): mixed
    {
        return TypeCaster::castToDatabase($value, $type);
    }
}
