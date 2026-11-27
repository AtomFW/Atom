<?php

declare(strict_types=1);

namespace Atom\Config;

use Atom\Atom;
use Throwable;

/**
 * High-performance dot-notation configuration accessor (`'atom.core.version'`, `'connectionInformation.extension'`,
 * `'database.choices.mysql.host'`) converting `.` segments into fast nested array lookups without references.
 */
final readonly class DotConfig
{
    /**
     * Resolves a dot-separated configuration key from loaded `Atom\Config\Config` namespaces,
     * `Atom` core metadata (`AboutAtom.php`), or top-level `app` configuration keys.
     */
    public static function get(string $path, mixed $default = null, bool $useDefaultChoice = true): mixed
    {
        $normalized = \trim($path);
        if ($normalized === '') {
            return $default;
        }

        $segments = \explode('.', $normalized);
        $root = \array_first($segments);
        if ($root === '') {
            return $default;
        }

        /** @var list<string> $tail */
        $tail = \array_slice($segments, 1);

        // 1. Virtual 'atom' namespace ('atom.core.version', 'atom.version', 'atom.<appKey>')
        if ($root === 'atom') {
            return self::resolveAtomNamespace($tail, $default, $useDefaultChoice);
        }

        // 2. Explicit registered config file namespace ('app.*', 'database.*', 'cluster.*', 'mcp.*', etc.)
        if (Config::hasNamespace($root)) {
            try {
                $section = Config::get($root, $useDefaultChoice);
            } catch (Throwable) {
                return $default;
            }

            if ($tail === []) {
                return $section;
            }

            return \is_array($section)
                ? self::traverseSegments($section, $tail, $default)
                : $default;
        }

        // 3. Developer-friendly shorthand into `config/app.php` (e.g. 'connectionInformation.extension')
        if (Config::isLoaded('app')) {
            try {
                $appConfig = Config::get('app', $useDefaultChoice);
                if (\is_array($appConfig) && \array_key_exists($root, $appConfig)) {
                    return self::traverseSegments($appConfig, $segments, $default);
                }
            } catch (Throwable) {
                return $default;
            }
        }

        return $default;
    }

    /**
     * Checks whether a dot-separated configuration path exists.
     */
    public static function has(string $path, bool $useDefaultChoice = true): bool
    {
        $sentinel = new \stdClass();
        return self::get($path, $sentinel, $useDefaultChoice) !== $sentinel;
    }

    /**
     * Reads a dot-separated key from an arbitrary associative array (`'a.b.c'` -> `$array['a']['b']['c']`).
     *
     * @param array<int|string, mixed> $source
     */
    public static function getFromArray(array $source, string $path, mixed $default = null): mixed
    {
        $normalized = \trim($path);
        if ($normalized === '') {
            return $default;
        }

        if (\array_key_exists($normalized, $source)) {
            return $source[$normalized];
        }

        return self::traverseSegments($source, \explode('.', $normalized), $default);
    }

    /**
     * Checks if a dot-separated key exists in an arbitrary associative array.
     *
     * @param array<int|string, mixed> $source
     */
    public static function hasInArray(array $source, string $path): bool
    {
        $sentinel = new \stdClass();
        return self::getFromArray($source, $path, $sentinel) !== $sentinel;
    }

    /**
     * Immutably sets a value at a dot-separated path inside an array without using PHP `&` references
     * (preserving copy-on-write and PHP 8.6 JIT type analysis).
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function setInArray(array $source, string $path, mixed $value): array
    {
        $normalized = \trim($path);
        if ($normalized === '') {
            return $source;
        }

        $segments = \array_values(\array_filter(
            \explode('.', $normalized),
            static fn(string $seg): bool => $seg !== '',
        ));

        if ($segments === []) {
            return $source;
        }

        return self::writeSegmentsRecursive($source, $segments, 0, $value);
    }

    /**
     * Immutably removes a dot-separated path from an associative array without `&` references.
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function removeFromArray(array $source, string $path): array
    {
        $normalized = \trim($path);
        if ($normalized === '') {
            return $source;
        }

        unset($source[$normalized]);
        $segments = \array_values(\array_filter(
            \explode('.', $normalized),
            static fn(string $seg): bool => $seg !== '',
        ));

        if ($segments === []) {
            return $source;
        }

        return self::removeSegmentsRecursive($source, $segments, 0);
    }

    /**
     * @param list<string> $tail
     */
    private static function resolveAtomNamespace(array $tail, mixed $default, bool $useDefaultChoice): mixed
    {
        $about = Atom::$about !== [] ? Atom::$about : self::loadAboutAtom();
        $appSection = [];
        if (Config::isLoaded('app')) {
            try {
                $loadedApp = Config::get('app', $useDefaultChoice);
                if (\is_array($loadedApp)) {
                    $appSection = $loadedApp;
                }
            } catch (Throwable) {
                $appSection = [];
            }
        }

        $virtualTree = [
            ...$appSection,
            ...$about,
            'core' => $about,
            'app' => $appSection,
        ];

        if ($tail === []) {
            return $virtualTree;
        }

        return self::traverseSegments($virtualTree, $tail, $default);
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadAboutAtom(): array
    {
        $aboutFile = \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'AboutAtom.php';
        if (!\is_file($aboutFile)) {
            return [];
        }

        $loaded = require $aboutFile;
        return \is_array($loaded) ? $loaded : [];
    }

    /**
     * @param array<int|string, mixed> $current
     * @param list<string> $segments
     */
    private static function traverseSegments(array $current, array $segments, mixed $default): mixed
    {
        $cursor = $current;

        foreach ($segments as $segment) {
            if (!\is_array($cursor)) {
                return $default;
            }

            if (\array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];
                continue;
            }

            if (\ctype_digit($segment) && \array_key_exists((int) $segment, $cursor)) {
                $cursor = $cursor[(int) $segment];
                continue;
            }

            return $default;
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $segments
     * @return array<string, mixed>
     */
    private static function writeSegmentsRecursive(array $node, array $segments, int $index, mixed $value): array
    {
        $key = $segments[$index];
        $isLast = ($index === \count($segments) - 1);

        if ($isLast) {
            $node[$key] = $value;
            return $node;
        }

        $child = isset($node[$key]) && \is_array($node[$key]) ? $node[$key] : [];
        /** @var array<string, mixed> $child */
        $node[$key] = self::writeSegmentsRecursive($child, $segments, $index + 1, $value);

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $segments
     * @return array<string, mixed>
     */
    private static function removeSegmentsRecursive(array $node, array $segments, int $index): array
    {
        $key = $segments[$index];
        if (!\array_key_exists($key, $node)) {
            return $node;
        }

        $isLast = ($index === \count($segments) - 1);
        if ($isLast) {
            unset($node[$key]);
            return $node;
        }

        if (\is_array($node[$key])) {
            /** @var array<string, mixed> $child */
            $child = $node[$key];
            $node[$key] = self::removeSegmentsRecursive($child, $segments, $index + 1);
        }

        return $node;
    }
}
