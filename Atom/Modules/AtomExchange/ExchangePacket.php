<?php

declare(strict_types=1);

namespace Atom\Modules\AtomExchange;

/**
 * Immutable DTO representing an authenticated inter-Atom data exchange message.
 */
final readonly class ExchangePacket
{
    public function __construct(
        public string $packetId,
        public string $senderNodeId,
        public string $recipientNodeId,
        public string $channel,
        public mixed $payload,
        public int $timestampNs,
        public string $keyId = 'atmx-k1',
        public bool $encrypted = true,
        public bool $compressed = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'packetId' => $this->packetId,
            'senderNodeId' => $this->senderNodeId,
            'recipientNodeId' => $this->recipientNodeId,
            'channel' => $this->channel,
            'payload' => $this->payload,
            'timestampNs' => $this->timestampNs,
            'keyId' => $this->keyId,
            'encrypted' => $this->encrypted,
            'compressed' => $this->compressed,
        ];
    }
}
