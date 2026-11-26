<?php

declare(strict_types=1);

namespace Atom\Component\Uid;

use Atom\Filter\TypeConvertibleInterface;
use InvalidArgumentException;
use Symfony\Component\Uid\Ulid as SymfonyUlid;

/**
 * ULID value object extending Symfony\Component\Uid\Ulid and participating
 * natively in Atom's automatic DB & Form type filtering.
 */
final class Ulid extends SymfonyUlid implements TypeConvertibleInterface
{
    public function __construct(?string $ulid = null)
    {
        parent::__construct($ulid);
    }

    #[\Override]
    public static function fromDatabase(mixed $value): static
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof SymfonyUlid) {
            return new self($value->toBase32());
        }

        if (\is_string($value)) {
            if (\strlen($value) === 16) {
                return new self(SymfonyUlid::fromBinary($value)->toBase32());
            }
            $trimmed = \trim($value);
            if ($trimmed === '') {
                return new self();
            }
            return new self($trimmed);
        }

        throw new InvalidArgumentException('Cannot hydrate Ulid from value of type ' . \get_debug_type($value));
    }

    #[\Override]
    public static function fromForm(mixed $value): static
    {
        return self::fromDatabase($value);
    }

    #[\Override]
    public function toDatabase(): string
    {
        return $this->toBase32();
    }
}
