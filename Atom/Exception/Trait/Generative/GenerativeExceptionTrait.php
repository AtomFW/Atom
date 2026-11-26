<?php

declare(strict_types=1);

namespace Atom\Exception\Trait\Generative;

trait GenerativeExceptionTrait
{
    public function __construct(string $format, string|int|float ...$params)
    {
        parent::__construct(\sprintf($format, ...$params), (int) $this->code);
    }
}
