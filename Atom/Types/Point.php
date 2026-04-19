<?php

declare(strict_types=1);

namespace Atom\Types;

readonly class Point 
{
    public function __construct(
        public float $latitude,
        public float $longitude
    ) {}

    public function toString(): string 
    {
        return "{$this->latitude},{$this->longitude}";
    }

    public function toFormat (string $format): string
    {
        return \sprintf($format, $this->latitude, $this->longitude);
    }

    public function toSql(): string
    {
        return "POINT({$this->latitude} {$this->longitude})";
    }
}
