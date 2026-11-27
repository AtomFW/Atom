<?php

declare(strict_types=1);

namespace Tests\Atom\Modules;

use Atom\Cache\InMemoryRedisAdapter;
use Atom\Modules\AtomExchange\AtomExchange;
use Atom\Modules\AtomExchange\ExchangeCipher;
use Atom\Modules\AtomExchange\ExchangePacket;
use Atom\Modules\ModuleMigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Time\Duration;

final class AtomExchangeTest extends TestCase
{
    public function testAeadEncryptionCompressionTamperAndReplayProtection(): void
    {
        $alicePair = ExchangeCipher::generateKeyPair();
        $bobPair = ExchangeCipher::generateKeyPair();

        $aliceSecret = ExchangeCipher::derivePeerSecret($alicePair['secretKey'], $bobPair['publicKey']);
        $bobSecret = ExchangeCipher::derivePeerSecret($bobPair['secretKey'], $alicePair['publicKey']);
        self::assertSame($aliceSecret, $bobSecret);

        $senderCipher = new ExchangeCipher($aliceSecret, 'k-prod-1', compressEnabled: true, compressThresholdBytes: 256);
        $receiverCipher = new ExchangeCipher($bobSecret, 'k-prod-1', compressEnabled: true, compressThresholdBytes: 256);

        $largeData = [
            'event' => 'order.synced',
            'items' => \array_fill(0, 60, ['sku' => 'ATOM-PRO-86', 'qty' => 4]),
        ];

        $frame = $senderCipher->seal('node-a', 'node-b', 'orders.sync', $largeData);
        $opened = $receiverCipher->open($frame);

        self::assertTrue($opened->encrypted);
        self::assertTrue($opened->compressed);
        self::assertSame('node-a', $opened->senderNodeId);
        self::assertSame('node-b', $opened->recipientNodeId);
        self::assertSame('orders.sync', $opened->channel);
        self::assertSame($largeData, $opened->payload);

        // Replay of the exact same frame must be rejected
        try {
            $receiverCipher->open($frame);
            self::fail('Expected duplicate replay frame to be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('replay', $e->getMessage());
        }

        // Tampered frame must fail AEAD authentication
        $freshFrame = $senderCipher->seal('node-a', 'node-b', 'orders.sync', ['amount' => 100]);
        $tampered = \substr($freshFrame, 0, -4) . 'AAAA';
        $this->expectException(RuntimeException::class);
        $receiverCipher->open($tampered);
    }

    public function testRedisAndNonBlockingIoPollMultiplexedExchange(): void
    {
        $redis = new InMemoryRedisAdapter(true);
        $secret = 'super-fast-shared-exchange-secret-2026';

        $nodeA = new AtomExchange([
            'nodeId' => 'atom-alpha',
            'encryption' => ['on' => true, 'sharedSecret' => $secret, 'keyId' => 'x1'],
        ], $redis);

        $nodeB = new AtomExchange([
            'nodeId' => 'atom-beta',
            'encryption' => ['on' => true, 'sharedSecret' => $secret, 'keyId' => 'x1'],
        ], $redis);

        $nodeA->sendViaRedis('atom-beta', 'cache.invalidate', ['tags' => ['users', 'products']]);
        $received = $nodeB->receiveFromRedis();

        self::assertCount(1, $received);
        self::assertSame('atom-alpha', $received[0]->senderNodeId);
        self::assertSame(['tags' => ['users', 'products']], $received[0]->payload);

        // Test PHP 8.6 \Io\Poll\Context non-blocking stream pair multiplexing
        $domain = \DIRECTORY_SEPARATOR === '\\' ? \STREAM_PF_INET : \STREAM_PF_UNIX;
        $pair = \stream_socket_pair($domain, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        [$clientStream, $serverStream] = $pair;
        try {
            $nodeB->onChannel('ping.fast', static fn(ExchangePacket $pkt): array => [
                'pongFrom' => 'atom-beta',
                'echo' => $pkt->payload,
            ]);

            // Pre-stage a request->reply simulation by writing server reply once client frame arrives,
            // or pre-writing an encrypted reply frame onto serverStream so Io\Poll\Context writes & reads non-blockingly!
            $prebuiltReply = $nodeB->sealFrame('atom-alpha', 'ping.fast:reply', ['pongFrom' => 'atom-beta', 'ok' => true]) . "\n";
            \fwrite($serverStream, $prebuiltReply);

            $replies = $nodeA->exchangeOverStreams(
                ['atom-beta' => $clientStream],
                'ping.fast',
                ['seq' => 1],
                Duration::fromMilliseconds(500),
            );

            self::assertArrayHasKey('atom-beta', $replies);
            self::assertSame(['pongFrom' => 'atom-beta', 'ok' => true], $replies['atom-beta']->payload);
        } finally {
            \fclose($clientStream);
            \fclose($serverStream);
        }
    }

    public function testAtomExchangeSqlMigrationsUpAndDown(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $runner = new ModuleMigrationRunner($pdo);

        $applied = $runner->migrateUp('AtomExchange');
        self::assertSame(['AtomExchange:ae0001_create_atom_exchange_peers'], $applied);

        $rolledBack = $runner->migrateDown('AtomExchange', 1);
        self::assertSame(['AtomExchange:ae0001_create_atom_exchange_peers'], $rolledBack);
    }
}
