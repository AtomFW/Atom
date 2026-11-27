<?php

declare(strict_types=1);

namespace Atom\Modules\OAuth;

use InvalidArgumentException;
use Random\Engine\Secure;
use Random\Randomizer;
use SensitiveParameter;

/**
 * RFC 7636 Proof Key for Code Exchange (PKCE) utility enforcing `S256` challenge verification.
 */
final readonly class OAuthPkce
{
    public static function generateCodeVerifier(int $byteLength = 32): string
    {
        $bytes = (new Randomizer(new Secure()))->getBytes(\clamp($byteLength, 32, 64));
        return \rtrim(\strtr(\base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function computeS256Challenge(#[SensitiveParameter] string $codeVerifier): string
    {
        if (\strlen($codeVerifier) < 43 || \strlen($codeVerifier) > 128) {
            throw new InvalidArgumentException('PKCE code_verifier must be between 43 and 128 characters.');
        }

        $digest = \hash('sha256', $codeVerifier, true);
        return \rtrim(\strtr(\base64_encode($digest), '+/', '-_'), '=');
    }

    public static function verifyChallenge(
        #[SensitiveParameter]
        string $codeVerifier,
        string $expectedChallenge,
        string $method = 'S256',
        bool $allowPlain = false,
    ): bool {
        if ($expectedChallenge === '' || \strlen($codeVerifier) < 43 || \strlen($codeVerifier) > 128) {
            return false;
        }

        $upperMethod = \strtoupper(\trim($method));
        if ($upperMethod === 'S256') {
            $computed = self::computeS256Challenge($codeVerifier);
            return \hash_equals($expectedChallenge, $computed);
        }

        if ($upperMethod === 'PLAIN' && $allowPlain) {
            return \hash_equals($expectedChallenge, $codeVerifier);
        }

        return false;
    }
}
