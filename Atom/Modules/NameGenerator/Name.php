<?php

declare(strict_types=1);

namespace Atom\Modules\NameGenerator;

use Random\Engine\Secure;
use Random\Randomizer;

/**
 * Cryptographically secure random identifier / token / name generator for PHP 8.6+.
 */
final readonly class Name
{
    public const string VERSION = '1.0.0';

    private const string ALPHANUMERIC = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * Generates a cryptographically secure random alphanumeric name of clamped length.
     */
    public function generate(int $length = 16, string $prefix = ''): string
    {
        $safeLength = \clamp($length, 1, 256);
        $randomizer = new Randomizer(new Secure());

        return $prefix . $randomizer->getBytesFromString(self::ALPHANUMERIC, $safeLength);
    }
}
