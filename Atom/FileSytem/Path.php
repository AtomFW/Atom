<?php

declare(strict_types=1);

/*
    Path class
    this file is path result
*/

namespace Atom\FileSytem;

/**
 * Path class for handling file system paths
 * This class provides utility methods for working with file paths
 * and ensures compatibility across different operating systems.
 */
final class Path
{
    /**
     * Threshold for path cleanup operations
     * @var int
     */
    private const CLEANUP_THRESHOLD = 1000;
    /**
     * Maximum allowed path length in characters
     * Used to prevent performance issues or system limitations
     */
    private const CLEANUP_SIZE = 800;
    /**
     * Buffer for caching path operations
     * @var array
     */
    private static $buffer = [];
    /**
     * Current size of the buffer
     * @var int
     */
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

    /**
     * Canonicalizes a path by removing '.' and '..' components, resolving symbolic links,
     * and handling the root directory properly. It also caches results to improve performance.
     *
     * @param string $path The path to canonicalize
     * @return string The canonicalized path
     */
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

    /**
     * This function returns the canonicalized home directory path.
     *
     * It first checks if the 'HOME' environment variable is set, and if so, returns the normalized home directory path.
     * If not, it checks if the 'HOMEDRIVE' and 'HOMEPATH' environment variables are set, and if so, returns the normalized home directory path.
     * If neither 'HOME' nor 'HOMEDRIVE' and 'HOMEPATH' are set, it throws a RuntimeException with a message indicating that the home directory cannot be determined.
     *
     * @return string The canonicalized home directory path.
     * @throws \RuntimeException If the home directory cannot be determined.
     */
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

    /**
     * This function finds the canonical parts of a given path relative to a given root.
     *
     * It takes a root path and a path and removes any '..' segments from the path, effectively limiting the path to the part that is within the root.
     * It does this by iterating over the parts of the path and adding them to an array, except for the '.' and '//' segments which are ignored.
     * If a '..' segment is found, the last part of the path is removed.
     *
     * @param string $root The root path.
     * @param string $path The path to be canonicalized.
     * @return array An array containing the canonical parts of the path.
     */
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

    /**
     * This function splits a given path into its root and relative parts.
     *
     * If the path starts with a forward slash ('/'), it is considered an absolute path and the root is set to '/'. The root is then removed from the path.
     * If the path does not start with a forward slash, it is considered a relative path and the root is set to an empty string.
     *
     * @param string $path The path to be split.
     * @return array An array containing the root and the relative part of the path.
     */
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
