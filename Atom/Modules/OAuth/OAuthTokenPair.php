<?php

declare(strict_types=1);

namespace Atom\Modules\OAuth;

use Time\Duration;

/**
 * Immutable OAuth 2.0 token pair DTO (Access Token + Refresh Token) with PHP 8.6 `\Time\Duration` lifetimes.
 */
final readonly class OAuthTokenPair
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int|string $userId,
        public string $clientId,
        public Duration $accessTtl,
        public Duration $refreshTtl,
        public int $issuedAt,
        public array $scopes = [],
        public string $tokenType = 'Bearer',
    ) {}

    public function isAccessExpired(int $nowTimestamp): bool
    {
        return ($this->issuedAt + $this->accessTtl->seconds) < $nowTimestamp;
    }

    /**
     * @return array<string, mixed>
     */
    public function toOAuthResponse(): array
    {
        return [
            'token_type' => $this->tokenType,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_in' => $this->accessTtl->seconds,
            'refresh_expires_in' => $this->refreshTtl->seconds,
            'scope' => \implode(' ', $this->scopes),
        ];
    }
}
