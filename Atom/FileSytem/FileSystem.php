<?php

declare(strict_types=1);

namespace Atom\FileSytem;

use Atom\Exception\IO\Generative\FileNotFoundGenerativeException;
use Atom\Exception\IO\Generative\InvalidArgumentGenerativeException;
use Atom\Exception\IO\Generative\IOGenerativeException;

/**
 * Stateless filesystem operations manager with deterministic stream cleanup,
 * PHP 8.6 flush/close error verification, and cryptographically safe temporary names.
 */
final class FileSystem
{
    private static ?string $lastError = null;

    public function copy(string $originFile, string $targetFile, bool $overwriteNewerFiles = false): bool
    {
        $originIsLocal = \stream_is_local($originFile) || \stripos($originFile, 'file://') === 0;

        if ($originIsLocal && !\is_file($originFile)) {
            throw new FileNotFoundGenerativeException('Failed to copy "%s" because file does not exist.', $originFile);
        }

        $this->mkdir(\dirname($targetFile));

        $doCopy = true;
        if (!$overwriteNewerFiles && !$this->hasRemoteHost($originFile) && \is_file($targetFile)) {
            $doCopy = (int) \filemtime($originFile) > (int) \filemtime($targetFile);
        }

        if (!$doCopy) {
            return false;
        }

        $source = self::box('fopen', $originFile, 'rb');
        if (!\is_resource($source)) {
            throw new IOGenerativeException(
                'Failed to copy "%s" to "%s" because source file could not be opened for reading: %s',
                $originFile,
                $targetFile,
                self::$lastError ?? 'unknown error',
            );
        }

        $target = self::box(
            'fopen',
            $targetFile,
            'wb',
            false,
            \stream_context_create(['ftp' => ['overwrite' => true]]),
        );

        if (!\is_resource($target)) {
            self::box('fclose', $source);
            throw new IOGenerativeException(
                'Failed to copy "%s" to "%s" because target file could not be opened for writing: %s',
                $originFile,
                $targetFile,
                self::$lastError ?? 'unknown error',
            );
        }

        $bytesCopied = false;
        $closeTargetOk = true;
        try {
            $bytesCopied = \stream_copy_to_stream($source, $target);
        } finally {
            self::box('fclose', $source);
            $closeTargetOk = (bool) self::box('fclose', $target);
        }

        if (!$closeTargetOk || !\is_file($targetFile)) {
            throw new IOGenerativeException('Failed to copy "%s" to "%s".', $originFile, $targetFile);
        }

        if ($originIsLocal) {
            self::box('chmod', $targetFile, (int) \fileperms($targetFile) | ((int) \fileperms($originFile) & 0111));

            $bytesOrigin = \filesize($originFile);
            if ($bytesCopied === false || $bytesCopied !== $bytesOrigin) {
                throw new IOGenerativeException(
                    'Failed to copy the whole content of "%s" to "%s" (%g of %g bytes copied).',
                    $originFile,
                    $targetFile,
                    (float) ($bytesCopied ?: 0),
                    (float) ($bytesOrigin ?: 0),
                );
            }

            return true;
        }

        return $bytesCopied !== false;
    }

    /**
     * @param string|iterable<mixed, string> $dirs
     */
    public function mkdir(string|iterable $dirs, int $mode = 0777): void
    {
        foreach ($this->toIterable($dirs) as $dir) {
            if (\is_dir($dir)) {
                continue;
            }

            if (!self::box('mkdir', $dir, $mode, true) && !\is_dir($dir)) {
                throw new IOGenerativeException(
                    'Failed to create "%s": %s',
                    $dir,
                    self::$lastError ?? 'unknown error',
                );
            }
        }
    }

