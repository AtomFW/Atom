<?php

declare(strict_types=1);

namespace Atom\Interfaces;

interface Structuring
{
    /**
     * @param array<string, mixed> $structuring
     */
    public function structures(array $structuring): void;
}
