<?php

declare(strict_types=1);

namespace Atom\Log;

use Atom\Account\Account;
use Atom\Component\Cache\CacheWrapper;
use Atom\DataBase\AutoMapped;
use Atom\DataBase\Database;
use Atom\DateTime\DateTime;
use Atom\Security\SafetyDataStructureVariable;

final class LogEvent
{
    /**
     * Returns an array of column names mapped to their respective
     * @return array
     */
    protected static function attributesTypes(): array
    {
        return [
            'ip' => "binary",
            'event_time' => "datetime",
            'metadata' => "json",
            'event_type' => "string",
            'user_id' => "integer" ,
            'entity_id' => "integer" ,
            'new_values' => "json",
            'old_values' => "json",
        ];
    }

    public function __construct(
        private readonly Database $database,
        private readonly T4LOG $log,
        private readonly DateTime $datetime,
        private readonly SafetyDataStructureVariable $cache,
        private readonly bool $account,
        private readonly int $idUser,
        private readonly int $connectionIpId,
        private readonly int $serverId,
        private readonly string $tableName = 'system_events',
    ) {
    }

    /**
     * Adds an event to the database.
     *
     * @param array<string, mixed> $context Optional additional event data.
     *
     * @return int The inserted row ID.
     */
    public function add(
        string $entityType, // server, post, user, file, ban, comment, etc.
        int $eventTypeId,
        int $severityId,
        int $actorTypeId,
        string $description,
        ?string $actorName = null, // actor_name
        ?int $entityId = null, // entity_id
        ?array $newValues = null, // json
        ?array $oldValues = null, // json
        ?array $metadata = null, // json
    ): int {
        $this->verifyData($entityType, $eventTypeId, $severityId, $actorTypeId, $description, $actorName, $entityId, $newValues, $oldValues, $metadata);

        try {
            $normalizeData = self::dataStructure($entityType, $eventTypeId, $severityId, $actorTypeId, $description, $actorName, $entityId, $newValues, $oldValues, $metadata);

            $mapKeysToValues = [];
            foreach ($normalizeData as $key => $value) {
                $mapKeysToValues[$key] = $key;
            }

            $attributesToBindsProperty = $this->database->attributesToBindsProperty($mapKeysToValues);

            foreach ($normalizeData as $key => $value) {
                if (!isset(self::attributesTypes()[$key])) {
                    continue;
                }

                $normalizeData[$key] = AutoMapped::mapValueFromPHP($value, self::attributesTypes()[$key]);
            }

            $insert = $this->database->insertInto($this->tableName)->values($attributesToBindsProperty)->setParameters($normalizeData);

            $statement = $insert->executeQuery();

            if ($statement->rowCount() === 0) {
                throw new \Exception("Connection saving failed");
            }

            $statement->free();

            return (int)$this->database->lastInsertId();
        } catch (\Throwable $e) {
            $this->log->error('Failed to store event.', [
                'event_type_id' => $eventTypeId,
                'severity_id' => $severityId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'actor_type_id' => $actorTypeId,
                'actor_name' => $actorName,
                'description' => $description,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'metadata' => $metadata,
                'exception' => $e
            ]);

            throw $e;
        }
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->datetime->getTimezone());
    }

    private function assertName(?string $name, string $eventType): void
    {
        if (($name !== null) && (trim($name) === '')) {
            throw new \InvalidArgumentException("Name of event ({$eventType}) must not be empty.");
        }
    }

    private function assertUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('User ID must be greater than zero.');
        }
    }

    private function assertId(?int $number, string $eventName): void
    {
        if (($number !== null) && (empty($number) || $number <= 0)) {
            throw new \InvalidArgumentException("Event ({$eventName}) ID must be greater than zero.");
        };
    }

    private function assertArray(?array $array, string $eventName): void
    {
        if (($array !== null) && !is_array($array)) {
            throw new \InvalidArgumentException("Event ({$eventName}) must be an array.");
        }
    }


    /**
     * @param array<string, mixed> $context
     */
    private function encodeContext(array $context): ?string
    {
        if ($context === []) {
            return null;
        }

        return json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

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
        ?array $metadata = null
    ) {
        $datetime = $this->datetime->now()->toSQL();
        $actorID = $this->account ? 1 : $this->idUser;
        $isAddFromServer = $this->account ? 1 : 0;

        return [
            'event_time' => $datetime,
            'event_type_id' => $eventTypeId,
            'severity_id' => $severityId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor_type_id' => $actorTypeId,
            'actor_id' => $actorID,
            'actor_name' => $actorName,
            'ip_address_id' => $this->connectionIpId,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'server_id' => $this->serverId,
            'is_add_from_server' => $isAddFromServer,
            'metadata' => $metadata,
        ];
    }

    private function verifyData (
        string $entityType,
        int $eventTypeId,
        int $severityId,
        int $actorTypeId,
        string $description,
        ?string $actorName = null,
        ?int $entityId = null, 
        ?array $newValues = null,
        ?array $oldValues = null,
        ?array $metadata = null
    ): void {
        $this->assertName($entityType, "entityType");
        $this->assertId($eventTypeId, "eventTypeId");
        $this->assertId($severityId, "severityId");
        $this->assertId($actorTypeId, "actorTypeId");
        $this->assertName($description, "description");
        $this->assertName($actorName, "actorName");
        $this->assertId($entityId, "entityId");
        $this->assertArray($newValues, "newValues");
        $this->assertArray($oldValues, "oldValues");
        $this->assertArray($metadata, "metadata");
    }
}
