<?php

declare(strict_types=1);

namespace Atom\Component\Task\Driver;

/**
 * Minimal driver interface for queue backends.
 */
interface MessageDriverInterface
{
    /**
     * @return iterable<string>
     */
    public function listKeys(): iterable;

    /**
     * @return array<string, mixed>|null
     */
    public function getMessage(string $key): ?array;

    /**
     * @param array<string, mixed> $envelope
     */
    public function saveMessage(array $envelope, string $position = 'append'): string;

    public function remove(string $key): bool;

    public function isOnline(): bool;
}
