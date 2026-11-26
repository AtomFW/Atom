<?php

declare(strict_types=1);

namespace Atom\Filter;

use function array_fill_keys;
use function html_entity_decode;
use function htmlspecialchars;
use function is_array;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function preg_replace;
use function str_replace;
use function strtolower;
use function trim;

/**
 * Stateless, Swoole-safe input sanitizer and contextual output escaper.
 */
final readonly class InputFilter
{
    private const array SENSITIVE_FIELD_NAMES = [
        'password' => true,
        'password_confirm' => true,
        'passwordconfirm' => true,
        'password_confirmation' => true,
        'current_password' => true,
        'currentpassword' => true,
        'new_password' => true,
        'newpassword' => true,
        'secret' => true,
        'token' => true,
        'api_key' => true,
        'private_key' => true,
    ];

    /**
     * Cleans raw input payload (from query, form body, or decoded JSON) without
     * HTML-encoding characters so database values, passwords, and JSON stay intact.
     *
     * @param array<int|string, mixed> $input
     * @param list<string> $rawFields Additional field names excluded from whitespace trimming
     * @return array<int|string, mixed>
     */
    public static function cleanArray(
        array $input,
        bool $encodeSpecialChars = false,
        array $rawFields = [],
    ): array {
        $extraRawMap = $rawFields !== []
            ? array_fill_keys(\array_map(strtolower(...), $rawFields), true)
            : [];

        return self::doCleanArray($input, $encodeSpecialChars, $extraRawMap);
    }

    /**
     * @param array<int|string, mixed> $input
     * @param array<string, bool> $extraRawMap
     * @return array<int|string, mixed>
     */
    private static function doCleanArray(array $input, bool $encodeSpecialChars, array $extraRawMap): array
    {
        $cleaned = [];

        foreach ($input as $key => $value) {
            $cleanKey = is_string($key) ? self::stripControlBytes($key) : $key;
            $isSensitive = is_string($cleanKey)
                && (self::isSensitiveField($cleanKey) || isset($extraRawMap[strtolower(trim($cleanKey))]));

            if (is_array($value)) {
                $cleaned[$cleanKey] = self::doCleanArray($value, $encodeSpecialChars && !$isSensitive, $extraRawMap);
                continue;
            }

            if (is_string($value)) {
                $cleaned[$cleanKey] = self::cleanString($value, !$isSensitive, $encodeSpecialChars && !$isSensitive);
                continue;
            }

            $cleaned[$cleanKey] = $value;
        }

        return $cleaned;
    }

    /**
     * Cleans a single string value: removes null bytes and control characters,
     * normalizes CRLF to LF and trims surrounding whitespace unless sensitive.
     */
    public static function cleanString(
        #[\SensitiveParameter]
        string $value,
        bool $trimWhitespace = true,
        bool $encodeSpecialChars = false,
    ): string {
        $clean = str_replace("\0", '', $value);

        if ($trimWhitespace) {
            $clean = str_replace(["\r\n", "\r"], "\n", $clean);
            $clean = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean) ?? $clean;
            $clean = trim($clean);
        }

        if ($encodeSpecialChars) {
            return self::escapeHtml($clean);
        }

        return $clean;
    }

    /**
     * Contextually escapes a value for safe insertion into HTML attributes or text nodes.
     */
    public static function escapeHtml(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value === true) {
            return '1';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof \BackedEnum) {
            return htmlspecialchars((string) $value->value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        }

        if (is_string($value) || $value instanceof \Stringable) {
            return htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        }

        return '';
    }

    public static function decodeHtmlEntities(string $value): string
    {
        return html_entity_decode($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function isSensitiveField(string $fieldName): bool
    {
        $normalized = $fieldName
            |> trim(...)
            |> strtolower(...);

        return isset(self::SENSITIVE_FIELD_NAMES[$normalized]);
    }

    private static function stripControlBytes(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
    }
}
