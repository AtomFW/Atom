<?php

declare(strict_types=1);

namespace Atom\Cache;

use Atom\Cache\SysvIpcException;
use Atom\Cache\SysvIpcSupport;
use Atom\Cache\SysvSemaphoreManager;
use Atom\Cache\SysvSharedMemoryManager;


/**
 * System V IPC (Inter-Process Communication) message queue manager
 *
 * This class provides a wrapper around System V message queue operations to enable
 * communication between multiple processes through message passing. It's used to
 * implement asynchronous messaging and inter-process communication in shared memory
 * environments.
 *
 * The message queue is created using the SysvIpcSupport::deriveKey() function to
 * generate a unique key based on the base key, namespace, and a component name.
 * This ensures that different queue instances don't interfere with each other.
 *
 * @internal This class is part of the internal SysvIpc implementation
 * @see SysvIpc
 */
final class SysvQueueManager
{
    private SysvMessageQueue $queue;

    /**
     * Creates a new System V message queue for inter-process communication
     *
     * This constructor initializes a System V message queue that can be used to
     * send and receive messages between multiple processes. The queue is created
     * with a unique key derived from the provided base key, namespace, and component name.
     *
     * @param int $baseKey Base key for generating the queue identifier
     * @param int $permissions Permissions for the created queue (default: 0666)
     * @param string $namespace Namespace to prevent key collisions (default: 'sysv-ipc')
     *
     * @throws SysvIpcException If queue creation fails
     */
    public function __construct(
        private int $baseKey,
        private int $permissions = 0666,
        private string $namespace = 'sysv-ipc'
    ) {
        SysvIpcSupport::requireFunction('msg_get_queue', 'sysvmsg');

        $key = SysvIpcSupport::deriveKey($this->baseKey, $this->namespace, 'queue');
        $this->queue = msg_get_queue($key, $this->permissions, true);

        if (!$this->queue) {
            throw new SysvIpcException('Failed to create queue msg_get_queue().');
        }
    }

    /**
     * Returns the native System V message queue resource
     *
     * This method provides access to the underlying queue resource for
     * direct system calls or debugging purposes.
     *
     * @return SysvMessageQueue The native message queue resource
     */
    public function getNativeQueue(): SysvMessageQueue
    {
        return $this->queue;
    }

    /**
     * Sends a typed message to the queue with automatic envelope wrapping
     *
     * This method wraps the payload in an envelope containing metadata like ID,
     * creation time, and expiration. It's designed for structured messaging.
     *
     * @param mixed $payload The data to send (can be any serializable type)
     * @param int $type Message type for priority handling (default: 1)
     * @param int|null $ttl Time-to-live in seconds for the message (default: null)
     * @param bool $blocking If true, blocks until message is sent (default: true)
     * @return bool True if message was sent successfully, false otherwise
     */
    public function sendMessage(mixed $payload, int $type = 1, ?int $ttl = null, bool $blocking = true): bool
    {
        SysvIpcSupport::requireFunction('msg_send', 'sysvmsg');

        $envelope = [
            'id' => bin2hex(random_bytes(16)),
            'payload' => $payload,
            'created_at' => time(),
            'expires_at' => SysvIpcSupport::computeExpiresAt($ttl, 0),
        ];

        $errorCode = 0;
        return msg_send($this->queue, $type, $envelope, true, $blocking, $errorCode);
    }

    /**
     * Sends a raw message to the queue without envelope wrapping
     *
     * This method sends a message as-is with no additional metadata or serialization.
     * It's useful for simple messaging where you control the format yourself.
     *
     * @param int $type Message type for priority handling
     * @param string $message The raw message data to send
     * @param bool $blocking If true, blocks until message is sent (default: true)
     * @return bool True if message was sent successfully, false otherwise
     */
    public function sendRawMessage(int $type, string $message, bool $blocking = true): bool
    {
        SysvIpcSupport::requireFunction('msg_send', 'sysvmsg');

        $errorCode = 0;
        return msg_send($this->queue, $type, $message, false, $blocking, $errorCode);
    }

