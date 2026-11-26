<?php

declare(strict_types=1);

namespace Atom\Structure;

use Attribute;

/**
 * Declares automatic type conversion rules for a model or DTO property
 * across Database reads (`$type`), Database writes (`$detype`), and Form input (`$formType`).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Cast
{
    public function __construct(
        public string $type,
        public ?string $detype = null,
        public ?string $formType = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
    ) {}
}
