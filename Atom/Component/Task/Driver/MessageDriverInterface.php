<?php
declare(strict_types=1);

namespace Atom\Component\Task\Driver;

/**
 * Minimal driver interface for queue backends.
 */
interface MessageDriverInterface
{
    /**
     * Return iterable of message IDs (strings).
     * Implementations may return in-queue order.
     *
     * @return iterable<string>
     */
    public function listKeys(): iterable;

    /**
     * Return message envelope by id or null if not found.
     *
     * Envelope is an associative array with keys:
     *  - id: string
     *  - class: string (task class)
     *  - body: mixed (serialized payload)
     *  - attempts: int
     *  - available_at: int (unix ts ms)
     *  - created_at: int (unix ts ms)
     *  - meta: array
     *
     * @param string $key
     * @return array|null
     */
    public function getMessage(string $key): ?array;

    /**
     * Save an envelope into the queue.
     *
     * @param array $envelope
     * @param string $position 'append'|'prepend'
     * @return string saved id
     */
    public function saveMessage(array $envelope, string $position = 'append'): string;

    public function remove(string $key): bool;

    /**
     * Health check
     */
    public function isOnline(): bool;
}