    /**
     * Receives a message from the queue with envelope unwrapping
     *
     * This method reads a message from the queue and automatically handles
     * envelope unwrapping to extract the original payload. It supports filtering
     * by desired type and handles expiration of messages.
     *
     * @param int $desiredType Message type to receive (0 = any type, default: 0)
     * @param int $flags Receive flags (default: MSG_IPC_NOWAIT)
     * @param int $maxMessageSize Maximum message size to receive (default: 8192)
     * @param bool $discardExpired If true, skips expired messages (default: true)
     * @return array|null Message data with type and payload, or null if none available
     */
    public function receiveMessage(
        int $desiredType = 0,
        int $flags = MSG_IPC_NOWAIT,
        int $maxMessageSize = 8192,
        bool $discardExpired = true
    ): ?array {
        SysvIpcSupport::requireFunction('msg_receive', 'sysvmsg');

        while (true) {
            $receivedType = 0;
            $message = null;
            $errorCode = 0;

            $ok = msg_receive(
                $this->queue,
                $desiredType,
                $receivedType,
                $maxMessageSize,
                $message,
                true,
                $flags,
                $errorCode
            );

            if (!$ok) {
                return null;
            }

            if ($discardExpired && \is_array($message) && SysvIpcSupport::isExpired($message['expires_at'] ?? null)) {
                continue;
            }

            return [
                'type' => $receivedType,
                'message' => \is_array($message) ? ($message['payload'] ?? $message) : $message,
                'raw' => $message,
            ];
        }
    }

    /**
     * Receives a raw message from the queue without envelope handling
     *
     * This method reads a message directly from the queue with no processing.
     * It's useful for when you're sending raw messages and want to avoid
     * any automatic envelope handling.
     *
     * @param int $desiredType Message type to receive (0 = any type, default: 0)
     * @param int $flags Receive flags (default: MSG_IPC_NOWAIT)
     * @param int $maxMessageSize Maximum message size to receive (default: 8192)
     * @return array|null Raw message data with type and message, or null if none available
     */
    public function receiveRawMessage(
        int $desiredType = 0,
        int $flags = MSG_IPC_NOWAIT,
        int $maxMessageSize = 8192
    ): ?array {
        SysvIpcSupport::requireFunction('msg_receive', 'sysvmsg');

        $receivedType = 0;
        $message = null;
        $errorCode = 0;

        $ok = msg_receive(
            $this->queue,
            $desiredType,
            $receivedType,
            $maxMessageSize,
            $message,
            false,
            $flags,
            $errorCode
        );

        if (!$ok) {
            return null;
        }

        return [
            'type' => $receivedType,
            'message' => $message,
        ];
    }

    /**
     * Gets statistics about the message queue
     *
     * This method returns detailed information about the current state of the queue,
     * including number of messages, size, and process information.
     *
     * @return array Statistics about the queue or empty array if failed
     */
    public function queueStats(): array
    {
        SysvIpcSupport::requireFunction('msg_stat_queue', 'sysvmsg');
        return msg_stat_queue($this->queue) ?: [];
    }

    /**
     * Attempts to purge expired messages from the queue (best-effort)
     *
     * This method tries to remove expired messages from the queue. It does this
     * by draining the queue, filtering out expired messages, and requeuing valid ones.
     * Note that this is a best-effort operation with no guarantees about atomicity.
     *
     * @param int $maxMessageSize Maximum message size to process (default: 8192)
     * @return int Number of expired messages purged
     */
    public function purgeExpiredMessages(int $maxMessageSize = 8192): int
    {
        $purged = 0;
        $buffer = [];

        while (true) {
            $receivedType = 0;
            $message = null;
            $errorCode = 0;

            $ok = @msg_receive(
                $this->queue,
                0,
                $receivedType,
                $maxMessageSize,
                $message,
                true,
                MSG_IPC_NOWAIT,
                $errorCode
            );

            if (!$ok) {
                break;
            }

            if (\is_array($message) && SysvIpcSupport::isExpired($message['expires_at'] ?? null)) {
                $purged++;
                continue;
            }

            $buffer[] = [$receivedType, $message];
        }

        foreach ($buffer as [$type, $message]) {
            $errorCode = 0;
            @msg_send($this->queue, $type, $message, true, true, $errorCode);
        }

        return $purged;
    }

    /**
     * Removes the message queue from system resources
     *
     * This method removes the message queue and frees its associated system resources.
     * It should only be called when you're certain no other processes are using it.
     *
     * @return bool True if removal was successful, false otherwise
     */
    public function remove(): bool
    {
        SysvIpcSupport::requireFunction('msg_remove_queue', 'sysvmsg');
        return msg_remove_queue($this->queue);
    }
}
