<?php

declare(strict_types=1);

namespace Atom\Cache;

use SysvMessageQueue;
use Time\Duration;

/**
 * System V IPC message queue manager without @ error suppression.
 */
final readonly class SysvQueueManager
{
    private SysvMessageQueue $queue;

    public function __construct(
        private int $baseKey,
        private int $permissions = 0666,
        private string $namespace = 'sysv-ipc',
    ) {
        SysvIpcSupport::requireFunction('msg_get_queue', 'sysvmsg');

        $key = SysvIpcSupport::deriveKey($this->baseKey, $this->namespace, 'queue');
        $queue = \msg_get_queue($key, $this->permissions);

        if ($queue === false) {
            throw new SysvIpcException('Failed to create queue msg_get_queue().');
        }

        $this->queue = $queue;
    }

    public function getNativeQueue(): SysvMessageQueue
    {
        return $this->queue;
    }

    public function sendMessage(
        mixed $payload,
        int $type = 1,
        int|Duration|null $ttl = null,
        bool $blocking = true,
    ): bool {
        SysvIpcSupport::requireFunction('msg_send', 'sysvmsg');

        $envelope = [
            'id' => \bin2hex(\random_bytes(16)),
            'payload' => $payload,
            'created_at' => \time(),
            'expires_at' => SysvIpcSupport::computeExpiresAt($ttl, 0),
        ];

        $errorCode = 0;
        return \msg_send($this->queue, $type, \serialize($envelope), false, $blocking, $errorCode);
    }

    public function sendRawMessage(int $type, string $message, bool $blocking = true): bool
    {
        SysvIpcSupport::requireFunction('msg_send', 'sysvmsg');

        $errorCode = 0;
        return \msg_send($this->queue, $type, $message, false, $blocking, $errorCode);
    }

    /**
     * @return array{type: int, message: mixed, raw: mixed}|null
     */
    public function receiveMessage(
        int $desiredType = 0,
        int $flags = 0,
        int $maxMessageSize = 8192,
        bool $discardExpired = true,
    ): ?array {
        SysvIpcSupport::requireFunction('msg_receive', 'sysvmsg');
        $effectiveFlags = $flags === 0 && \defined('MSG_IPC_NOWAIT') ? \MSG_IPC_NOWAIT : $flags;

        while (true) {
            $receivedType = 0;
            $message = null;
            $errorCode = 0;

            $ok = (bool) SysvIpcSupport::callQuietly(
                function () use ($desiredType, &$receivedType, $maxMessageSize, &$message, $effectiveFlags, &$errorCode): bool {
                    return \msg_receive(
                        $this->queue,
                        $desiredType,
                        $receivedType,
                        $maxMessageSize,
                        $message,
                        true,
                        $effectiveFlags,
                        $errorCode,
                    );
                },
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
     * @return array{type: int, message: mixed}|null
     */
    public function receiveRawMessage(
        int $desiredType = 0,
        int $flags = 0,
        int $maxMessageSize = 8192,
    ): ?array {
        SysvIpcSupport::requireFunction('msg_receive', 'sysvmsg');
        $effectiveFlags = $flags === 0 && \defined('MSG_IPC_NOWAIT') ? \MSG_IPC_NOWAIT : $flags;

        $receivedType = 0;
        $message = null;
        $errorCode = 0;

        $ok = (bool) SysvIpcSupport::callQuietly(
            function () use ($desiredType, &$receivedType, $maxMessageSize, &$message, $effectiveFlags, &$errorCode): bool {
                return \msg_receive(
                    $this->queue,
                    $desiredType,
                    $receivedType,
                    $maxMessageSize,
                    $message,
                    false,
                    $effectiveFlags,
                    $errorCode,
                );
            },
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
     * @return array<string, mixed>
     */
    public function queueStats(): array
    {
        SysvIpcSupport::requireFunction('msg_stat_queue', 'sysvmsg');
        $stats = \msg_stat_queue($this->queue);
        return \is_array($stats) ? $stats : [];
    }

    public function purgeExpiredMessages(int $maxMessageSize = 8192): int
    {
        SysvIpcSupport::requireFunction('msg_receive', 'sysvmsg');
        SysvIpcSupport::requireFunction('msg_send', 'sysvmsg');

        $nowaitFlag = \defined('MSG_IPC_NOWAIT') ? \MSG_IPC_NOWAIT : 1;
        $purged = 0;
        $buffer = [];

        while (true) {
            $receivedType = 0;
            $message = null;
            $errorCode = 0;

            $ok = (bool) SysvIpcSupport::callQuietly(
                function () use (&$receivedType, $maxMessageSize, &$message, $nowaitFlag, &$errorCode): bool {
                    return \msg_receive(
                        $this->queue,
                        0,
                        $receivedType,
                        $maxMessageSize,
                        $message,
                        true,
                        $nowaitFlag,
                        $errorCode,
                    );
                },
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
            SysvIpcSupport::callQuietly(function () use ($type, $message): bool {
                $errorCode = 0;
                return \msg_send($this->queue, $type, $message, true, true, $errorCode);
            });
        }

        return $purged;
    }

    public function remove(): bool
    {
        SysvIpcSupport::requireFunction('msg_remove_queue', 'sysvmsg');
        return \msg_remove_queue($this->queue);
    }
}
