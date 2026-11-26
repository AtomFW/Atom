<?php

declare(strict_types=1);

namespace Atom\Component\Task\Driver;

use InvalidArgumentException;
use PDO;

/**
 * PDO-backed queue message driver with strict table identifier validation
 * and deterministic cursor cleanup.
 */
final readonly class DoctrineDriver implements MessageDriverInterface
{
    private string $quotedTable;

    public function __construct(
        private PDO $pdo,
        string $table = 'queue_messages',
    ) {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid queue table identifier: {$table}");
        }
        $this->quotedTable = '`' . $table . '`';
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function listKeys(): iterable
    {
        $stmt = $this->pdo->query("SELECT id FROM {$this->quotedTable} ORDER BY created_at ASC");
        if ($stmt === false) {
            return [];
        }

        try {
            /** @var list<string> $keys */
            $keys = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            return $keys;
        } finally {
            $stmt->closeCursor();
        }
    }

    #[\Override]
    public function getMessage(string $key): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->quotedTable} WHERE id = :id LIMIT 1");
        try {
            $stmt->execute(['id' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!\is_array($row)) {
                return null;
            }

            $decodedBody = \is_string($row['body'] ?? null) ? \json_decode($row['body'], true) : null;
            $decodedMeta = \is_string($row['meta'] ?? null) && $row['meta'] !== ''
                ? \json_decode($row['meta'], true)
                : [];

            return [
                'id' => (string) $row['id'],
                'class' => (string) $row['class'],
                'body' => $decodedBody,
                'attempts' => (int) $row['attempts'],
                'available_at' => (int) $row['available_at'],
                'created_at' => (int) $row['created_at'],
                'meta' => \is_array($decodedMeta) ? $decodedMeta : [],
            ];
        } finally {
            $stmt->closeCursor();
        }
    }

    #[\Override]
    public function saveMessage(array $envelope, string $position = 'append'): string
    {
        $id = !empty($envelope['id']) ? (string) $envelope['id'] : \bin2hex(\random_bytes(8));
        $nowMs = (int) (\microtime(true) * 1000);

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->quotedTable} (id, class, body, attempts, available_at, created_at, meta)
             VALUES (:id, :class, :body, :attempts, :available_at, :created_at, :meta)
             ON DUPLICATE KEY UPDATE body = VALUES(body), attempts = VALUES(attempts), available_at = VALUES(available_at)",
        );

        try {
            $stmt->execute([
                'id' => $id,
                'class' => (string) ($envelope['class'] ?? ''),
                'body' => \json_encode($envelope['body'] ?? [], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'attempts' => (int) ($envelope['attempts'] ?? 0),
                'available_at' => (int) ($envelope['available_at'] ?? $nowMs),
                'created_at' => (int) ($envelope['created_at'] ?? $nowMs),
                'meta' => isset($envelope['meta'])
                    ? \json_encode($envelope['meta'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)
                    : null,
            ]);
        } finally {
            $stmt->closeCursor();
        }

        return $id;
    }

    #[\Override]
    public function remove(string $key): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->quotedTable} WHERE id = :id");
        try {
            return $stmt->execute(['id' => $key]);
        } finally {
            $stmt->closeCursor();
        }
    }

    #[\Override]
    public function isOnline(): bool
    {
        try {
            $stmt = $this->pdo->query('SELECT 1');
            if ($stmt !== false) {
                $stmt->closeCursor();
            }
            return $stmt !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
