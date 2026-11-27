<?php

declare(strict_types=1);

namespace Tests\Atom\Filter;

use Atom\Component\Uid\Ulid;
use Atom\DataBase\AutoMapped;
use Atom\Filter\TypeCaster;
use Atom\Model;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CustomValidatedModel extends Model
{
    public string $slug = '';
    public string $ipAddress = '';
    public string $hexColor = '';
    public string $macAddress = '';
    public string $uuid = '';
    public string $ulid = '';
    public string $payloadJson = '';
    public string $tier = '';
    public int $evenNumber = 2;

    #[\Override]
    public function rules(): array
    {
        return [
            'slug' => [self::RULE_REQUIRED, self::RULE_SLUG],
            'ipAddress' => [self::RULE_IP],
            'hexColor' => [self::RULE_HEX_COLOR],
            'macAddress' => [self::RULE_MAC],
            'uuid' => [self::RULE_UUID],
            'ulid' => [self::RULE_ULID],
            'payloadJson' => [self::RULE_JSON],
            'tier' => [[self::RULE_IN, 'values' => ['free', 'pro', 'enterprise']]],
            'evenNumber' => [
                static fn(mixed $val): bool|string => (\is_int($val) && $val % 2 === 0) ? true : 'Number must be even',
            ],
        ];
    }
}

final class ExpandedAutoMappingTest extends TestCase
{
    public function testSupportedTypesIncludesNewBuiltins(): void
    {
        $types = AutoMapped::supportedTypes();

        self::assertContains('slug', $types);
        self::assertContains('money', $types);
        self::assertContains('gzjson', $types);
        self::assertContains('ipv4_int', $types);
        self::assertContains('ulid_bin', $types);
        self::assertContains('csv', $types);
        self::assertContains('set', $types);
    }

    public function testStringAndNetworkNormalizations(): void
    {
        self::assertSame('user@example.com', TypeCaster::castFromForm('  User@Example.COM ', 'email'));
        self::assertSame('hello-atom-8-6', TypeCaster::castFromForm('  Hello, Atom 8.6!! ', 'slug'));
        self::assertSame('+48123456789', TypeCaster::castFromForm('+48 (123) 456-789', 'phone'));
        self::assertSame('00:1a:2b:3c:4d:5e', TypeCaster::castFromForm('00-1A-2B-3C-4D-5E', 'mac'));
        self::assertSame('#ffaa00', TypeCaster::castFromForm('#FA0', 'hex_color'));
        self::assertSame('2.1.0', TypeCaster::castFromForm('v2.1', 'semver'));

        $ipInt = AutoMapped::mapValueFromPHP('192.168.1.10', 'ipv4_int');
        self::assertIsInt($ipInt);
        self::assertSame('192.168.1.10', AutoMapped::mapValueFromDB($ipInt, 'ipv4_int'));

        $ipBin = AutoMapped::mapValueFromPHP('2001:db8::1', 'ip_bin');
        self::assertSame(16, \strlen((string) $ipBin));
        self::assertSame('2001:db8::1', AutoMapped::mapValueFromDB($ipBin, 'ip_bin'));
    }

