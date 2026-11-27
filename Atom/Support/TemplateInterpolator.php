<?php

declare(strict_types=1);

namespace Atom\Support;

use Atom\Config\DotConfig;
use BackedEnum;
use Stringable;
use Time\Duration;
use UnitEnum;

/**
 * Stateless HTML template placeholder interpolator replacing `{{jakaśAplikacjia.core.version}}`
 * (UTF-8 escaped by default) and `{!! jakaśAplikacjia.core.html !!}` (explicit raw output)
 * from view parameters, `DataRegistry`, and `DotConfig`.
 */
final readonly class TemplateInterpolator
{
    private const string ESCAPED_PATTERN = '/\{\{\s*(config:[\p{L}\p{N}_.\-]+|[\p{L}\p{N}_\-]+(?:\.[\p{L}\p{N}_\-]+)*)\s*\}\}/u';
    private const string RAW_PATTERN = '/\{!!\s*(config:[\p{L}\p{N}_.\-]+|[\p{L}\p{N}_\-]+(?:\.[\p{L}\p{N}_\-]+)*)\s*!!\}/u';

    /**
     * Replaces matching `{{dot.key}}` and `{!! dot.key !!}` placeholders inside an HTML string.
     * Unresolved placeholders (such as `{{content}}` before layout assembly or client-side JS tokens)
     * remain untouched.
     *
     * @param array<string, mixed> $viewParams
     */
    public static function interpolate(
        string $html,
        array $viewParams = [],
        ?DataRegistry $primaryRegistry = null,
        ?DataRegistry $fallbackRegistry = null,
        bool $escapeByDefault = true,
    ): string {
        if ($html === '' || (!\str_contains($html, '{{') && !\str_contains($html, '{!!'))) {
            return $html;
        }

        $sentinel = new \stdClass();

        if (\str_contains($html, '{!!')) {
            $html = \preg_replace_callback(
                self::RAW_PATTERN,
                static function (array $matches) use ($viewParams, $primaryRegistry, $fallbackRegistry, $sentinel): string {
                    $token = $matches[1];
                    $resolved = self::resolveValue($token, $viewParams, $primaryRegistry, $fallbackRegistry, $sentinel);
                    if ($resolved === $sentinel) {
                        return $matches[0];
                    }

                    return self::stringify($resolved);
                },
                $html,
            ) ?? $html;
        }

        if (\str_contains($html, '{{')) {
            $html = \preg_replace_callback(
                self::ESCAPED_PATTERN,
                static function (array $matches) use ($viewParams, $primaryRegistry, $fallbackRegistry, $escapeByDefault, $sentinel): string {
                    $token = $matches[1];
                    if ($token === 'content' && !\array_key_exists('content', $viewParams)) {
                        return $matches[0];
                    }

                    $resolved = self::resolveValue($token, $viewParams, $primaryRegistry, $fallbackRegistry, $sentinel);
                    if ($resolved === $sentinel) {
                        return $matches[0];
                    }

                    $str = self::stringify($resolved);
                    return $escapeByDefault
                        ? \htmlspecialchars($str, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                        : $str;
                },
                $html,
            ) ?? $html;
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $viewParams
     */
    private static function resolveValue(
        string $token,
        array $viewParams,
        ?DataRegistry $primaryRegistry,
        ?DataRegistry $fallbackRegistry,
        object $sentinel,
    ): mixed {
        if (\str_starts_with($token, 'config:')) {
            $cfgPath = \substr($token, 7);
            return DotConfig::get($cfgPath, $sentinel);
        }

        if ($viewParams !== []) {
            $fromParams = DotConfig::getFromArray($viewParams, $token, $sentinel);
            if ($fromParams !== $sentinel) {
                return $fromParams;
            }
        }

        if ($primaryRegistry !== null && $primaryRegistry->has($token)) {
            return $primaryRegistry->get($token);
        }

        if ($fallbackRegistry !== null && $fallbackRegistry !== $primaryRegistry && $fallbackRegistry->has($token)) {
            return $fallbackRegistry->get($token);
        }

        if (\str_starts_with($token, 'atom.core.') || $token === 'atom.version') {
            return DotConfig::get($token, $sentinel);
        }

        return $sentinel;
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if ($value instanceof Duration) {
            return $value->seconds . 's';
        }
        if (\is_array($value)) {
            $encoded = \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            return $encoded !== false ? $encoded : '';
        }

        return '';
    }
}
