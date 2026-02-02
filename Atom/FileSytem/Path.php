<?php

/*
    Path class
    this file is path result
*/

namespace Atom\FileSytem;

final class Path
{
    private const CLEANUP_THRESHOLD = 1000;
    private const CLEANUP_SIZE = 800;
    private static $buffer = [];
    private static $bufferSize = 0;

    /**
     * normalize function
     *
     * @param string $path
     * @return string
     */
    public static function normalize(string $path): string
    {
        return str_replace('\\', DIRECTORY_SEPARATOR, $path);
    }

    public static function canonicalize(string $path): string
    {
        if ('' === $path) {
            return '';
        }

        // This method is called by many other methods in this class. Buffer
        // the canonicalized paths to make up for the severe performance
        // decrease.
        if (isset(self::$buffer[$path])) {
            return self::$buffer[$path];
        }

        // Replace "~" with user's home directory.
        if ('~' === $path[0]) {
            $path = self::getHomeDirectory() . substr($path, 1);
        }

        $path = self::normalize($path);

        [$root, $pathWithoutRoot] = self::split($path);

        $canonicalParts = self::findCanonicalParts($root, $pathWithoutRoot);

        // Add the root directory again
        self::$buffer[$path] = $canonicalPath = $root . implode('/', $canonicalParts);
        ++self::$bufferSize;

        // Clean up regularly to prevent memory leaks
        if (self::$bufferSize > self::CLEANUP_THRESHOLD) {
            self::$buffer = \array_slice(self::$buffer, -self::CLEANUP_SIZE, null, true);
            self::$bufferSize = self::CLEANUP_SIZE;
        }

        return $canonicalPath;
    }

    private static function getHomeDirectory(): string
    {
        if (isset($_SERVER['HOME'])) {
            return rtrim(self::normalize($_SERVER['HOME']), '/');
        }

        if (isset($_SERVER['HOMEDRIVE'], $_SERVER['HOMEPATH'])) {
            return rtrim(self::normalize($_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH']), '/');
        }

        throw new \RuntimeException(
            'Cannot determine the home directory from $_SERVER[\'HOME\'] or ' .
            '$_SERVER[\'HOMEDRIVE\'] and $_SERVER[\'HOMEPATH\'].'
        );
    }

    private static function findCanonicalParts(string $root, string $path): array
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ('.' === $part) {
                continue;
            }
            if ('..' === $part) {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        return $parts;
    }

    private static function split(string $path): array
    {
        $root = '';
        if ('/' === $path[0]) {
            $root = '/';
            $path = substr($path, 1);
        }
        return [$root, $path];
    }
}
