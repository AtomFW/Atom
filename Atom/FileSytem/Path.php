<?php

declare(strict_types=1);

namespace Atom\FileSytem;

/**
 * Cross-platform path normalization and bounded canonicalization cache.
 */
final class Path
{
    private const int CLEANUP_THRESHOLD = 1000;
    private const int CLEANUP_SIZE = 800;

    /** @var array<string, string> */
    private static array $buffer = [];

    private static int $bufferSize = 0;

    public static function normalize(string $path): string
    {
        return \str_replace('\\', \DIRECTORY_SEPARATOR, $path);
    }

    public static function canonicalize(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if (isset(self::$buffer[$path])) {
            return self::$buffer[$path];
        }

        $inputKey = $path;

        if ($path[0] === '~') {
            $path = self::getHomeDirectory() . \substr($path, 1);
        }

        $path = self::normalize($path);
        [$root, $pathWithoutRoot] = self::split($path);
        $canonicalParts = self::findCanonicalParts($pathWithoutRoot);

        $canonicalPath = $root . \implode('/', $canonicalParts);
        self::$buffer[$inputKey] = $canonicalPath;
        ++self::$bufferSize;

        if (self::$bufferSize > self::CLEANUP_THRESHOLD) {
            self::$buffer = \array_slice(self::$buffer, -self::CLEANUP_SIZE, null, true);
            self::$bufferSize = self::CLEANUP_SIZE;
        }

        return $canonicalPath;
    }

    private static function getHomeDirectory(): string
    {
        $home = \getenv('HOME');
        if (\is_string($home) && $home !== '') {
            return \rtrim(self::normalize($home), '/');
        }

        $homeDrive = \getenv('HOMEDRIVE');
        $homePath = \getenv('HOMEPATH');
        if (\is_string($homeDrive) && $homeDrive !== '' && \is_string($homePath) && $homePath !== '') {
            return \rtrim(self::normalize($homeDrive . $homePath), '/');
        }

        throw new \RuntimeException('Cannot determine the home directory from HOME or HOMEDRIVE/HOMEPATH.');
    }

    /**
     * @return list<string>
     */
    private static function findCanonicalParts(string $path): array
    {
        $parts = [];
        foreach (\explode('/', $path) as $part) {
            if ($part === '.') {
                continue;
            }
            if ($part === '..') {
                \array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function split(string $path): array
    {
        if ($path !== '' && $path[0] === '/') {
            return ['/', \substr($path, 1)];
        }

        return ['', $path];
    }
}
