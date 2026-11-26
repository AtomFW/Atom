<?php

declare(strict_types=1);

namespace Atom\FileSytem;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use FilesystemIterator;
use finfo;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SortDirection;
use SplFileInfo;
use Time\Duration;

/**
 * Manages upload directory structures and temporary upload folders.
 * Defaults temporary metadata storage to runtime/temp/upload-folder-manager.
 */
final class UploadFolderManager
{
    private string $baseDir;

    /** @var list<string> */
    private array $schema;

    private string $prefix;
    private int $tempTtlSeconds;
    private string $tempStorageDir;
    private int $dirPermissions;
    private int $filePermissions;

    private ?string $mainPath = null;

    /** @var list<string> */
    private array $relatedPaths = [];

    private ?string $tempJsonPath = null;

    /** @var array<string, mixed>|null */
    private ?array $meta = null;

    private ?finfo $finfo = null;

    /**
     * @param array<int, string>|string $schema
     * @param array<string, mixed> $options
     */
    public function __construct(string $baseDir, array|string $schema, array $options = [])
    {
        $this->baseDir = $this->normalizePath($baseDir);
        $this->schema = $this->normalizeSchema($schema);
        $this->prefix = (string) ($options['prefix'] ?? '');

        $rawTtl = $options['temp_ttl'] ?? $options['temp_ttl_seconds'] ?? 86400;
        $this->tempTtlSeconds = $rawTtl instanceof Duration
            ? \clamp($rawTtl->seconds, 1, \PHP_INT_MAX)
            : \clamp((int) $rawTtl, 1, \PHP_INT_MAX);

        $defaultTempStorage = \dirname(__DIR__, 2)
            . \DIRECTORY_SEPARATOR . 'runtime'
            . \DIRECTORY_SEPARATOR . 'temp'
            . \DIRECTORY_SEPARATOR . 'upload-folder-manager';

        $this->tempStorageDir = $this->normalizePath((string) ($options['temp_storage_dir'] ?? $defaultTempStorage));
        $this->dirPermissions = (int) ($options['dir_permissions'] ?? 0755);
        $this->filePermissions = (int) ($options['file_permissions'] ?? 0644);

        $this->ensureDirectory($this->baseDir, $this->dirPermissions);
        $this->ensureDirectory($this->tempStorageDir, $this->dirPermissions);

        if (isset($options['related_schemas']) && \is_array($options['related_schemas'])) {
            $related = \array_slice($options['related_schemas'], 0, 2);
            $this->meta['relatedSchemas'] = \array_map(
                fn(array|string $s): array => $this->normalizeSchema($s),
                $related,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function createStructure(bool $temporary = false, ?string $tempId = null): array
    {
        $now = new DateTimeImmutable('now');

        $this->mainPath = $temporary
            ? $this->normalizePath($this->baseDir . \DIRECTORY_SEPARATOR . ($tempId ?: $this->generateId()))
            : $this->normalizePath($this->baseDir . \DIRECTORY_SEPARATOR . $this->buildSchemaPath($this->schema, $now));

        $this->ensureDirectory($this->mainPath, $this->dirPermissions);

        $this->relatedPaths = [];
        foreach ($this->getRelatedSchemas() as $relatedSchema) {
            $schemaParts = \is_array($relatedSchema) ? $relatedSchema : $this->normalizeSchema((string) $relatedSchema);
            $path = $this->normalizePath($this->mainPath . \DIRECTORY_SEPARATOR . $this->buildSchemaPath($schemaParts, $now));
            $this->ensureDirectory($path, $this->dirPermissions);
            $this->relatedPaths[] = $path;
        }

        $relatedSchemas = $this->getRelatedSchemas();

        if ($temporary) {
            $this->tempJsonPath = $this->tempStorageDir . \DIRECTORY_SEPARATOR . $this->generateTempJsonName($this->mainPath) . '.json';

            $this->meta = [
                'id' => $tempId ?: \basename($this->mainPath),
                'baseDir' => $this->baseDir,
                'mainPath' => $this->mainPath,
                'relatedPaths' => $this->relatedPaths,
                'schema' => $this->schema,
                'relatedSchemas' => $relatedSchemas,
                'prefix' => $this->prefix,
                'createdAt' => $now->format(\DATE_ATOM),
                'expiresAt' => $now->add(new DateInterval('PT' . $this->tempTtlSeconds . 'S'))->format(\DATE_ATOM),
                'ttlSeconds' => $this->tempTtlSeconds,
            ];

            $payload = \json_encode(
                $this->meta,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            );
            \file_put_contents($this->tempJsonPath, $payload, \LOCK_EX);
            $this->safeChmod($this->tempJsonPath, $this->filePermissions);
        } else {
            $this->meta = [
                'baseDir' => $this->baseDir,
                'mainPath' => $this->mainPath,
                'relatedPaths' => $this->relatedPaths,
                'schema' => $this->schema,
                'relatedSchemas' => $relatedSchemas,
                'prefix' => $this->prefix,
                'createdAt' => $now->format(\DATE_ATOM),
            ];
        }

        return $this->getStructureInfo();
    }

    /**
     * @return array<string, mixed>
     */
    public function loadFromTempJson(string $jsonPath): array
    {
        $jsonPath = $this->normalizePath($jsonPath);
        if (!\is_file($jsonPath)) {
            throw new RuntimeException("JSON file does not exist: {$jsonPath}");
        }

        $raw = \file_get_contents($jsonPath);
        if ($raw === false) {
            throw new RuntimeException("Unable to read JSON file: {$jsonPath}");
        }

        $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || empty($decoded['mainPath'])) {
            throw new RuntimeException('Invalid JSON content for folder structure.');
        }

        $this->tempJsonPath = $jsonPath;
        $this->meta = $decoded;
        $this->mainPath = $this->normalizePath((string) $decoded['mainPath']);
        $this->relatedPaths = \array_values(\array_map(
            fn(mixed $p): string => $this->normalizePath((string) $p),
            \is_array($decoded['relatedPaths'] ?? null) ? $decoded['relatedPaths'] : [],
        ));

        return $this->getStructureInfo();
    }

    public function getMainPath(): string
    {
        $this->assertCreated();
        return (string) $this->mainPath;
    }

    /**
     * @return list<string>
     */
    public function getRelatedPaths(): array
    {
        $this->assertCreated();
        return $this->relatedPaths;
    }

    /**
     * @return array<string, string>
     */
    public function getAllPaths(): array
    {
        $this->assertCreated();

        $paths = ['main' => (string) $this->mainPath];
        foreach ($this->relatedPaths as $i => $path) {
            $paths['related_' . ($i + 1)] = $path;
        }

        return $paths;
    }

    public function getTempJsonPath(): ?string
    {
        return $this->tempJsonPath;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStructureInfo(): array
    {
        $this->assertCreated();

        return [
            'baseDir' => $this->baseDir,
            'mainPath' => $this->mainPath,
            'relatedPaths' => $this->relatedPaths,
            'tempJsonPath' => $this->tempJsonPath,
            'schema' => $this->schema,
            'relatedSchemas' => $this->getRelatedSchemas(),
            'prefix' => $this->prefix,
            'createdAt' => $this->getCreatedAt()?->format(\DATE_ATOM),
            'modifiedAt' => $this->getModifiedAt()?->format(\DATE_ATOM),
            'lastAddedAt' => $this->getLastAddedAt()?->format(\DATE_ATOM),
            'fileCount' => $this->countFiles(),
            'expired' => $this->isExpired(),
        ];
    }

    public function isExpired(): bool
    {
        if ($this->tempJsonPath === null || !\is_file($this->tempJsonPath)) {
            return false;
        }

        $meta = $this->meta ?? \json_decode((string) \file_get_contents($this->tempJsonPath), true);
        if (!\is_array($meta) || empty($meta['expiresAt'])) {
            return false;
        }

        return new DateTimeImmutable((string) $meta['expiresAt']) <= new DateTimeImmutable('now');
    }

    public function cleanupExpiredTemp(): bool
    {
        if (!$this->isExpired() || $this->mainPath === null) {
            return false;
        }

        $this->deleteDirectoryRecursively($this->mainPath);

        if ($this->tempJsonPath !== null && \is_file($this->tempJsonPath)) {
            $this->safeUnlink($this->tempJsonPath);
        }

        return true;
    }

    public function folderExists(?string $folderKey = 'main'): bool
    {
        $path = $this->resolveFolderPath($folderKey);
        return $path !== null && \is_dir($path);
    }

    public function fileExists(string $fileName, ?string $folderKey = 'main'): bool
    {
        return \is_file($this->getAbsoluteFilePath($fileName, $folderKey));
    }

    public function getAbsoluteFilePath(string $fileName, ?string $folderKey = 'main'): string
    {
        $folderPath = $this->resolveFolderPath($folderKey);
        if ($folderPath === null) {
            throw new RuntimeException('Unknown destination folder.');
        }

        return $this->normalizePath($folderPath . \DIRECTORY_SEPARATOR . \ltrim($fileName, '/\\'));
    }

    /**
     * @return list<string>
     */
    public function getAllFilesPaths(bool $recursive = true, ?string $folderKey = null): array
    {
        $paths = [];
        foreach ($this->resolveSelectedFolders($folderKey) as $folder) {
            foreach ($this->collectFiles($folder, $recursive) as $file) {
                $paths[] = (string) $file['path'];
            }
        }

        return $paths;
    }

    public function countFiles(bool $recursive = true, ?string $folderKey = null): int
    {
        return \count($this->getAllFilesPaths($recursive, $folderKey));
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function filesByExtension(bool $recursive = true, ?string $folderKey = null): array
    {
        $map = [];
        foreach ($this->resolveSelectedFolders($folderKey) as $folder) {
            foreach ($this->collectFiles($folder, $recursive) as $file) {
                $ext = $file['extension'] !== '' ? (string) $file['extension'] : '[no-extension]';
                $map[$ext][] = $file;
            }
        }

        \ksort($map);
        return $map;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function filesByMime(bool $recursive = true, ?string $folderKey = null): array
    {
        $map = [];
        foreach ($this->resolveSelectedFolders($folderKey) as $folder) {
            foreach ($this->collectFiles($folder, $recursive) as $file) {
                $mime = !empty($file['mime']) ? (string) $file['mime'] : 'application/octet-stream';
                $map[$mime][] = $file;
            }
        }

        \ksort($map);
        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFiles(string $sortBy = 'added_at', bool $recursive = true, ?string $folderKey = null): array
    {
        $files = [];
        foreach ($this->resolveSelectedFolders($folderKey) as $folder) {
            foreach ($this->collectFiles($folder, $recursive) as $entry) {
                $files[] = $entry;
            }
        }

        \usort($files, static function (array $a, array $b) use ($sortBy): int {
            return match ($sortBy) {
                'alphabetical' => \strcmp((string) $a['name'], (string) $b['name']),
                'reverse_alphabetical' => \strcmp((string) $b['name'], (string) $a['name']),
                default => ($a['modifiedTimestamp'] <=> $b['modifiedTimestamp']) ?: \strcmp((string) $a['name'], (string) $b['name']),
            };
        });

        return $files;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFilesByAddedDate(
        bool|SortDirection $desc = true,
        bool $recursive = true,
        ?string $folderKey = null,
    ): array {
        $isDesc = $desc instanceof SortDirection ? $desc === SortDirection::Descending : $desc;
        $files = $this->listFiles('added_at', $recursive, $folderKey);

        return $isDesc ? \array_reverse($files) : $files;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFilesAlphabetically(
        bool|SortDirection $desc = false,
        bool $recursive = true,
        ?string $folderKey = null,
    ): array {
        $isDesc = $desc instanceof SortDirection ? $desc === SortDirection::Descending : $desc;
        return $this->listFiles($isDesc ? 'reverse_alphabetical' : 'alphabetical', $recursive, $folderKey);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFilesReverseAlphabetically(bool $recursive = true, ?string $folderKey = null): array
    {
        return $this->listFiles('reverse_alphabetical', $recursive, $folderKey);
    }

    public function getCreatedAt(?string $folderKey = 'main'): ?DateTimeImmutable
    {
        $path = $this->resolveFolderPath($folderKey);
        if ($path === null || !\is_dir($path)) {
            return null;
        }

        if ($this->meta !== null && isset($this->meta['createdAt']) && $folderKey === 'main') {
            return new DateTimeImmutable((string) $this->meta['createdAt']);
        }

        $ctime = \filectime($path);
        return (new DateTimeImmutable())->setTimestamp($ctime !== false ? $ctime : \time());
    }

    public function getModifiedAt(?string $folderKey = 'main'): ?DateTimeImmutable
    {
        $path = $this->resolveFolderPath($folderKey);
        if ($path === null || !\is_dir($path)) {
            return null;
        }

        $mtime = \filemtime($path);
        return (new DateTimeImmutable())->setTimestamp($mtime !== false ? $mtime : \time());
    }

    public function getLastAddedAt(?string $folderKey = null, bool $recursive = true): ?DateTimeImmutable
    {
        $files = $this->listFiles('added_at', $recursive, $folderKey);
        if ($files === []) {
            return null;
        }

        $last = \array_last($files);
        return (new DateTimeImmutable())->setTimestamp((int) $last['modifiedTimestamp']);
    }

    public function getFolderPermissions(?string $folderKey = 'main'): ?string
    {
        $path = $this->resolveFolderPath($folderKey);
        return ($path !== null && \file_exists($path)) ? \sprintf('%04o', (int) \fileperms($path) & 0777) : null;
    }

    public function getFilePermissions(string $fileName, ?string $folderKey = 'main'): ?string
    {
        $path = $this->getAbsoluteFilePath($fileName, $folderKey);
        return \is_file($path) ? \sprintf('%04o', (int) \fileperms($path) & 0777) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFileInfo(string $fileName, ?string $folderKey = 'main'): array
    {
        $path = $this->getAbsoluteFilePath($fileName, $folderKey);
        if (!\is_file($path)) {
            throw new RuntimeException("The file does not exist: {$path}");
        }

        return $this->buildFileRecord(new SplFileInfo($path));
    }

    /**
     * @return array<string, mixed>
     */
    public function getFolderInfo(?string $folderKey = 'main'): array
    {
        $folder = $this->resolveFolderPath($folderKey);
        if ($folder === null || !\is_dir($folder)) {
            throw new RuntimeException('The folder does not exist.');
        }

        return [
            'path' => $folder,
            'exists' => true,
            'permissions' => $this->getFolderPermissions($folderKey),
            'createdAt' => $this->getCreatedAt($folderKey)?->format(\DATE_ATOM),
            'modifiedAt' => $this->getModifiedAt($folderKey)?->format(\DATE_ATOM),
            'lastAddedAt' => $this->getLastAddedAt($folderKey)?->format(\DATE_ATOM),
            'fileCount' => $this->countFiles(true, $folderKey),
            'subfolderCount' => $this->countSubfolders($folder),
            'totalSize' => $this->calculateFolderSize($folder),
        ];
    }

    public function hasRelatedFolder(int $index): bool
    {
        return isset($this->relatedPaths[$index]);
    }

    public function getRelatedFolderPath(int $index): ?string
    {
        return $this->relatedPaths[$index] ?? null;
    }

    public function touchFolder(?string $folderKey = 'main'): bool
    {
        $folder = $this->resolveFolderPath($folderKey);
        if ($folder === null || !\is_dir($folder)) {
            return false;
        }

        \set_error_handler(static fn(): bool => true);
        try {
            return \touch($folder);
        } finally {
            \restore_error_handler();
        }
    }

    public function refreshTempMetadata(): bool
    {
        if ($this->tempJsonPath === null || $this->meta === null) {
            return false;
        }

        $this->meta['lastRefreshedAt'] = (new DateTimeImmutable('now'))->format(\DATE_ATOM);
        $encoded = \json_encode(
            $this->meta,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );

        return \file_put_contents($this->tempJsonPath, $encoded, \LOCK_EX) !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTempMetadata(): ?array
    {
        if ($this->meta !== null) {
            return $this->meta;
        }

        if ($this->tempJsonPath !== null && \is_file($this->tempJsonPath)) {
            $decoded = \json_decode((string) \file_get_contents($this->tempJsonPath), true);
            return \is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * @return array<int, array<int, string>|string>
     */
    public function getRelatedSchemas(): array
    {
        $schemas = $this->meta['relatedSchemas'] ?? [];
        return \is_array($schemas) ? $schemas : [];
    }

    public function saveFile(string $relativeFileName, string $content, ?string $folderKey = 'main'): string
    {
        $path = $this->getAbsoluteFilePath($relativeFileName, $folderKey);
        $dir = \dirname($path);

        $this->ensureDirectory($dir, $this->dirPermissions);
        if (\file_put_contents($path, $content, \LOCK_EX) === false) {
            throw new RuntimeException("Failed to write file: {$path}");
        }
        $this->safeChmod($path, $this->filePermissions);

        return $path;
    }

    /**
     * @return list<string>
     */
    private function resolveSelectedFolders(?string $folderKey): array
    {
        $this->assertCreated();

        if ($folderKey === 'main') {
            return [(string) $this->mainPath];
        }

        if ($folderKey === null) {
            return [(string) $this->mainPath, ...$this->relatedPaths];
        }

        if (\preg_match('/^related_(\d+)$/', $folderKey, $m) === 1) {
            $idx = \max(0, (int) $m[1] - 1);
            return isset($this->relatedPaths[$idx]) ? [$this->relatedPaths[$idx]] : [];
        }

        if ($folderKey === 'related') {
            return $this->relatedPaths;
        }

        throw new InvalidArgumentException('Unknown folder key.');
    }

    private function resolveFolderPath(?string $folderKey): ?string
    {
        $this->assertCreated();

        return match ($folderKey) {
            'main', null => $this->mainPath,
            'related', 'related_1' => $this->relatedPaths[0] ?? null,
            'related_2' => $this->relatedPaths[1] ?? null,
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectFiles(string $folder, bool $recursive): array
    {
        if (!\is_dir($folder)) {
            return [];
        }

        $iterator = $recursive
            ? new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            )
            : new FilesystemIterator($folder, FilesystemIterator::SKIP_DOTS);

        $result = [];
        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isFile()) {
                $result[] = $this->buildFileRecord($item);
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFileRecord(SplFileInfo $file): array
    {
        $path = $this->normalizePath($file->getPathname());
        $ctime = (int) $file->getCTime();
        $mtime = (int) $file->getMTime();

        return [
            'name' => $file->getFilename(),
            'basename' => $file->getBasename(),
            'path' => $path,
            'directory' => $this->normalizePath($file->getPath()),
            'extension' => \strtolower($file->getExtension()),
            'size' => $file->getSize(),
            'permissions' => \sprintf('%04o', $file->getPerms() & 0777),
            'createdAt' => (new DateTimeImmutable())->setTimestamp($ctime)->format(\DATE_ATOM),
            'modifiedAt' => (new DateTimeImmutable())->setTimestamp($mtime)->format(\DATE_ATOM),
            'createdTimestamp' => $ctime,
            'modifiedTimestamp' => $mtime,
            'mime' => $this->detectMimeType($path),
        ];
    }

    private function detectMimeType(string $path): string
    {
        if (!\is_file($path)) {
            return 'application/octet-stream';
        }

        $this->finfo ??= new finfo(\FILEINFO_MIME_TYPE);
        $mime = $this->finfo->file($path);

        return \is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    /**
     * @param array<int, string>|string $schema
     * @return list<string>
     */
    private function normalizeSchema(array|string $schema): array
    {
        if (\is_string($schema)) {
            $schema = \array_values(\array_filter(
                \array_map(\trim(...), \explode('/', \trim($schema, '/'))),
                static fn(string $v): bool => $v !== '',
            ));
        }

        if ($schema === []) {
            throw new InvalidArgumentException('Folder schema cannot be empty.');
        }

        return \array_values(\array_map(
            static fn(string $part): string => \trim($part, \DIRECTORY_SEPARATOR . ' '),
            $schema,
        ));
    }

    /**
     * @param array<int, string> $schema
     */
    private function buildSchemaPath(array $schema, DateTimeInterface $date): string
    {
        $parts = [];
        foreach ($schema as $part) {
            $replaced = $this->replaceTokens($part, $date);
            if ($replaced !== '') {
                $parts[] = $replaced;
            }
        }

        return \implode(\DIRECTORY_SEPARATOR, $parts);
    }

    private function replaceTokens(string $part, DateTimeInterface $date): string
    {
        $map = [
            '{Y}' => $date->format('Y'),
            '{y}' => $date->format('y'),
            '{m}' => $date->format('m'),
            '{d}' => $date->format('d'),
            '{H}' => $date->format('H'),
            '{i}' => $date->format('i'),
            '{s}' => $date->format('s'),
            '{prefix}' => $this->prefix,
        ];

        $part = \strtr($part, $map);
        if ($part === 'prefix') {
            $part = $this->prefix;
        }

        return $this->sanitizePathSegment($part);
    }

    private function sanitizePathSegment(string $segment): string
    {
        $segment = \trim($segment);
        $segment = \str_replace(["\0", '/', '\\'], '-', $segment);
        $segment = \preg_replace('/[<>:"|?*]+/u', '-', $segment) ?? $segment;

        return \trim($segment, ' .-');
    }

    private function normalizePath(string $path): string
    {
        $isUnc = \str_starts_with($path, '\\\\');
        $normalized = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $path);
        $parts = [];

        foreach (\explode(\DIRECTORY_SEPARATOR, $normalized) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                \array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        if ($isUnc) {
            return '\\\\' . \implode(\DIRECTORY_SEPARATOR, $parts);
        }

        $prefix = \str_starts_with($normalized, \DIRECTORY_SEPARATOR) ? \DIRECTORY_SEPARATOR : '';
        return $prefix . \implode(\DIRECTORY_SEPARATOR, $parts);
    }

    private function ensureDirectory(string $path, int $permissions): void
    {
        if (!\is_dir($path) && !\mkdir($path, $permissions, true) && !\is_dir($path)) {
            throw new RuntimeException("Failed to create directory: {$path}");
        }

        $this->safeChmod($path, $permissions);
    }

    private function deleteDirectoryRecursively(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $this->safeRmdir($item->getPathname());
            } else {
                $this->safeUnlink($item->getPathname());
            }
        }

        $this->safeRmdir($dir);
    }

    private function safeChmod(string $path, int $permissions): void
    {
        \set_error_handler(static fn(): bool => true);
        try {
            \chmod($path, $permissions);
        } finally {
            \restore_error_handler();
        }
    }

    private function safeUnlink(string $path): void
    {
        if (!\is_file($path)) {
            return;
        }

        \set_error_handler(static fn(): bool => true);
        try {
            \unlink($path);
        } finally {
            \restore_error_handler();
        }
    }

    private function safeRmdir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        \set_error_handler(static fn(): bool => true);
        try {
            \rmdir($dir);
        } finally {
            \restore_error_handler();
        }
    }

    private function calculateFolderSize(string $dir): int
    {
        if (!\is_dir($dir)) {
            return 0;
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isFile()) {
                $size += $item->getSize();
            }
        }

        return $size;
    }

    private function countSubfolders(string $dir): int
    {
        if (!\is_dir($dir)) {
            return 0;
        }

        $count = 0;
        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir()) {
                $count++;
            }
        }

        return $count;
    }

    private function generateId(): string
    {
        return \bin2hex(\random_bytes(8));
    }

    private function generateTempJsonName(string $mainPath): string
    {
        return 'upload-folder-' . \substr(\hash('xxh128', $mainPath . '|' . \hrtime(true)), 0, 24);
    }

    private function assertCreated(): void
    {
        if ($this->mainPath === null) {
            throw new RuntimeException('First, call createStructure().');
        }
    }
}
