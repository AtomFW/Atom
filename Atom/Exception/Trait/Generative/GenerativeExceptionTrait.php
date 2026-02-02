<?php

namespace Atom\Exception\Trait\Generative;

trait GenerativeExceptionTrait
{
    public function __construct(string $format, ...$params)
    {
        $this->message = \sprintf($format, ...$params);
    }
}