    public function testCollectionsMoneyCompressionAndEncodings(): void
    {
        self::assertSame(['php', 'swoole', 'atom'], AutoMapped::mapValueFromDB('php, swoole , atom', 'csv'));
        self::assertSame('php,swoole,atom', AutoMapped::mapValueFromPHP(['php', 'swoole', 'atom'], 'csv'));

        self::assertSame(['read', 'write'], AutoMapped::mapValueFromDB('read, write, read', 'set'));
        self::assertSame([10, 20, 30], AutoMapped::mapValueFromDB('10, 20, 30', 'int_list'));
        self::assertSame([1.5, 2.25], AutoMapped::mapValueFromDB('1.5, 2.25', 'float_list'));

        self::assertSame(1999, AutoMapped::mapValueFromPHP(19.99, 'money'));
        self::assertSame(19.99, AutoMapped::mapValueFromDB(1999, 'money'));
        self::assertSame('19.99', AutoMapped::mapValueFromDB(1999, 'money_decimal'));

        self::assertSame(100.0, TypeCaster::castFromForm('145.5', 'percent'));
        self::assertSame(0.0, TypeCaster::castFromForm('-12', 'percent'));

        $payload = ['framework' => 'Atom', 'features' => ['ram-cache', 'modules', 'cli']];
        $compressed = AutoMapped::mapValueFromPHP($payload, 'gzjson');
        self::assertIsString($compressed);
        self::assertSame($payload, AutoMapped::mapValueFromDB($compressed, 'gzjson'));

        $encoded = AutoMapped::mapValueFromPHP('raw-secret', 'base64');
        self::assertSame('raw-secret', AutoMapped::mapValueFromDB($encoded, 'base64'));

        $hex = AutoMapped::mapValueFromPHP('ABC', 'hex');
        self::assertSame('414243', $hex);
        self::assertSame('ABC', AutoMapped::mapValueFromDB($hex, 'hex'));
    }

    public function testTemporalAndUlidBinaryMappings(): void
    {
        $ts = 1_750_000_000;
        $dt = AutoMapped::mapValueFromDB($ts, 'timestamp');
        self::assertInstanceOf(DateTimeImmutable::class, $dt);
        self::assertSame($ts, AutoMapped::mapValueFromPHP($dt, 'timestamp'));

        $dateOnly = AutoMapped::mapValueFromDB('2026-11-26', 'date_immutable');
        self::assertInstanceOf(DateTimeImmutable::class, $dateOnly);
        self::assertSame('2026-11-26', AutoMapped::mapValueFromPHP($dateOnly, 'date_immutable'));

        $ulid = new Ulid();
        $bin = AutoMapped::mapValueFromPHP($ulid, 'ulid_bin');
        self::assertSame(16, \strlen((string) $bin));
        self::assertSame($ulid->toBase32(), AutoMapped::mapValueFromDB($bin, 'ulid_bin'));
    }

    public function testRowMappingWithTypesAndCustomCallables(): void
    {
        $dbRow = [
            'price_cents' => 4550,
            'tags' => 'fast,stateless',
            'custom_code' => 'ab',
        ];

        $phpRow = AutoMapped::mapRowFromDB($dbRow, [
            'price_cents' => 'money',
            'tags' => 'csv',
            'custom_code' => static fn(mixed $v): string => \strtoupper((string) $v) . '_OK',
        ]);

        self::assertSame(45.5, $phpRow['price_cents']);
        self::assertSame(['fast', 'stateless'], $phpRow['tags']);
        self::assertSame('AB_OK', $phpRow['custom_code']);
    }

    public function testModelCustomValidationAndNewFormatRules(): void
    {
        $model = new CustomValidatedModel();
        $model->slug = 'valid-slug-1';
        $model->ipAddress = '10.0.0.1';
        $model->hexColor = '#38bdf8';
        $model->macAddress = '00:1a:2b:3c:4d:5e';
        $model->uuid = '123e4567-e89b-42d3-a456-426614174000';
        $model->ulid = (new Ulid())->toBase32();
        $model->payloadJson = '{"ok":true}';
        $model->tier = 'pro';
        $model->evenNumber = 4;

        self::assertTrue($model->validate());

        $model->slug = 'Invalid Slug!';
        $model->evenNumber = 3;
        $model->tier = 'unknown';

        self::assertFalse($model->validate());
        self::assertSame('This field must be a valid lowercase URL slug', $model->getFirstError('slug'));
        self::assertSame('Number must be even', $model->getFirstError('evenNumber'));
        self::assertSame('Selected value is not allowed', $model->getFirstError('tier'));

        self::assertFalse($model->validateWith('ipAddress', static fn(mixed $ip): bool => $ip === '127.0.0.1', 'Loopback IP required'));
        self::assertSame('Loopback IP required', $model->getFirstError('ipAddress'));
    }
}