    /**
     * @param string|iterable<mixed, string> $files
     */
    public function exists(string|iterable $files): bool
    {
        $maxPathLength = \PHP_MAXPATHLEN - 2;

        foreach ($this->toIterable($files) as $file) {
            $filePath = (string) $file;
            if (\strlen($filePath) > $maxPathLength) {
                throw new IOGenerativeException(
                    'Could not check if file exist because path length exceeds %d characters.',
                    $maxPathLength,
                );
            }

            if (!\file_exists($filePath)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string|iterable<mixed, string> $files
     */
    public function touch(string|iterable $files, ?int $time = null, ?int $atime = null): void
    {
        foreach ($this->toIterable($files) as $file) {
            $ok = $time !== null
                ? ($atime !== null ? self::box('touch', $file, $time, $atime) : self::box('touch', $file, $time))
                : self::box('touch', $file);

            if (!$ok) {
                throw new IOGenerativeException('Failed to touch "%s": %s', (string) $file, self::$lastError ?? '');
            }
        }
    }

    /**
     * @param string|iterable<mixed, string|\SplFileInfo> $files
     */
    public function remove(string|iterable $files): void
    {
        if ($files instanceof \Traversable) {
            $list = [];
            foreach ($files as $item) {
                $list[] = $item instanceof \SplFileInfo ? $item->getPathname() : (string) $item;
            }
            $files = $list;
        } elseif (!\is_array($files)) {
            $files = [$files];
        } else {
            $files = \array_map(
                static fn(mixed $item): string => $item instanceof \SplFileInfo ? $item->getPathname() : (string) $item,
                $files,
            );
        }

        self::doRemove($files, false);
    }

    /**
     * @param array<int|string, string> $files
     */
    private static function doRemove(array $files, bool $isRecursive): void
    {
        $files = \array_reverse($files);
        foreach ($files as $file) {
            if (\is_link($file)) {
                if (
                    !(self::box('unlink', $file) || \DIRECTORY_SEPARATOR !== '\\' || self::box('rmdir', $file))
                    && \file_exists($file)
                ) {
                    throw new IOGenerativeException(
                        'Failed to remove symlink "%s": %s',
                        $file,
                        self::$lastError ?? '',
                    );
                }
            } elseif (\is_dir($file)) {
                if (!$isRecursive) {
                    $real = \realpath($file);
                    $parentDir = \dirname($real !== false ? $real : $file);
                    $tmpName = $parentDir . '/.' . \bin2hex(\random_bytes(4));

                    if (\file_exists($tmpName)) {
                        try {
                            self::doRemove([$tmpName], true);
                        } catch (IOGenerativeException) {
                            throw new IOGenerativeException(
                                'Failed to remove directory "%s" because temporary name "%s" already exists.',
                                $file,
                                $tmpName,
                            );
                        }
                    }

                    if (!\file_exists($tmpName) && self::box('rename', $file, $tmpName)) {
                        $file = $tmpName;
                    }
                }

                $filesystemIterator = new \FilesystemIterator(
                    $file,
                    \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS,
                );
                self::doRemove(\iterator_to_array($filesystemIterator, true), true);

                if (!self::box('rmdir', $file) && \file_exists($file) && !$isRecursive) {
                    throw new IOGenerativeException('Failed to remove directory "%s": %s', $file, self::$lastError ?? '');
                }
            } elseif (
                !self::box('unlink', $file)
                && (\str_contains((string) self::$lastError, 'Permission denied') || \file_exists($file))
            ) {
                throw new IOGenerativeException('Failed to remove file "%s": %s', $file, self::$lastError ?? '');
            }
        }
    }

    /**
     * @param string|iterable<mixed, string|\SplFileInfo> $files
     */
    public function chmod(string|iterable $files, int $mode, int $umask = 0000, bool $recursive = false): void
    {
        foreach ($this->toIterable($files) as $file) {
            $path = $file instanceof \SplFileInfo ? $file->getPathname() : (string) $file;
            if (!self::box('chmod', $path, $mode & ~$umask)) {
                throw new IOGenerativeException('Failed to chmod file "%s": %s', $path, self::$lastError ?? '');
            }
            if ($recursive && \is_dir($path) && !\is_link($path)) {
                $this->chmod(new \FilesystemIterator($path), $mode, $umask, true);
            }
        }
    }

    /**
     * @param string|iterable<mixed, string|\SplFileInfo> $files
     */
    public function chown(string|iterable $files, string|int $user, bool $recursive = false): void
    {
        foreach ($this->toIterable($files) as $file) {
            $path = $file instanceof \SplFileInfo ? $file->getPathname() : (string) $file;
            if ($recursive && \is_dir($path) && !\is_link($path)) {
                $this->chown(new \FilesystemIterator($path), $user, true);
            }
            if (\is_link($path) && \function_exists('lchown')) {
                if (!self::box('lchown', $path, $user)) {
                    throw new IOGenerativeException('Failed to chown file "%s": %s', $path, self::$lastError ?? '');
                }
            } elseif (!self::box('chown', $path, $user)) {
                throw new IOGenerativeException('Failed to chown file "%s": %s', $path, self::$lastError ?? '');
            }
        }
    }

    /**
     * @param string|iterable<mixed, string|\SplFileInfo> $files
     */
    public function chgrp(string|iterable $files, string|int $group, bool $recursive = false): void
    {
        foreach ($this->toIterable($files) as $file) {
            $path = $file instanceof \SplFileInfo ? $file->getPathname() : (string) $file;
            if ($recursive && \is_dir($path) && !\is_link($path)) {
                $this->chgrp(new \FilesystemIterator($path), $group, true);
            }
            if (\is_link($path) && \function_exists('lchgrp')) {
                if (!self::box('lchgrp', $path, $group)) {
                    throw new IOGenerativeException('Failed to chgrp file "%s": %s', $path, self::$lastError ?? '');
                }
            } elseif (!self::box('chgrp', $path, $group)) {
                throw new IOGenerativeException('Failed to chgrp file "%s": %s', $path, self::$lastError ?? '');
            }
        }
    }

    public function rename(string $origin, string $target, bool $overwrite = false): void
    {
        if (!$overwrite && $this->isReadable($target)) {
            throw new IOGenerativeException('Cannot rename because the target "%s" already exists.', $target);
        }

        if (!self::box('rename', $origin, $target)) {
            if (\is_dir($origin)) {
                $this->mirror($origin, $target, null, ['override' => $overwrite, 'delete' => $overwrite]);
                $this->remove($origin);
                return;
            }
            throw new IOGenerativeException('Cannot rename "%s" to "%s": %s', $origin, $target, self::$lastError ?? '');
        }
    }

    private function isReadable(string $filename): bool
    {
        $maxPathLength = \PHP_MAXPATHLEN - 2;
        if (\strlen($filename) > $maxPathLength) {
            throw new IOGenerativeException(
                'Could not check if file is readable because path length exceeds %d characters.',
                $maxPathLength,
            );
        }

        return \is_readable($filename) || \file_exists($filename);
    }

    public function symlink(string $originDir, string $targetDir, bool $copyOnWindows = false): void
    {
        self::assertFunctionExists('symlink');

        if (\DIRECTORY_SEPARATOR === '\\') {
            $originDir = \strtr($originDir, '/', '\\');
            $targetDir = \strtr($targetDir, '/', '\\');

            if ($copyOnWindows) {
                if (\is_dir($originDir)) {
                    $this->mirror($originDir, $targetDir);
                } else {
                    $this->copy($originDir, $targetDir);
                }
                return;
            }
        }

        $this->mkdir(\dirname($targetDir));

        if (\is_link($targetDir)) {
            if (\readlink($targetDir) === $originDir) {
                return;
            }
            $this->remove($targetDir);
        }

        if (!self::box('symlink', $originDir, $targetDir)) {
            $this->linkException($originDir, $targetDir, 'symbolic');
        }
    }

    /**
     * @param string|iterable<mixed, string> $targetFiles
     */
    public function hardlink(string $originFile, string|iterable $targetFiles): void
    {
        self::assertFunctionExists('link');

        if (!$this->exists($originFile)) {
            throw new FileNotFoundGenerativeException('Origin file "%s" does not exist.', $originFile);
        }

        if (!\is_file($originFile)) {
            throw new FileNotFoundGenerativeException('Origin file "%s" is not a file.', $originFile);
        }

        foreach ($this->toIterable($targetFiles) as $targetFile) {
            $targetPath = (string) $targetFile;
            if (\is_file($targetPath)) {
                if (\fileinode($originFile) === \fileinode($targetPath)) {
                    continue;
                }
                $this->remove($targetPath);
            }

            if (!self::box('link', $originFile, $targetPath)) {
                $this->linkException($originFile, $targetPath, 'hard');
            }
        }
    }

    private function linkException(string $origin, string $target, string $linkType): never
    {
        if (self::$lastError !== null && \DIRECTORY_SEPARATOR === '\\' && \str_contains(self::$lastError, 'error code(1314)')) {
            throw new IOGenerativeException(
                'Unable to create "%s" link due to error code 1314: \'A required privilege is not held '
                . 'by the client\'. Do you have the required Administrator-rights?',
                $linkType,
            );
        }

        throw new IOGenerativeException(
            'Failed to create "%s" link from "%s" to "%s": %s',
            $linkType,
            $origin,
            $target,
            self::$lastError ?? '',
        );
    }

    public function readlink(string $path, bool $canonicalize = false): ?string
    {
        if (!$canonicalize && !\is_link($path)) {
            return null;
        }

        if ($canonicalize) {
            if (!$this->exists($path)) {
                return null;
            }
            $resolved = \realpath($path);
            return $resolved !== false ? $resolved : null;
        }

        $target = \readlink($path);
        return $target !== false ? $target : null;
    }

    public function makePathRelative(string $endPath, string $startPath): string
    {
        if (!$this->isAbsolutePath($startPath)) {
            throw new InvalidArgumentGenerativeException('The start path "%s" is not absolute.', $startPath);
        }

        if (!$this->isAbsolutePath($endPath)) {
            throw new InvalidArgumentGenerativeException('The end path "%s" is not absolute.', $endPath);
        }

        if (\DIRECTORY_SEPARATOR === '\\') {
            $endPath = \str_replace('\\', '/', $endPath);
            $startPath = \str_replace('\\', '/', $startPath);
        }

        $splitDriveLetter = static fn(string $path): array =>
            \strlen($path) > 2 && $path[1] === ':' && $path[2] === '/' && \ctype_alpha($path[0])
                ? [\substr($path, 2), \strtoupper($path[0])]
                : [$path, null];

        $splitPath = static function (string $path): array {
            $result = [];
            foreach (\explode('/', \trim($path, '/')) as $segment) {
                if ($segment === '..') {
                    \array_pop($result);
                } elseif ($segment !== '.' && $segment !== '') {
                    $result[] = $segment;
                }
            }
            return $result;
        };

        [$endPath, $endDriveLetter] = $splitDriveLetter($endPath);
        [$startPath, $startDriveLetter] = $splitDriveLetter($startPath);

        $startPathArr = $splitPath($startPath);
        $endPathArr = $splitPath($endPath);

        if ($endDriveLetter !== null && $startDriveLetter !== null && $endDriveLetter !== $startDriveLetter) {
            return $endDriveLetter . ':/' . ($endPathArr !== [] ? \implode('/', $endPathArr) . '/' : '');
        }

        $index = 0;
        while (
            isset($startPathArr[$index], $endPathArr[$index])
            && $startPathArr[$index] === $endPathArr[$index]
        ) {
            ++$index;
        }

        $depth = \count($startPathArr) === 1 ? 0 : \count($startPathArr) - $index;
        $traverser = \str_repeat('../', \max(0, $depth));
        $endPathRemainder = \implode('/', \array_slice($endPathArr, $index));
        $relativePath = $traverser . ($endPathRemainder !== '' ? $endPathRemainder . '/' : '');

        return $relativePath === '' ? './' : $relativePath;
    }

    /**
     * @param \Traversable<mixed, \SplFileInfo>|null $iterator
     * @param array<string, mixed> $options
     */
    public function mirror(
        string $originDir,
        string $targetDir,
        ?\Traversable $iterator = null,
        array $options = [],
    ): void {
        $targetDir = \rtrim($targetDir, '/\\');
        $originDir = \rtrim($originDir, '/\\');
        $originDirLen = \strlen($originDir);

        if (!$this->exists($originDir)) {
            throw new IOGenerativeException('The origin directory specified "%s" was not found.', $originDir);
        }

        if ($this->exists($targetDir) && !empty($options['delete'])) {
            $deleteIterator = $iterator ?? new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($targetDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            $targetDirLen = \strlen($targetDir);
            foreach ($deleteIterator as $file) {
                $origin = $originDir . \substr($file->getPathname(), $targetDirLen);
                if (!$this->exists($origin)) {
                    $this->remove($file->getPathname());
                }
            }
        }

        $copyOnWindows = (bool) ($options['copy_on_windows'] ?? false);

        if ($iterator === null) {
            $flags = $copyOnWindows
                ? \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
                : \FilesystemIterator::SKIP_DOTS;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($originDir, $flags),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
        }

        $this->mkdir($targetDir);
        $filesCreatedWhileMirroring = [];

        foreach ($iterator as $file) {
            $realPath = $file->getRealPath();
            if (
                $file->getPathname() === $targetDir
                || $realPath === $targetDir
                || ($realPath !== false && isset($filesCreatedWhileMirroring[$realPath]))
            ) {
                continue;
            }

            $target = $targetDir . \substr($file->getPathname(), $originDirLen);
            $filesCreatedWhileMirroring[$target] = true;

            if (!$copyOnWindows && $file->isLink()) {
                $this->symlink($file->getLinkTarget(), $target);
            } elseif ($file->isDir()) {
                $this->mkdir($target);
            } elseif ($file->isFile()) {
                $this->copy($file->getPathname(), $target, (bool) ($options['override'] ?? false));
            } else {
                throw new IOGenerativeException('Unable to guess "%s" file type.', $file->getPathname());
            }
        }
    }

    public function isAbsolutePath(string $file): bool
    {
        if ($file === '') {
            return false;
        }

        if (\strspn($file, '/\\', 0, 1) > 0) {
            return true;
        }

        if (\strlen($file) >= 3 && \ctype_alpha($file[0]) && $file[1] === ':' && \strspn($file, '/\\', 2, 1) > 0) {
            return true;
        }

        return \preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $file) === 1;
    }

    public function tempnam(string $dir, string $prefix, string $suffix = ''): string
    {
        [$scheme, $hierarchy] = $this->getSchemeAndHierarchy($dir);

        if (($scheme === null || $scheme === 'file' || $scheme === 'gs') && $suffix === '') {
            $tmpFile = self::box('tempnam', $hierarchy, $prefix);
            if (\is_string($tmpFile) && $tmpFile !== '') {
                if ($scheme !== null && $scheme !== 'gs') {
                    return $scheme . '://' . $tmpFile;
                }
                return $tmpFile;
            }

            throw new IOGenerativeException('A temporary file could not be created: %s', self::$lastError ?? '');
        }

        for ($i = 0; $i < 10; ++$i) {
            $tmpFile = $dir . '/' . $prefix . \bin2hex(\random_bytes(8)) . $suffix;
            $handle = self::box('fopen', $tmpFile, 'x+');
            if (!\is_resource($handle)) {
                continue;
            }

            self::box('fclose', $handle);
            return $tmpFile;
        }

        throw new IOGenerativeException('A temporary file could not be created: %s', self::$lastError ?? '');
    }

    /**
     * @param string|resource $content
     */
    public function dumpFile(string $filename, mixed $content): void
    {
        $dir = \dirname($filename);
        if (!\is_dir($dir)) {
            $this->mkdir($dir);
        }

        $tmpFile = $this->tempnam($dir, \basename($filename));

        try {
            if (self::box('file_put_contents', $tmpFile, $content) === false) {
                throw new IOGenerativeException('Failed to write file "%s": %s', $filename, self::$lastError ?? '');
            }

            self::box('chmod', $tmpFile, \file_exists($filename) ? (int) \fileperms($filename) : (0666 & ~\umask()));
            $this->rename($tmpFile, $filename, true);
        } finally {
            if (\file_exists($tmpFile)) {
                self::box('unlink', $tmpFile);
            }
        }
    }

    /**
     * @param string|resource $content
     */
    public function appendToFile(string $filename, mixed $content, bool $lock = false): void
    {
        $dir = \dirname($filename);
        if (!\is_dir($dir)) {
            $this->mkdir($dir);
        }

        if (self::box('file_put_contents', $filename, $content, \FILE_APPEND | ($lock ? \LOCK_EX : 0)) === false) {
            throw new IOGenerativeException('Failed to write file "%s": %s', $filename, self::$lastError ?? '');
        }
    }

    private function hasRemoteHost(string $path): bool
    {
        return \preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://[^/]#', $path) === 1
            && \stripos($path, 'file://') !== 0;
    }

    /**
     * @param string|iterable<mixed, mixed> $files
     * @return iterable<mixed, mixed>
     */
    private function toIterable(string|iterable $files): iterable
    {
        return \is_iterable($files) ? $files : [$files];
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function getSchemeAndHierarchy(string $filename): array
    {
        $components = \explode('://', $filename, 2);
        return \count($components) === 2 ? [$components[0], $components[1]] : [null, $components[0]];
    }

    private static function assertFunctionExists(string $func): void
    {
        if (!\function_exists($func)) {
            throw new IOGenerativeException(
                'Unable to perform filesystem operation because the "%s()" function has been disabled.',
                $func,
            );
        }
    }

    private static function box(string $func, mixed ...$args): mixed
    {
        self::assertFunctionExists($func);

        self::$lastError = null;
        \set_error_handler([self::class, 'handleError']);
        try {
            return $func(...$args);
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * @internal
     */
    public static function handleError(int $type, string $msg): bool
    {
        self::$lastError = $msg;
        return true;
    }

    public static function normalize(string $path): string
    {
        return \str_replace('\\', \DIRECTORY_SEPARATOR, $path);
    }
}
