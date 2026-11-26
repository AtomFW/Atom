<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Stateless string utility module loaded dynamically by `Atom\Modules\Modul`.
 */
final readonly class StringMasterUtils
{
    public const string VERSION = '1.0.0';

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config = [],
    ) {}

    public function run(string $input): string
    {
        $prefix = (string) ($this->config['prefix'] ?? '');
        return $prefix . \strrev($input);
    }
}
