<?php

declare(strict_types=1);

namespace Atom\Modules\AtomExchange;

use Atom\Atom;
use Atom\Cache\Contract\RedisAdapterInterface;
use Atom\Cache\InMemoryRedisAdapter;
use Atom\Cache\RespRedisAdapter;
use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;
use Atom\Modules\Contract\AtomModuleInterface;
use Atom\Terminal\Terminal;
use Io\Poll\Context as PollContext;
use Io\Poll\Event as PollEvent;
use Override;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;
use SensitiveParameter;
use StreamPollHandle;
use Throwable;
use Time\Duration;

/**
 * Official `AtomExchange` module (`Atom\Modules\AtomExchange`) enabling ultra-fast,
 * AEAD-encrypted data exchange between Atom servers over Redis, multiplexed non-blocking
 * PHP 8.6 `\Io\Poll\Context` streams, or stateless HTTP endpoints.
 */
final class AtomExchange implements AtomModuleInterface
{
    public const string VERSION = '1.0.0';

    /** @var array<string, mixed> */
    private array $options;

    private string $localNodeId;
    private ExchangeCipher $cipher;
    private ?RedisAdapterInterface $redisAdapter = null;

    /**
     * Bounded channel handler registry (`channel => callable(ExchangePacket): mixed`).
     *
     * @var array<string, callable(ExchangePacket): mixed>
     */
    private array $channelHandlers = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        #[SensitiveParameter]
        array $options = [],
        ?RedisAdapterInterface $redisAdapter = null,
    ) {
        $this->options = $options;
        $this->localNodeId = (string) ($options['nodeId'] ?? 'atom-node-local');

        $encCfg = \is_array($options['encryption'] ?? null) ? $options['encryption'] : [];
        $frameCfg = \is_array($options['framing'] ?? null) ? $options['framing'] : [];

        $secret = (string) ($encCfg['sharedSecret'] ?? '');
        if ($secret === '') {
            $secret = \bin2hex((new Randomizer(new Secure()))->getBytes(32));
        }

        $this->cipher = new ExchangeCipher(
            sharedSecret: $secret,
            keyId: (string) ($encCfg['keyId'] ?? 'atmx-k1'),
            encryptEnabled: (bool) ($encCfg['on'] ?? true),
            compressEnabled: (bool) ($frameCfg['compress'] ?? true),
            compressThresholdBytes: (int) ($frameCfg['compressThresholdBytes'] ?? 1024),
            maxFrameBytes: (int) ($frameCfg['maxFrameBytes'] ?? 4_194_304),
            replayWindow: Duration::fromSeconds(\clamp((int) ($encCfg['replayWindowSeconds'] ?? 30), 1, 3600)),
            maxReplayNonces: (int) ($encCfg['maxReplayNonces'] ?? 4096),
        );

        $this->redisAdapter = $redisAdapter;
    }

    #[Override]
    public function getName(): string
    {
        return 'AtomExchange';
    }

    #[Override]
    public function getVersion(): string
    {
        return self::VERSION;
    }

    #[Override]
    public function dependencies(): array
    {
        return [];
    }

    public function cipher(): ExchangeCipher
    {
        return $this->cipher;
    }

    public function getLocalNodeId(): string
    {
        return $this->localNodeId;
    }

    public function withSharedSecret(#[SensitiveParameter] string $secret, string $keyId = 'atmx-k1'): self
    {
        $nextOpts = $this->options;
        $nextOpts['encryption'] = [
            ...(\is_array($this->options['encryption'] ?? null) ? $this->options['encryption'] : []),
            'on' => true,
            'sharedSecret' => $secret,
            'keyId' => $keyId,
        ];

        $clone = new self($nextOpts, $this->redisAdapter);
        $clone->channelHandlers = $this->channelHandlers;
        return $clone;
    }

    public function setRedisAdapter(?RedisAdapterInterface $adapter): void
    {
        $this->redisAdapter = $adapter;
    }

    #[Override]
    public function boot(Atom $app): void
    {
        $cfg = $app->config->get('exchange');
        if (!\is_array($cfg) || empty($cfg['on'])) {
            return;
        }

        $enc = \is_array($cfg['encryption'] ?? null) ? $cfg['encryption'] : [];
        $configuredSecret = (string) ($enc['sharedSecret'] ?? '');
        if ($configuredSecret !== '') {
            $frameCfg = \is_array($cfg['framing'] ?? null) ? $cfg['framing'] : [];
            $this->cipher = new ExchangeCipher(
                sharedSecret: $configuredSecret,
                keyId: (string) ($enc['keyId'] ?? 'atmx-k1'),
                encryptEnabled: (bool) ($enc['on'] ?? true),
                compressEnabled: (bool) ($frameCfg['compress'] ?? true),
                compressThresholdBytes: (int) ($frameCfg['compressThresholdBytes'] ?? 1024),
                maxFrameBytes: (int) ($frameCfg['maxFrameBytes'] ?? 4_194_304),
                replayWindow: Duration::fromSeconds(\clamp((int) ($enc['replayWindowSeconds'] ?? 30), 1, 3600)),
                maxReplayNonces: (int) ($enc['maxReplayNonces'] ?? 4096),
            );
        }

        $driverBlock = \is_array($cfg['driver'] ?? null) ? $cfg['driver'] : [];
        $driverName = (string) ($driverBlock['driver'] ?? '');
        if ($this->redisAdapter === null && $driverName === 'redis') {
            $this->redisAdapter = new RespRedisAdapter($driverBlock);
        } elseif ($this->redisAdapter === null && $driverName === 'memory') {
            $this->redisAdapter = new InMemoryRedisAdapter(true);
        }

        $endpointCfg = \is_array($cfg['endpoint'] ?? null) ? $cfg['endpoint'] : [];
        if (!empty($endpointCfg['on'])) {
            $path = (string) ($endpointCfg['path'] ?? '/_atom/exchange');
            $app->router->post($path, function (Request $req, Response $res): string {
                $res->setHeader('Content-Type', 'application/octet-stream');
                try {
                    $replyFrame = $this->handleWireRequest($req->getRawBody());
                    return $replyFrame;
                } catch (Throwable $e) {
                    $res->statusCode(400);
                    return $e->getMessage();
                }
            });
        }
    }

    /**
     * Registers a stateless handler for an incoming encrypted exchange channel.
     *
     * @param callable(ExchangePacket): mixed $handler
     */
    public function onChannel(string $channel, callable $handler): self
    {
        if (\count($this->channelHandlers) >= 256 && !isset($this->channelHandlers[$channel])) {
            $oldest = \array_key_first($this->channelHandlers);
            unset($this->channelHandlers[$oldest]);
        }

        $this->channelHandlers[$channel] = $handler;
        return $this;
    }

    /**
     * Seals a payload for `$recipientNodeId` into an encrypted wire frame.
     */
    public function sealFrame(
        string $recipientNodeId,
        string $channel,
        mixed $payload,
        bool $binaryOutput = false,
    ): string {
        return $this->cipher->seal(
            senderNodeId: $this->localNodeId,
            recipientNodeId: $recipientNodeId,
            channel: $channel,
            payload: $payload,
            binaryOutput: $binaryOutput,
        );
    }

    /**
     * Opens and verifies an encrypted wire frame into an `ExchangePacket`.
     */
    public function openFrame(string $wireFrame, bool $isBinaryInput = false): ExchangePacket
    {
        return $this->cipher->open($wireFrame, $isBinaryInput);
    }

    /**
     * Processes an incoming encrypted wire frame, invokes the registered channel callback,
     * and returns an encrypted reply wire frame back to the sender.
     */
    public function handleWireRequest(string $wireFrame): string
    {
        $packet = $this->openFrame(\trim($wireFrame), false);
        $handler = $this->channelHandlers[$packet->channel] ?? null;

        $responsePayload = $handler !== null
            ? $handler($packet)
            : ['ok' => true, 'ack' => $packet->packetId];

        return $this->cipher->seal(
            senderNodeId: $this->localNodeId,
            recipientNodeId: $packet->senderNodeId,
            channel: $packet->channel . ':reply',
            payload: $responsePayload,
            binaryOutput: false,
        );
    }

    /**
     * Pushes an encrypted packet to `$recipientNodeId`'s ultra-fast Redis inbox & pub/sub channel.
     */
    public function sendViaRedis(
        string $recipientNodeId,
        string $channel,
        mixed $payload,
        int $maxInboxSize = 2048,
    ): string {
        if ($this->redisAdapter === null || !$this->redisAdapter->isAvailable()) {
            throw new RuntimeException('Redis adapter is not available for AtomExchange.');
        }

        $frame = $this->sealFrame($recipientNodeId, $channel, $payload, false);
        $inboxKey = 'inbox:' . $recipientNodeId;
        $this->redisAdapter->rPush($inboxKey, $frame, \clamp($maxInboxSize, 16, 100_000));
        $this->redisAdapter->publish('pub:' . $recipientNodeId . ':' . $channel, $frame);

        return $frame;
    }

    /**
     * Drains up to `$limit` encrypted packets from this node's Redis inbox and decrypts them.
     *
     * @return list<ExchangePacket>
     */
    public function receiveFromRedis(int $limit = 32, ?string $nodeIdOverride = null): array
    {
        if ($this->redisAdapter === null || !$this->redisAdapter->isAvailable()) {
            return [];
        }

        $targetNode = $nodeIdOverride ?? $this->localNodeId;
        $inboxKey = 'inbox:' . $targetNode;
        $max = \clamp($limit, 1, 1024);
        $packets = [];

        for ($i = 0; $i < $max; $i++) {
            $rawFrame = $this->redisAdapter->lPop($inboxKey);
            if ($rawFrame === null || $rawFrame === '') {
                break;
            }
            $packets[] = $this->openFrame($rawFrame, false);
        }

        return $packets;
    }

    /**
     * Multiplexes encrypted frames in parallel across multiple non-blocking stream/socket peers
     * using PHP 8.6 native `\Io\Poll\Context` (zero `stream_select()` overhead).
     *
     * @param array<string, resource> $peerStreams Map of `recipientNodeId => connected stream`
     * @return array<string, ExchangePacket> Decrypted replies indexed by `recipientNodeId`
     */
    public function exchangeOverStreams(
        array $peerStreams,
        string $channel,
        mixed $payload,
        ?Duration $timeout = null,
    ): array {
        if ($peerStreams === []) {
            return [];
        }

        $waitTimeout = $timeout ?? Duration::fromMilliseconds(1000);
        $poll = new PollContext();

        /** @var array<string, array{stream: resource, watcher: \Io\Poll\Watcher, writeBuf: string, readBuf: string, done: bool}> $states */
        $states = [];
        $replies = [];

        try {
            foreach ($peerStreams as $peerId => $stream) {
                if (!\is_resource($stream)) {
                    continue;
                }
                \stream_set_blocking($stream, false);
                $frame = $this->sealFrame($peerId, $channel, $payload, false) . "\n";
                $watcher = $poll->add(new StreamPollHandle($stream), [PollEvent::Write], $peerId);
                $states[$peerId] = [
                    'stream' => $stream,
                    'watcher' => $watcher,
                    'writeBuf' => $frame,
                    'readBuf' => '',
                    'done' => false,
                ];
            }

            $pending = \count($states);
            $maxLoops = 256;
            $loops = 0;

            while ($pending > 0 && $loops < $maxLoops) {
                $loops++;
                $triggered = $poll->wait($waitTimeout);
                if ($triggered === []) {
                    break;
                }

                foreach ($triggered as $watcher) {
                    $peerId = (string) $watcher->getData();
                    if (!isset($states[$peerId]) || $states[$peerId]['done']) {
                        continue;
                    }

                    $stream = $states[$peerId]['stream'];

                    if ($watcher->hasTriggered(PollEvent::Error)) {
                        $states[$peerId]['done'] = true;
                        $watcher->remove();
                        $pending--;
                        continue;
                    }

                    if ($states[$peerId]['writeBuf'] !== '' && $watcher->hasTriggered(PollEvent::Write)) {
                        $written = \fwrite($stream, $states[$peerId]['writeBuf']);
                        if ($written !== false && $written > 0) {
                            $states[$peerId]['writeBuf'] = \substr($states[$peerId]['writeBuf'], $written);
                        }
                        if ($states[$peerId]['writeBuf'] === '') {
                            $watcher->modifyEvents([PollEvent::Read]);
                        }
                    } elseif ($watcher->hasTriggered(PollEvent::Read) || $watcher->hasTriggered(PollEvent::HangUp)) {
                        $chunk = \fread($stream, 65536);
                        if (\is_string($chunk) && $chunk !== '') {
                            $states[$peerId]['readBuf'] .= $chunk;
                        }

                        if (\str_contains($states[$peerId]['readBuf'], "\n") || \feof($stream)) {
                            $line = \strtok($states[$peerId]['readBuf'], "\r\n");
                            if (\is_string($line)) {
                                $replies[$peerId] = $this->openFrame($line, false);
                            }
                            $states[$peerId]['done'] = true;
                            $watcher->remove();
                            $pending--;
                        }
                    }
                }
            }
        } finally {
            foreach ($states as $st) {
                if ($st['watcher']->isActive()) {
                    $st['watcher']->remove();
                }
            }
        }

        return $replies;
    }

    #[Override]
    public function registerCommands(Terminal $terminal): Terminal
    {
        return $terminal
            ->withCommand(
                'exchange:status',
                function (array $args, Terminal $term): int {
                    $redisState = $this->redisAdapter !== null && $this->redisAdapter->isAvailable()
                        ? 'Online (' . $this->redisAdapter->getDriverName() . ')'
                        : 'Standby / Direct Mode';

                    $term->table(
                        ['Property', 'Value'],
                        [
                            ['Module', $this->getName() . ' v' . $this->getVersion()],
                            ['Local Node ID', $this->localNodeId],
                            ['AEAD Cipher', \function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') ? 'XChaCha20-Poly1305 IETF (libsodium)' : 'AES-256-GCM (OpenSSL)'],
                            ['Redis Transport', $redisState],
                            ['SQL Migrations', 'ae0001 (Atom/Modules/AtomExchange/migration)'],
                        ],
                    );
                    return 0;
                },
                'Display AtomExchange cryptographic cipher and transport status',
            )
            ->withCommand(
                'exchange:keygen',
                static function (array $args, Terminal $term): int {
                    $pair = ExchangeCipher::generateKeyPair();
                    $term->info('Generated X25519 peer keypair:');
                    $term->writeln('  PUBLIC_KEY=' . $pair['publicKey']);
                    $term->writeln('  SECRET_KEY=' . $pair['secretKey']);
                    return 0;
                },
                'Generate a new X25519 keypair for zero-trust Atom peer exchange',
            );
    }
}
