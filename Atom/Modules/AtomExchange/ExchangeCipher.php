<?php

declare(strict_types=1);

namespace Atom\Modules\AtomExchange;

use InvalidArgumentException;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;
use SensitiveParameter;
use Time\Duration;

/**
 * High-speed binary AEAD frame cipher (`ATMX` v1) using libsodium XChaCha20-Poly1305 IETF
 * (with OpenSSL AES-256-GCM fallback), HKDF-SHA256 key derivation, X25519 ECDH peer agreement,
 * optional raw DEFLATE compression, and bounded anti-replay nonce verification.
 */
final class ExchangeCipher
{
    private const string MAGIC = 'ATMX';
    private const int WIRE_VERSION = 1;
    private const int FLAG_ENCRYPTED = 0x01;
    private const int FLAG_COMPRESSED = 0x02;
    private const int FLAG_SODIUM = 0x04;

    private string $derivedKey;
    private string $macKey;
    private string $keyId;
    private bool $encryptEnabled;
    private bool $compressEnabled;
    private int $compressThresholdBytes;
    private int $maxFrameBytes;
    private Duration $replayWindow;
    private int $maxReplayNonces;
    private Randomizer $randomizer;

    /**
     * Bounded FIFO map of seen frame nonces to prevent replay attacks without memory leaks.
     *
     * @var array<string, int>
     */
    private array $seenNonces = [];

    public function __construct(
        #[SensitiveParameter]
        string $sharedSecret,
        string $keyId = 'atmx-k1',
        bool $encryptEnabled = true,
        bool $compressEnabled = true,
        int $compressThresholdBytes = 1024,
        int $maxFrameBytes = 4_194_304,
        ?Duration $replayWindow = null,
        int $maxReplayNonces = 4096,
    ) {
        if ($encryptEnabled && \trim($sharedSecret) === '') {
            throw new InvalidArgumentException('ExchangeCipher requires a non-empty shared secret when encryption is enabled.');
        }

        $seed = $sharedSecret !== '' ? $sharedSecret : 'atom-unencrypted-frame-key';
        $this->derivedKey = \hash_hkdf('sha256', $seed, 32, 'atom-exchange-aead|' . $keyId);
        $this->macKey = \hash_hkdf('sha256', $seed, 32, 'atom-exchange-mac|' . $keyId);
        $this->keyId = $keyId !== '' ? $keyId : 'atmx-k1';
        $this->encryptEnabled = $encryptEnabled;
        $this->compressEnabled = $compressEnabled;
        $this->compressThresholdBytes = \clamp($compressThresholdBytes, 128, 16_777_216);
        $this->maxFrameBytes = \clamp($maxFrameBytes, 1024, 67_108_864);
        $this->replayWindow = $replayWindow ?? Duration::fromSeconds(30);
        $this->maxReplayNonces = \clamp($maxReplayNonces, 64, 100_000);
        $this->randomizer = new Randomizer(new Secure());
    }

    /**
     * Generates an X25519 keypair (hex-encoded public & secret keys) for zero-trust peer pairing.
     *
     * @return array{publicKey: string, secretKey: string}
     */
    public static function generateKeyPair(): array
    {
        $pair = \sodium_crypto_box_keypair();
        return [
            'publicKey' => \bin2hex(\sodium_crypto_box_publickey($pair)),
            'secretKey' => \bin2hex(\sodium_crypto_box_secretkey($pair)),
        ];
    }

    /**
     * Derives a symmetric 256-bit shared secret (hex) from our X25519 secret key and a peer's public key.
     */
    public static function derivePeerSecret(
        #[SensitiveParameter]
        string $ourSecretKeyHex,
        string $peerPublicKeyHex,
        string $context = 'atom-cluster-v1',
    ): string {
        $sk = \hex2bin(\trim($ourSecretKeyHex));
        $pk = \hex2bin(\trim($peerPublicKeyHex));
        if ($sk === false || $pk === false || \strlen($sk) !== \SODIUM_CRYPTO_BOX_SECRETKEYBYTES || \strlen($pk) !== \SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('Invalid X25519 key lengths supplied for ECDH key agreement.');
        }

        $rawShared = \sodium_crypto_scalarmult($sk, $pk);
        return \bin2hex(\hash_hkdf('sha256', $rawShared, 32, $context));
    }

