<?php

declare(strict_types=1);

namespace Atom\Component\Uid;

use Symfony\Component\Uid\Ulid as SymfonyUlid;

/**
 * ULID (Universally Unique Lexicographically Sortable Identifier) value object that extends Symfony's ULID implementation.
 *
 * This class provides a typed wrapper around ULID values, ensuring type safety and validation
 * when working with universally unique lexicographically sortable identifiers. It inherits all 
 * functionality from Symfony's ULID implementation while providing a clean, predictable interface 
 * for ULID handling.
 *
 * The class is designed to be immutable and follows the value object pattern, meaning that
 * any operation that modifies a ULID will return a new instance rather than modifying the existing one.
 *
 * ULIDs are 26-character strings that are lexicographically sortable and include a timestamp,
 * making them suitable for distributed systems where both uniqueness and ordering matter.
 * This implementation ensures proper validation and standardization of ULID formats.
 *
 * Example usage:
 * ```php
 * $ulid = new Ulid('01ARZ3NDEM0000000000000000');
 * echo $ulid->toString(); // Outputs: 01ARZ3NDEM0000000000000000
 * ```
 */
final class Ulid extends SymfonyUlid
{
    /**
     * Creates a new ULID instance.
     *
     * If no ULID is provided, a new random one will be generated.
     *
     * @param string|null $ulid The ULID string to wrap. If null, a new random ULID will be generated.
     *                          Must be a valid ULID format if provided.
     *
     * @throws \InvalidArgumentException If the provided ULID is not in a valid format.
     */
    public function __construct(?string $ulid = null)
    {
        parent::__construct($ulid);
    }
}
