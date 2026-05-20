<?php

declare(strict_types=1);

namespace Atom\Generate;

use Random\Engine\Secure;
use Random\Randomizer;
use Atom\Component\Uid\Ulid;
use Atom\Component\Uid\Uuid;

/**
 * UUIDs class for handling UUID operations
 *
 * This class provides functionality for generating and managing UUIDs
 * using various methods and formats.
 */
final class Uuids implements Iterator
{
    public const MODE_ULID   = 'ulid';
    public const MODE_UUID7  = 'uuid7';
    public const MODE_RANDOM = 'random';
    public const MODE_HYBRID = 'hybrid';

    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private Randomizer $rng;
    private string $mode;
    private int $length;
    private int $extra;

    private int $position = 0;
    private string $current;

    /**
     * Constructor for Uuids class
     *
     * @param string $mode
     * @param int $length
     * @param int $extra
     * @param Randomizer|null $rng
     */
    public function __construct(
        string $mode = self::MODE_ULID,
        int $length = 32,
        int $extra = 16,
        ?Randomizer $rng = null
    ) {
        $this->mode = $mode;
        $this->length = $length;
        $this->extra = $extra;
        $this->rng = $rng ?? new Randomizer(new Secure());

        $this->current = $this->generate();
    }

    /* =========================
     * Core generator
     * ========================= */

    private function generate(): string
    {
        // Generate UUID based on the selected mode
        return match ($this->mode) {
            self::MODE_ULID   => (new Ulid())->toBase32(),
            self::MODE_UUID7  => Uuid::v7()->toBase32(),
            self::MODE_RANDOM => $this->rng->getBytesFromString(self::ALPHABET, $this->length),
            self::MODE_HYBRID => (new Ulid())->toBase32() . '.' .
                                 $this->rng->getBytesFromString(self::ALPHABET, $this->extra),
            default => throw new InvalidArgumentException("Unknown mode: {$this->mode}")
        };
    }

    /* =========================
     * Iterator implementation
     * ========================= */

    /**
     * @return string
     */
    public function current(): string
    {
        return $this->current;
    }

    /**
     * @return int
     */
    public function key(): int
    {
        return $this->position;
    }

    /**
     * Generate the next UUID in the sequence.
     *
     * This method advances the internal pointer and generates a new UUID
     * based on the configured mode (ulid, uuid7, random, or hybrid).
     */
    public function next(): void
    {
        $this->position++;
        $this->current = $this->generate();
    }

    /**
     * Rewind the iterator to the first element.
     * This method resets the internal position to 0 and generates a new value.
     *
     * @return void
     */
    public function rewind(): void
    {
        $this->position = 0;
        $this->current = $this->generate();
    }

    /**
     * @return bool True if the current position is valid, false otherwise.
     *              In this implementation, it always returns true since there's no limit
     *              on how many times we can call next().
     */
    public function valid(): bool
    {
        return true; // infinite generator
    }

    /* =========================
     * Extra helpers (next-style)
     * ========================= */

    /**
     * Generates and returns the next ID, advancing the internal pointer.
     *
     * This method is a helper that combines moving the iterator forward with
     * retrieving the new value. It's useful when you want to get the next
     * generated identifier without manually calling `next()` and `current()`.
     *
     * @return string The next generated ID based on the configured mode.
     */
    public function nextId(): string
    {
        $this->next();
        return $this->current;
    }

    /**
     * Generates the next pseudo-random integer between $min and $max.
     *
     * @param int $min The minimum value to be returned, inclusive.
     * @param int $max The maximum value to be returned, inclusive.
     * @return int A random integer value within the specified range.
     */
    public function nextInt(int $min = 0, int $max = PHP_INT_MAX): int
    {
        return $this->rng->getInt($min, $max);
    }

    /**
     * Generates the specified number of random bytes.
     *
     * This method uses the internal randomizer to generate a string of bytes,
     * which can be used for cryptographic purposes or other needs requiring
     * cryptographically secure randomness.
     *
     * @param int $length The number of bytes to generate. Defaults to 32.
     * @return string Returns a string containing the specified number of random bytes.
     */
    public function nextBytes(int $length = 32): string
    {
        return $this->rng->getBytes($length);
    }

    /**
     * Generates the next random string of specified length using the configured alphabet.
     *
     * @param int $length The length of the random string to generate (default: 32)
     * @return string The generated random string
     */
    public function nextString(int $length = 32): string
    {
        return $this->rng->getBytesFromString(self::ALPHABET, $length);
    }
}
