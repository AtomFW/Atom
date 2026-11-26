<?php

declare(strict_types=1);

namespace Atom\Filter;

/**
 * Unified contract for custom value objects participating in automatic
 * database and form type conversion.
 */
interface TypeConvertibleInterface
{
    /**
     * Hydrates a PHP value object from a raw database value.
     */
    public static function fromDatabase(mixed $value): static;

    /**
     * Hydrates a PHP value object from raw form / HTTP request input.
     */
    public static function fromForm(mixed $value): static;

    /**
     * Converts the value object into its scalar/SQL-bindable database representation.
     */
    public function toDatabase(): int|float|string|bool|null;
}
