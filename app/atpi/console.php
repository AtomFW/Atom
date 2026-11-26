<?php

declare(strict_types=1);

namespace App\Atpi;

/**
 * Główny punkt wejścia konsoli ATPI obsługujący połączenia i komendy API.
 */
final readonly class ConsoleKernel
{
    public function version(): string
    {
        return 'Atom ATPI 2.1.0 (PHP 8.6.0+)';
    }
}
