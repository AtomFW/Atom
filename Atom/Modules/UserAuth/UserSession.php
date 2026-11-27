<?php

declare(strict_types=1);

namespace Atom\Modules\UserAuth;

use Time\Duration;

/**
 * Immutable value object representing an authenticated user session context (stateless / Swoole-safe).
 */
final readonly class UserSession
{
    public function __construct(
        public int|string $userId,
        public string $sessionId = '',
        public string $ipAddress = '',
        public string $userAgent = '',
        public int $issuedAt = 0,
        public ?Duration $ttl = null,
    ) {}

    public function isExpired(int $nowTimestamp): bool
    {
        if ($this->ttl === null || $this->issuedAt <= 0) {
            return false;
        }

        return ($this->issuedAt + $this->ttl->seconds) < $nowTimestamp;
    }
}
