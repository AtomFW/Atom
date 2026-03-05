<?php
declare(strict_types=1);

namespace Atom\Component\Task\Driver;

use PDO;

/**
 * Minimal PDO-based driver storing messages in a single table.
 *
 * Table schema suggestion:
 *
 * CREATE TABLE queue_messages (
 *   id VARCHAR(64) PRIMARY KEY,
 *   class VARCHAR(255) NOT NULL,
 *   body LONGTEXT NOT NULL,
 *   attempts INT NOT NULL DEFAULT 0,
 *   available_at BIGINT NOT NULL,
 *   created_at BIGINT NOT NULL,
 *   meta JSON NULL
 * );
 *
 * Index on available_at for faster polling.
 */
final class DoctrineDriver implements MessageDriverInterface
{
    private PDO $pdo;
    private string $table;

    public function __construct(PDO $pdo, string $table = 'queue_messages')
    {
        $this->pdo = $pdo;
        $this->table = $table;
    }

    public function listKeys(): iterable
    {
        $stmt = $this->pdo->query("SELECT id FROM {$this->table} ORDER BY created_at ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    }

    public function getMessage(string $key): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id");
        $stmt->execute(['id' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'id' => $row['id'],
            'class' => $row['class'],
            'body' => json_decode($row['body'], true),
            'attempts' => (int)$row['attempts'],
            'available_at' => (int)$row['available_at'],
            'created_at' => (int)$row['created_at'],
            'meta' => $row['meta'] ? json_decode($row['meta'], true) : [],
        ];
    }

    public function saveMessage(array $envelope, string $position = 'append'): string
    {
        $id = $envelope['id'] ?? bin2hex(random_bytes(8));
        $envelope['id'] = $id;
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (id, class, body, attempts, available_at, created_at, meta)
             VALUES (:id, :class, :body, :attempts, :available_at, :created_at, :meta)
             ON DUPLICATE KEY UPDATE body = VALUES(body), attempts = VALUES(attempts), available_at = VALUES(available_at)"
        );
        $stmt->execute([
            'id' => $id,
            'class' => $envelope['class'],
            'body' => json_encode($envelope['body']),
            'attempts' => $envelope['attempts'] ?? 0,
            'available_at' => $envelope['available_at'] ?? (int) (microtime(true) * 1000),
            'created_at' => $envelope['created_at'] ?? (int) (microtime(true) * 1000),
            'meta' => isset($envelope['meta']) ? json_encode($envelope['meta']) : null,
        ]);
        return $id;
    }

    public function remove(string $key): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = :id");
        return $stmt->execute(['id' => $key]);
    }

    public function isOnline(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}