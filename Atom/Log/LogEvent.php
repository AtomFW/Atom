<?php

declare(strict_types=1);

namespace Atom\Log;

use Atom\DataBase\AutoMapped;
use Atom\DataBase\Database;
use Atom\DateTime\DateTime;
use Atom\Security\SafetyDataStructureVariable;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Audit & system event logger persisting structured events into `system_events` with deterministic cleanup.
 */
final readonly class LogEvent
{
    private const array SENSITIVE_KEYS = [
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'cookie',
    ];

    /**
     * @return array<string, string>
     */
    protected static function attributesTypes(): array
    {
        return [
            'ip' => 'binary',
            'event_time' => 'datetime',
            'metadata' => 'json',
            'event_type' => 'string',
            'user_id' => 'integer',
            'entity_id' => 'integer',
            'new_values' => 'json',
            'old_values' => 'json',
        ];
    }

    public function __construct(
        private Database $database,
        private T4LOG $log,
        private DateTime $datetime,
        protected SafetyDataStructureVariable $cache,
        private bool $account,
        private int $idUser,
        private int $connectionIpId,
        private int $serverId,
        private string $tableName = 'system_events',
    ) {}

    /**
     * @param array<string, mixed>|null $newValues
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $metadata
     */
    public function add(
        string $entityType,
        int $eventTypeId,
        int $severityId,
        int $actorTypeId,
        string $description,
        ?string $actorName = null,
        ?int $entityId = null,
        ?array $newValues = null,
        ?array $oldValues = null,
        ?array $metadata = null,
    ): int {
        $this->verifyData(
            $entityType,
            $eventTypeId,
            $severityId,
            $actorTypeId,
            $description,
            $actorName,
            $entityId,
            $newValues,
            $oldValues,
            $metadata,
        );

        try {
            $normalizeData = $this->dataStructure(
                $entityType,
                $eventTypeId,
                $severityId,
                $actorTypeId,
                $description,
                $actorName,
                $entityId,
                $newValues,
                $oldValues,
                $metadata,
            );

            $keys = \array_keys($normalizeData);
            $mapKeysToValues = \array_combine($keys, $keys);
            $attributesToBindsProperty = $this->database->attributesToBindsProperty($mapKeysToValues);

            $types = self::attributesTypes();
            foreach ($normalizeData as $key => $value) {
                if (isset($types[$key])) {
                    $normalizeData[$key] = AutoMapped::mapValueFromPHP($value, $types[$key]);
                }
            }

            $insert = $this->database
                ->insertInto($this->tableName)
                ->values($attributesToBindsProperty)
                ->setParameters($normalizeData);

            $statement = $insert->executeQuery();
            try {
                if ($statement->rowCount() === 0) {
                    throw new RuntimeException('Event saving failed');
                }
            } finally {
                $statement->free();
            }

            return (int) $this->database->lastInsertId();
        } catch (Throwable $e) {
            $this->log->error('Failed to store event.', [
                'event_type_id' => $eventTypeId,
                'severity_id' => $severityId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'actor_type_id' => $actorTypeId,
                'actor_name' => $actorName,
                'description' => $description,
                'old_values' => self::redactArray($oldValues),
                'new_values' => self::redactArray($newValues),
                'metadata' => self::redactArray($metadata),
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|null
     */
    private static function redactArray(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $clean = [];
        foreach ($payload as $key => $val) {
            $lowKey = \strtolower((string) $key);
            $isSensitive = \array_any(
                self::SENSITIVE_KEYS,
                static fn(string $token): bool => \str_contains($lowKey, $token)
            );
            $clean[$key] = $isSensitive ? '[REDACTED]' : (\is_array($val) ? self::redactArray($val) : $val);
        }

        return $clean;
    }

    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->datetime->getTimezone());
    }

    private function assertName(?string $name, string $eventType): void
    {
        if ($name !== null && \trim($name) === '') {
            throw new InvalidArgumentException("Name of event ({$eventType}) must not be empty.");
        }
    }

    protected function assertUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('User ID must be greater than zero.');
        }
    }

    private function assertId(?int $number, string $eventName): void
    {
        if ($number !== null && $number <= 0) {
            throw new InvalidArgumentException("Event ({$eventName}) ID must be greater than zero.");
        }
    }

    /**
     * @param array<string, mixed>|null $array
     */
    private function assertArray(?array $array, string $eventName): void
    {
        // Typed nullable array already guaranteed by PHP 8.6 strict_types; kept for reflection/API compatibility.
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function encodeContext(array $context): ?string
    {
        if ($context === []) {
            return null;
        }

        return \json_encode($context, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed>|null $newValues
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>
     */
    private function dataStructure(
        string $entityType,
        int $eventTypeId,
        int $severityId,
        int $actorTypeId,
        string $description,
        ?string $actorName = null,
        ?int $entityId = null,
        ?array $newValues = null,
        ?array $oldValues = null,
        ?array $metadata = null,
    ): array {
        return [
            'event_time' => $this->datetime->now()->toSQL(),
            'event_type_id' => $eventTypeId,
            'severity_id' => $severityId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor_type_id' => $actorTypeId,
            'actor_id' => $this->account ? 1 : $this->idUser,
            'actor_name' => $actorName,
            'ip_address_id' => $this->connectionIpId,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'server_id' => $this->serverId,
            'is_add_from_server' => $this->account ? 1 : 0,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param array<string, mixed>|null $newValues
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $metadata
     */
    private function verifyData(
        string $entityType,
        int $eventTypeId,
        int $severityId,
        int $actorTypeId,
        string $description,
        ?string $actorName = null,
        ?int $entityId = null,
        ?array $newValues = null,
        ?array $oldValues = null,
        ?array $metadata = null,
    ): void {
        $this->assertName($entityType, 'entityType');
        $this->assertId($eventTypeId, 'eventTypeId');
        $this->assertId($severityId, 'severityId');
        $this->assertId($actorTypeId, 'actorTypeId');
        $this->assertName($description, 'description');
        $this->assertName($actorName, 'actorName');
        $this->assertId($entityId, 'entityId');
        $this->assertArray($newValues, 'newValues');
        $this->assertArray($oldValues, 'oldValues');
        $this->assertArray($metadata, 'metadata');
    }
}