    /**
     * Seals an arbitrary payload into a compact, authenticated binary wire frame (returned as base64url-safe string
     * or raw binary when `$binaryOutput = true`).
     */
    public function seal(
        string $senderNodeId,
        string $recipientNodeId,
        string $channel,
        mixed $payload,
        bool $binaryOutput = false,
        ?int $nowTimestampNs = null,
    ): string {
        $packetId = \bin2hex($this->randomizer->getBytes(8));
        $timestampNs = $nowTimestampNs ?? (int) (\microtime(true) * 1_000_000_000);

        $envelopeJson = \json_encode([
            'id' => $packetId,
            'src' => $senderNodeId,
            'dst' => $recipientNodeId,
            'ch' => $channel,
            'ts' => $timestampNs,
            'data' => $payload,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        $flags = 0;
        $body = $envelopeJson;

        if ($this->compressEnabled && \strlen($body) >= $this->compressThresholdBytes) {
            $deflated = \gzdeflate($body, 1);
            if ($deflated !== false && \strlen($deflated) < \strlen($body)) {
                $body = $deflated;
                $flags |= self::FLAG_COMPRESSED;
            }
        }

        $useSodium = \function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
        if ($useSodium) {
            $flags |= self::FLAG_SODIUM;
        }

        $headerMeta = \json_encode([
            'v' => self::WIRE_VERSION,
            'kid' => $this->keyId,
            'f' => $flags | ($this->encryptEnabled ? self::FLAG_ENCRYPTED : 0),
            'ts' => $timestampNs,
        ], \JSON_THROW_ON_ERROR);

        if ($this->encryptEnabled) {
            $flags |= self::FLAG_ENCRYPTED;
            if ($useSodium) {
                $nonce = $this->randomizer->getBytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
                $aad = self::MAGIC . '|' . $headerMeta;
                $cipherText = \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($body, $aad, $nonce, $this->derivedKey);
            } else {
                $nonce = $this->randomizer->getBytes(12);
                $tag = '';
                $aad = self::MAGIC . '|' . $headerMeta;
                $encrypted = \openssl_encrypt($body, 'aes-256-gcm', $this->derivedKey, \OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
                if ($encrypted === false) {
                    throw new RuntimeException('OpenSSL AES-256-GCM frame encryption failed.');
                }
                $cipherText = $encrypted . $tag;
            }
        } else {
            $nonce = $this->randomizer->getBytes(16);
            $mac = \hash_hmac('sha256', self::MAGIC . '|' . $headerMeta . '|' . $nonce . '|' . $body, $this->macKey, true);
            $cipherText = $mac . $body;
        }

        $metaLen = \strlen($headerMeta);
        $nonceLen = \strlen($nonce);
        $rawFrame = self::MAGIC
            . \pack('nC', $metaLen, $nonceLen)
            . $headerMeta
            . $nonce
            . $cipherText;

        if (\strlen($rawFrame) > $this->maxFrameBytes) {
            throw new RuntimeException('AtomExchange frame exceeds configured maxFrameBytes limit.');
        }

        return $binaryOutput
            ? $rawFrame
            : \rtrim(\strtr(\base64_encode($rawFrame), '+/', '-_'), '=');
    }

    /**
     * Authenticates, checks replay window/nonces, decompresses, and decodes an `ATMX` frame into an `ExchangePacket`.
     */
    public function open(
        string $wireFrame,
        bool $isBinaryInput = false,
        ?int $nowTimestampNs = null,
        bool $enforceReplayProtection = true,
    ): ExchangePacket {
        $raw = $isBinaryInput
            ? $wireFrame
            : \base64_decode(\strtr($wireFrame, '-_', '+/'), true);

        if (!\is_string($raw) || \strlen($raw) < 16 || \strlen($raw) > $this->maxFrameBytes) {
            throw new RuntimeException('Invalid or oversized AtomExchange wire frame.');
        }

        if (\substr($raw, 0, 4) !== self::MAGIC) {
            throw new RuntimeException('Invalid AtomExchange frame magic header.');
        }

        $unpacked = \unpack('nmetaLen/CnonceLen', \substr($raw, 4, 3));
        if (!\is_array($unpacked)) {
            throw new RuntimeException('Corrupted AtomExchange frame header.');
        }

        $metaLen = (int) $unpacked['metaLen'];
        $nonceLen = (int) $unpacked['nonceLen'];
        $headerOffset = 7;

        if (\strlen($raw) < ($headerOffset + $metaLen + $nonceLen + 1)) {
            throw new RuntimeException('Truncated AtomExchange wire frame.');
        }

        $headerMeta = \substr($raw, $headerOffset, $metaLen);
        $nonce = \substr($raw, $headerOffset + $metaLen, $nonceLen);
        $cipherText = \substr($raw, $headerOffset + $metaLen + $nonceLen);

        $meta = \json_decode($headerMeta, true);
        if (!\is_array($meta) || ($meta['v'] ?? 0) !== self::WIRE_VERSION) {
            throw new RuntimeException('Unsupported AtomExchange frame version.');
        }

        $frameKeyId = (string) ($meta['kid'] ?? '');
        if (!\hash_equals($this->keyId, $frameKeyId)) {
            throw new RuntimeException("AtomExchange keyId mismatch ('{$frameKeyId}').");
        }

        $flags = (int) ($meta['f'] ?? 0);
        $isEncrypted = ($flags & self::FLAG_ENCRYPTED) !== 0;
        if ($this->encryptEnabled && !$isEncrypted) {
            throw new RuntimeException('Unencrypted frame rejected because encryption is mandatory.');
        }

        if ($isEncrypted) {
            $aad = self::MAGIC . '|' . $headerMeta;
            if (($flags & self::FLAG_SODIUM) !== 0) {
                $plain = \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipherText, $aad, $nonce, $this->derivedKey);
                if ($plain === false) {
                    throw new RuntimeException('AtomExchange AEAD authentication failed.');
                }
            } else {
                if (\strlen($cipherText) < 16) {
                    throw new RuntimeException('Truncated AES-GCM tag in AtomExchange frame.');
                }
                $tag = \substr($cipherText, -16);
                $encBody = \substr($cipherText, 0, -16);
                $plain = \openssl_decrypt($encBody, 'aes-256-gcm', $this->derivedKey, \OPENSSL_RAW_DATA, $nonce, $tag, $aad);
                if ($plain === false) {
                    throw new RuntimeException('AtomExchange AES-GCM authentication failed.');
                }
            }
        } else {
            if (\strlen($cipherText) < 32) {
                throw new RuntimeException('Truncated HMAC in AtomExchange frame.');
            }
            $providedMac = \substr($cipherText, 0, 32);
            $plain = \substr($cipherText, 32);
            $expectedMac = \hash_hmac('sha256', self::MAGIC . '|' . $headerMeta . '|' . $nonce . '|' . $plain, $this->macKey, true);
            if (!\hash_equals($expectedMac, $providedMac)) {
                throw new RuntimeException('AtomExchange frame HMAC verification failed.');
            }
        }

        $isCompressed = ($flags & self::FLAG_COMPRESSED) !== 0;
        if ($isCompressed) {
            $inflated = \gzinflate($plain, $this->maxFrameBytes);
            if ($inflated === false) {
                throw new RuntimeException('Failed to decompress AtomExchange payload.');
            }
            $plain = $inflated;
        }

        $decoded = \json_decode($plain, true);
        if (!\is_array($decoded) || !isset($decoded['id'], $decoded['src'], $decoded['dst'], $decoded['ch'], $decoded['ts'])) {
            throw new RuntimeException('Malformed AtomExchange inner envelope.');
        }

        $timestampNs = (int) $decoded['ts'];
        if ($enforceReplayProtection) {
            $nowNs = $nowTimestampNs ?? (int) (\microtime(true) * 1_000_000_000);
            $windowNs = ($this->replayWindow->seconds * 1_000_000_000) + $this->replayWindow->nanoseconds;
            if (\abs($nowNs - $timestampNs) > $windowNs) {
                throw new RuntimeException('AtomExchange frame rejected: timestamp outside allowed replay window.');
            }

            $nonceHash = \bin2hex($nonce) . ':' . (string) $decoded['id'];
            if (isset($this->seenNonces[$nonceHash])) {
                throw new RuntimeException('AtomExchange frame rejected: duplicate replay nonce detected.');
            }

            if (\count($this->seenNonces) >= $this->maxReplayNonces) {
                $oldestKey = \array_key_first($this->seenNonces);
                if (\is_string($oldestKey)) {
                    unset($this->seenNonces[$oldestKey]);
                }
            }
            $this->seenNonces[$nonceHash] = $timestampNs;
        }

        return new ExchangePacket(
            packetId: (string) $decoded['id'],
            senderNodeId: (string) $decoded['src'],
            recipientNodeId: (string) $decoded['dst'],
            channel: (string) $decoded['ch'],
            payload: $decoded['data'] ?? null,
            timestampNs: $timestampNs,
            keyId: $frameKeyId,
            encrypted: $isEncrypted,
            compressed: $isCompressed,
        );
    }
}
