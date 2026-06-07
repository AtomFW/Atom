<?php

declare(strict_types=1);

namespace Atom\Exception\Interface\Generative;

interface GenerativeExceptionInterface
{
    public function __construct(string $format, ...$params);
}
