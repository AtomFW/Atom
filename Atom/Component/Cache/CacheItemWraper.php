<?php

declare(strict_types=1);

namespace Atom\Component\Cache;

use DateInterval;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use Time\Duration;

/**
 * PSR-6 CacheItemInterface decorator adding PHP 8.6 \Time\Duration expiration support.
 */
final readonly class CacheItemWraper implements CacheItemInterface
{
    public function __construct(
        private CacheItemInterface $inner,
    ) {}

    public function unwrap(): CacheItemInterface
    {
        return $this->inner;
    }

    #[\Override]
    public function getKey(): string
    {
        return $this->inner->getKey();
    }

    #[\Override]
    public function get(): mixed
    {
        return $this->inner->get();
    }

    #[\Override]
    public function isHit(): bool
    {
        return $this->inner->isHit();
    }

    #[\Override]
    public function set(mixed $value): static
    {
        $this->inner->set($value);
        return $this;
    }

    #[\Override]
    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->inner->expiresAt($expiration);
        return $this;
    }

    #[\Override]
    public function expiresAfter(int|DateInterval|null $time): static
    {
        $this->inner->expiresAfter($time);
        return $this;
    }

    public function expiresAfterDuration(Duration $duration): static
    {
        $this->inner->expiresAfter($duration->negative ? 0 : $duration->seconds);
        return $this;
    }
}
