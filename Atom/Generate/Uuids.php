<?php

declare(strict_types=1);

namespace Atom\Generate;

use Atom\Component\Uid\Ulid;
use Atom\Component\Uid\Uuid;
use InvalidArgumentException;
use Iterator;
use Override;
use Random\Engine\Secure;
use Random\Randomizer;

/**
 * Cryptographically secure infinite ID / token stream generator (`Iterator<int, string>`).
 *
 * @implements Iterator<int, string>
 */
final class Uuids implements Iterator
{
    public const string MODE_ULID = 'ulid';
    public const string MODE_UUID7 = 'uuid7';
    public const string MODE_RANDOM = 'random';
    public const string MODE_HYBRID = 'hybrid';

    private const string ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private readonly Randomizer $rng;
    private readonly string $mode;
    private readonly int $length;
    private readonly int $extra;

    private int $position = 0;
    private string $current;

    public function __construct(
        string $mode = self::MODE_ULID,
        int $length = 32,
        int $extra = 16,
        ?Randomizer $rng = null,
    ) {
        $this->mode = $mode;
        $this->length = \max(1, $length);
        $this->extra = \max(1, $extra);
        $this->rng = $rng ?? new Randomizer(new Secure());

        $this->current = $this->generate();
    }

    private function generate(): string
    {
        return match ($this->mode) {
            self::MODE_ULID => (new Ulid())->toBase32(),
            self::MODE_UUID7 => Uuid::v7()->toBase32(),
            self::MODE_RANDOM => $this->rng->getBytesFromString(self::ALPHABET, $this->length),
            self::MODE_HYBRID => (new Ulid())->toBase32() . '.' . $this->rng->getBytesFromString(self::ALPHABET, $this->extra),
            default => throw new InvalidArgumentException("Unknown mode: {$this->mode}"),
        };
    }

    #[Override]
    public function current(): string
    {
        return $this->current;
    }

    #[Override]
    public function key(): int
    {
        return $this->position;
    }

    #[Override]
    public function next(): void
    {
        $this->position++;
        $this->current = $this->generate();
    }

    #[Override]
    public function rewind(): void
    {
        $this->position = 0;
        $this->current = $this->generate();
    }

    #[Override]
    public function valid(): bool
    {
        return true;
    }

    public function nextId(): string
    {
        $this->next();
        return $this->current;
    }

    public function nextInt(int $min = 0, int $max = \PHP_INT_MAX): int
    {
        return $this->rng->getInt($min, $max);
    }

    public function nextBytes(int $length = 32): string
    {
        return $this->rng->getBytes(\max(1, $length));
    }

    public function nextString(int $length = 32): string
    {
        return $this->rng->getBytesFromString(self::ALPHABET, \max(1, $length));
    }
}
