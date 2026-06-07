<?php

declare(strict_types=1);

namespace Atom\Component\Uid;

use Symfony\Component\Uid\Uuid as SymfonyUuid;

/**
 * UUID (Universally Unique Identifier) value object that extends Symfony's UUID implementation.
 *
 * This class provides a typed wrapper around UUID values, ensuring type safety and validation
 * when working with universally unique identifiers. It inherits all functionality from
 * Symfony's UUID implementation while providing a clean, predictable interface for UUID handling.
 *
 * The class is designed to be immutable and follows the value object pattern, meaning that
 * any operation that modifies a UUID will return a new instance rather than modifying the existing one.
 *
 * UUIDs are commonly used in distributed systems for creating unique identifiers that don't
 * require coordination between different systems or databases. This implementation ensures
 * proper validation and standardization of UUID formats.
 *
 * Example usage:
 * ```php
 * $uuid = new Uuid('550e8400-e29b-41d4-a716-446655440000');
 * echo $uuid->toString(); // Outputs: 550e8400-e29b-41d4-a716-446655440000
 * ```
 */
final class Uuid extends SymfonyUuid
{
    /**
     * Creates a new UUID instance.
     *
     * @param string $uuid The UUID string to wrap. Must be a valid UUID format.
     * @param bool $checkVariant If true, validates that the UUID follows the standard variant format.
     *                           If false (default), only validates basic format without strict variant checking.
     *
     * @throws \InvalidArgumentException If the provided UUID is not in a valid format.
     */
    public function __construct(string $uuid, bool $checkVariant = false)
    {
        parent::__construct($uuid, $checkVariant);
    }
}
