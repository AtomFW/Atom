<?php

declare(strict_types=1);

namespace Atom\Component\Uid;

use Atom\Filter\TypeConvertibleInterface;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid as SymfonyUuid;

/**
 * UUID value object extending Symfony\Component\Uid\Uuid and participating
 * natively in Atom's automatic DB & Form type filtering.
 */
final class Uuid extends SymfonyUuid implements TypeConvertibleInterface
{
    public function __construct(string $uuid, bool $checkVariant = false)
    {
        parent::__construct($uuid, $checkVariant);
    }

    #[\Override]
    public static function fromDatabase(mixed $value): static
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof SymfonyUuid) {
            return new self($value->toRfc4122());
        }

        if (\is_string($value)) {
            if (\strlen($value) === 16) {
                return new self(SymfonyUuid::fromBinary($value)->toRfc4122());
            }
            return new self(\trim($value));
        }

        throw new InvalidArgumentException('Cannot hydrate Uuid from value of type ' . \get_debug_type($value));
    }

    #[\Override]
    public static function fromForm(mixed $value): static
    {
        return self::fromDatabase($value);
    }

    #[\Override]
    public function toDatabase(): string
    {
        return $this->toRfc4122();
    }
}
