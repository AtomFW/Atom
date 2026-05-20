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
use SplFileInfo;

/**
 * Manages upload folders in the filesystem
 * Provides methods for creating, validating, and managing upload directories
 */
final class UploadFolderManager
{
    private string $baseDir;
    /** @var array<int, string> */
    private array $schema;
    private string $prefix;
    private int $tempTtlSeconds;
    private string $tempStorageDir;
    private int $dirPermissions;
    private int $filePermissions;

    private ?string $mainPath = null;
    /** @var array<int, string> */
    private array $relatedPaths = [];
    private ?string $tempJsonPath = null;
    private ?array $meta = null;

    /**
     * @param string $baseDir Base directory, e.g. /var/www/storage/uploads
     * @param array<int, string>|string $schema Directory schema, e.g. ['Y', 'm', 'd'] or 'Y/m/d/H' or ['prefix', 'Y', 'm', 'd']
     * @param array{
     *     prefix?: string,
     *     temp_ttl_seconds?: int,
     *     temp_storage_dir?: string,
     *     dir_permissions?: int,
     *     file_permissions?: int,
     *     related_schemas?: array<int, array<int, string>|string>
     * } $options
     */
    public function __construct(string $baseDir, array|string $schema, array $options = [])
    {
        $this->baseDir = $this->normalizePath($baseDir);
        $this->schema = $this->normalizeSchema($schema);
        $this->prefix = (string)($options['prefix'] ?? '');
        $this->tempTtlSeconds = max(1, (int)($options['temp_ttl_seconds'] ?? 86400));
        $this->tempStorageDir = $this->normalizePath(
            $options['temp_storage_dir'] ?? (rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'upload-folder-manager')
        );
        $this->dirPermissions = (int)($options['dir_permissions'] ?? 0755);
        $this->filePermissions = (int)($options['file_permissions'] ?? 0644);

        $this->ensureDirectory($this->baseDir, $this->dirPermissions);
        $this->ensureDirectory($this->tempStorageDir, $this->dirPermissions);

        // Up to 2 subfolder schemas. You can also specify them later in createStructure(),
        // but here we keep the model simple: 0..2 additional side folders.
        if (isset($options['related_schemas']) && is_array($options['related_schemas'])) {
            $related = array_slice($options['related_schemas'], 0, 2);
            $this->meta['relatedSchemas'] = array_map(fn ($s) => $this->normalizeSchema($s), $related);
        }
    }

    /**
     * Creates a directory structure and saves JSON metadata.
     * If $temporary=true, the base directory will have a unique ID,
     * and the metadata will go to a JSON file in temp_storage_dir.
     *
     * @param bool $temporary Whether to create a temporary directory with unique ID
     * @param string|null $tempId Unique identifier for temporary directories
     * @return array Information about the created structure
     */
    public function createStructure(bool $temporary = false, ?string $tempId = null): array
    {
        $now = new DateTimeImmutable('now');

        $this->mainPath = $temporary
            ? $this->normalizePath($this->baseDir . DIRECTORY_SEPARATOR . ($tempId ?: $this->generateId()))
            : $this->normalizePath($this->baseDir . DIRECTORY_SEPARATOR . $this->buildSchemaPath($this->schema, $now));

        $this->ensureDirectory($this->mainPath, $this->dirPermissions);

        $this->relatedPaths = [];
        foreach ($this->getRelatedSchemas() as $relatedSchema) {
            $path = $this->normalizePath($this->mainPath . DIRECTORY_SEPARATOR . $this->buildSchemaPath($relatedSchema, $now));
            $this->ensureDirectory($path, $this->dirPermissions);
            $this->relatedPaths[] = $path;
        }

        if ($temporary) {
            $this->tempJsonPath = $this->tempStorageDir . DIRECTORY_SEPARATOR . $this->generateTempJsonName($this->mainPath) . '.json';

            $this->meta = [
                'id' => $tempId ?: basename($this->mainPath),
                'baseDir' => $this->baseDir,
                'mainPath' => $this->mainPath,
                'relatedPaths' => $this->relatedPaths,
                'schema' => $this->schema,
                'relatedSchemas' => $this->getRelatedSchemas(),
                'prefix' => $this->prefix,
                'createdAt' => $now->format(DATE_ATOM),
                'expiresAt' => $now->add(new DateInterval('PT' . $this->tempTtlSeconds . 'S'))->format(DATE_ATOM),
                'ttlSeconds' => $this->tempTtlSeconds,
            ];

            file_put_contents(
                $this->tempJsonPath,
                json_encode($this->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            @chmod($this->tempJsonPath, $this->filePermissions);
        } else {
            $this->meta = [
                'baseDir' => $this->baseDir,
                'mainPath' => $this->mainPath,
                'relatedPaths' => $this->relatedPaths,
                'schema' => $this->schema,
                'relatedSchemas' => $this->getRelatedSchemas(),
                'prefix' => $this->prefix,
                'createdAt' => $now->format(DATE_ATOM),
            ];
        }

        return $this->getStructureInfo();
    }

    /**
     * Loads the structure from the JSON file generated for the temporary folder.
     *
     * @param string $jsonPath Path to the JSON metadata file
     * @return array Information about the loaded structure
     */
    public function loadFromTempJson(string $jsonPath): array
    {
        $jsonPath = $this->normalizePath($jsonPath);

        if (!is_file($jsonPath)) {
            throw new RuntimeException("JSON file does not exist: {$jsonPath}");
        }

        $decoded = json_decode((string)file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || empty($decoded['mainPath'])) {
            throw new RuntimeException('Invalid JSON content for folder structure.');
        }

        $this->tempJsonPath = $jsonPath;
        $this->meta = $decoded;
        $this->mainPath = $this->normalizePath((string)$decoded['mainPath']);
        $this->relatedPaths = array_values(array_map(
            fn ($p) => $this->normalizePath((string)$p),
            $decoded['relatedPaths'] ?? []
        ));

        return $this->getStructureInfo();
    }

    /**
     * Gets the path to the main directory.
     *
     * @return string Main directory path
     */
    public function getMainPath(): string
    {
        $this->assertCreated();
        return $this->mainPath;
    }

    /**
     * Gets paths of all related directories.
     *
     * @return array<int, string> Array of directory paths
     */
    public function getRelatedPaths(): array
    {
        $this->assertCreated();
        return $this->relatedPaths;
    }

    /**
     * Gets paths of all directories in the structure.
     *
     * @return array<string, string> Associative array with directory identifiers and their paths
     */
    public function getAllPaths(): array
    {
        $this->assertCreated();

        $paths = ['main' => $this->mainPath];
        foreach ($this->relatedPaths as $i => $path) {
            $paths['related_' . ($i + 1)] = $path;
        }

        return $paths;
    }

    /**
     * Gets path to the temporary JSON metadata file.
     *
     * @return string|null Path to JSON file or null if not applicable
     */
    public function getTempJsonPath(): ?string
    {
        return $this->tempJsonPath;
    }

    /**
     * Gets comprehensive information about the current structure.
     *
     * @return array Structure information
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
            'createdAt' => $this->getCreatedAt()?->format(DATE_ATOM),
            'modifiedAt' => $this->getModifiedAt()?->format(DATE_ATOM),
            'lastAddedAt' => $this->getLastAddedAt()?->format(DATE_ATOM),
            'fileCount' => $this->countFiles(),
            'expired' => $this->isExpired(),
        ];
    }

    /**
     * Checks if the temporary directory has expired.
     *
     * @return bool True if expired, false otherwise
     */
    public function isExpired(): bool
    {
        if ($this->tempJsonPath === null || !is_file($this->tempJsonPath)) {
            return false;
        }

        $meta = $this->meta ?? json_decode((string)file_get_contents($this->tempJsonPath), true);
        if (!is_array($meta) || empty($meta['expiresAt'])) {
            return false;
        }

        return new DateTimeImmutable((string)$meta['expiresAt']) <= new DateTimeImmutable('now');
    }

    /**
     * Cleans up expired temporary directories and metadata files.
     *
     * @return bool True if cleanup was performed, false otherwise
     */
    public function cleanupExpiredTemp(): bool
    {
        if (!$this->isExpired() || $this->mainPath === null) {
            return false;
        }

        $this->deleteDirectoryRecursively($this->mainPath);

        if ($this->tempJsonPath !== null && is_file($this->tempJsonPath)) {
            @unlink($this->tempJsonPath);
        }

        return true;
    }

    /**
     * Checks if a directory exists in the structure.
     *
     * @param string|null $folderKey Identifier for the folder to check
     * @return bool True if directory exists, false otherwise
     */
    public function folderExists(?string $folderKey = 'main'): bool
    {
        $path = $this->resolveFolderPath($folderKey);
        return $path !== null && is_dir($path);
    }

    /**
     * Checks if a file exists in the structure.
     *
     * @param string $fileName Name of the file to check
     * @param string|null $folderKey Identifier for the folder to search in
     * @return bool True if file exists, false otherwise
     */
    public function fileExists(string $fileName, ?string $folderKey = 'main'): bool
    {
        $path = $this->getAbsoluteFilePath($fileName, $folderKey);
        return is_file($path);
    }

    /**
     * Gets absolute path for a file.
     *
     * @param string $fileName Name of the file
     * @param string|null $folderKey Identifier for the folder to search in
     * @return string Absolute path to the file
     */
    public function getAbsoluteFilePath(string $fileName, ?string $folderKey = 'main'): string
    {
        $folderPath = $this->resolveFolderPath($folderKey);
        if ($folderPath === null) {
            throw new RuntimeException('Unknown destination folder.');
        }

        return $this->normalizePath($folderPath . DIRECTORY_SEPARATOR . ltrim($fileName, DIRECTORY_SEPARATOR));
    }

    /**
     * Gets list of all files in specified folders.
     *
     * @param bool $recursive Whether to include subdirectories
     * @param string|null $folderKey Identifier for the folder to search in
     * @return array<int, string> Array of file paths
     */
    public function getAllFilesPaths(bool $recursive = true, ?string $folderKey = null): array
    {
        $paths = [];
        foreach ($this->resolveSelectedFolders($folderKey) as $folder) {
            foreach ($this->collectFiles($folder, $recursive) as $file) {
                $paths[] = $file['path'];
            }
        }

        return $paths;
    }

    /**
     * Counts files in specified folders.
     *
     * @param bool $recursive Whether to include subdirectories
     * @param string|null $folderKey Identifier for the folder to search in
     * @return int Number of files found
     */
    public function countFiles(bool $recursive = true, ?string $folderKey = null): int
    {
        return count($this->getAllFilesPaths($recursive, $folderKey));
    }

    /**
     * Groups files by file extension.
     *
     * @param bool $recursive Whether to include subdirectories
     * @param string|null $folderKey Identifier for the folder to search in
     * @return array<string, array<int, array<string, mixed>>> Files grouped by file extension
     */
    public function filesByExtension(bool $recursive = true, ?string $folderKey = null): array
    {
        $map = [];

        foreach ($this->resolveSelectedFolders($folderKey) as $folder) {
            foreach ($this->collectFiles($folder, $recursive) as $file) {
                $ext = $file['extension'] !== '' ? $file['extension'] : '[no-extension]';
                $map[$ext][] = $file;
            }
        }

        ksort($map);
        return $map;
    }

    /**
     * Groups files by MIME type.
     *
     * @param bool $recursive Whether to include subdirectories
     * @param string|null $folderKey Identifier for the folder to search in
     * @return array<string, array<int, array<string, mixed>>> Files grouped by MIME type
     */
    public function filesByMime(bool $recursive = true, ?string $folderKey = null): array
    {
        $map = [];

        foreach ($this->resolveSelectedFolders($folderKey) as $folder) {
            foreach ($this->collectFiles($folder, $recursive) as $file) {
                $mime = $file['mime'] ?: 'application/octet-stream';
                $map[$mime][] = $file;
            }
        }

        ksort($map);
        return $map;
    }

    /**
     * Lists files in a directory sorted by specified criteria.
     *
     * @param string $sortBy Sorting criteria: added_at|alphabetical|reverse_alphabetical
     * @param bool $recursive Whether to include subdirectories
     * @param string|null $folderKey Identifier for the folder to search in
     * @return array<int, array<string, mixed>> Array of file information
     */
    public function listFiles(string $sortBy = 'added_at', bool $recursive = true, ?string $folderKey = null): array
    {
        $files = [];
        foreach ($this->resolveSelectedFolders($folderKey) as $folder) {
            $files = \array_merge($files, $this->collectFiles($folder, $recursive));
        }

        usort($files, function (array $a, array $b) use ($sortBy): int {
            return match ($sortBy) {
                'alphabetical' => strcmp($a['name'], $b['name']),
                'reverse_alphabetical' => strcmp($b['name'], $a['name']),
                default => ($a['modifiedTimestamp'] <=> $b['modifiedTimestamp']) ?: strcmp($a['name'], $b['name']),
            };
        });

        return $files;
    }

    /**
     * Lists files sorted by added date.
     *
     * @param bool $desc Whether to sort in descending order
     * @param bool $recursive Whether to include subdirectories
     * @param string|null $folderKey Identifier for the folder to search in
     * @return array<int, array<string, mixed>> Array of file information
     */
    public function listFilesByAddedDate(bool $desc = true, bool $recursive = true, ?string $folderKey = null): array
    {
        $files = $this->listFiles('added_at', $recursive, $folderKey);
        if ($desc) {
            $files = array_reverse($files);
        }

        return $files;
    }

    /**
     * Lists files sorted alphabetically.
     *
     * @param bool $desc Whether to sort in descending order
     * @param bool $recursive Whether to include subdirectories
     * @param string|null $folderKey Identifier for the folder to search in
     * @return array<int, array<string, mixed>> Array of file information
     */
    public function listFilesAlphabetically(bool $desc = false, bool $recursive = true, ?string $folderKey = null): array
    {
        return $this->listFiles($desc ? 'reverse_alphabetical' : 'alphabetical', $recursive, $folderKey);
    }

    /**
     * Lists files sorted in reverse alphabetical order.
     *
     * @param bool $recursive Whether to include subdirectories
     * @param string|null $folderKey Identifier for the folder to search in
     * @return array<int, array<string, mixed>> Array of file information
     */
    public function listFilesReverseAlphabetically(bool $recursive = true, ?string $folderKey = null): array
    {
        return $this->listFiles('reverse_alphabetical', $recursive, $folderKey);
    }

    /**
     * Gets creation date for a directory.
     *
     * @param string|null $folderKey Identifier for the folder to check
     * @return DateTimeImmutable|null Creation timestamp or null if not found
     */
    public function getCreatedAt(?string $folderKey = 'main'): ?DateTimeImmutable
    {
        $path = $this->resolveFolderPath($folderKey);
        if ($path === null || !is_dir($path)) {
            return null;
        }

        if ($this->meta !== null && isset($this->meta['createdAt']) && $folderKey === 'main') {
            return new DateTimeImmutable((string)$this->meta['createdAt']);
        }

        return (new DateTimeImmutable())->setTimestamp((int)@filectime($path));
    }

    /**
     * Gets last modified date for a directory.
     *
     * @param string|null $folderKey Identifier for the folder to check
     * @return DateTimeImmutable|null Modification timestamp or null if not found
     */
    public function getModifiedAt(?string $folderKey = 'main'): ?DateTimeImmutable
    {
        $path = $this->resolveFolderPath($folderKey);
        if ($path === null || !is_dir($path)) {
            return null;
        }

        return (new DateTimeImmutable())->setTimestamp((int)@filemtime($path));
    }

    /**
     * Gets date of last file addition to a directory.
     *
     * @param string|null $folderKey Identifier for the folder to check
     * @param bool $recursive Whether to include subdirectories
     * @return DateTimeImmutable|null Last added timestamp or null if not found
     */
    public function getLastAddedAt(?string $folderKey = null, bool $recursive = true): ?DateTimeImmutable
    {
        $files = $this->listFiles('added_at', $recursive, $folderKey);
        if ($files === []) {
            return null;
        }

        $last = $files[array_key_last($files)];
        return (new DateTimeImmutable())->setTimestamp((int)$last['modifiedTimestamp']);
    }

    /**
     * Gets permissions for directory.
     *
     * @param string|null $folderKey Identifier for the folder to check
     * @return string|null String representation of permissions or null if not found
     */
    public function getFolderPermissions(?string $folderKey = 'main'): ?string
    {
        $path = $this->resolveFolderPath($folderKey);
        return ($path !== null && file_exists($path)) ? \sprintf('%04o', fileperms($path) & 0777) : null;
    }

    /**
     * Gets permissions for a file.
     *
     * @param string $fileName Name of the file
     * @param string|null $folderKey Identifier for the folder to check in
     * @return string|null String representation of permissions or null if not found
     */
    public function getFilePermissions(string $fileName, ?string $folderKey = 'main'): ?string
    {
        $path = $this->getAbsoluteFilePath($fileName, $folderKey);
        return is_file($path) ? \sprintf('%04o', fileperms($path) & 0777) : null;
    }

    /**
     * Gets information about a specific file.
     *
     * @param string $fileName Name of the file
     * @param string|null $folderKey Identifier for the folder to check in
     * @return array<string, mixed> File information
     */
    public function getFileInfo(string $fileName, ?string $folderKey = 'main'): array
    {
        $path = $this->getAbsoluteFilePath($fileName, $folderKey);
        if (!is_file($path)) {
            throw new RuntimeException("The file does not exist: {$path}");
        }

        return $this->buildFileRecord(new SplFileInfo($path));
    }

    /**
     * Gets information about a specific directory.
     *
     * @param string|null $folderKey Identifier for the folder to check
     * @return array<string, mixed> Directory information
     */
    public function getFolderInfo(?string $folderKey = 'main'): array
    {
        $folder = $this->resolveFolderPath($folderKey);
        if ($folder === null || !is_dir($folder)) {
            throw new RuntimeException('The folder does not exist.');
        }

        return [
            'path' => $folder,
            'exists' => true,
            'permissions' => $this->getFolderPermissions($folderKey),
            'createdAt' => $this->getCreatedAt($folderKey)?->format(DATE_ATOM),
            'modifiedAt' => $this->getModifiedAt($folderKey)?->format(DATE_ATOM),
            'lastAddedAt' => $this->getLastAddedAt($folderKey)?->format(DATE_ATOM),
            'fileCount' => $this->countFiles(true, $folderKey),
            'subfolderCount' => $this->countSubfolders($folder),
            'totalSize' => $this->calculateFolderSize($folder),
        ];
    }

    /**
     * Checks if a related directory exists.
     *
     * @param int $index Index of the related directory to check
     * @return bool True if directory exists, false otherwise
     */
    public function hasRelatedFolder(int $index): bool
    {
        return isset($this->relatedPaths[$index]);
    }

    /**
     * Gets path of a specific related directory.
     *
     * @param int $index Index of the related directory to retrieve
     * @return string|null Path to the directory or null if it doesn't exist
     */
    public function getRelatedFolderPath(int $index): ?string
    {
        return $this->relatedPaths[$index] ?? null;
    }

    /**
     * Updates timestamp for a directory.
     *
     * @param string|null $folderKey Identifier for the folder to touch
     * @return bool True if successful, false otherwise
     */
    public function touchFolder(?string $folderKey = 'main'): bool
    {
        $folder = $this->resolveFolderPath($folderKey);
        return $folder !== null && is_dir($folder) ? @touch($folder) : false;
    }

    /**
     * Refreshes metadata in temporary JSON file.
     *
     * @return bool True if successful, false otherwise
     */
    public function refreshTempMetadata(): bool
    {
        if ($this->tempJsonPath === null || $this->meta === null) {
            return false;
        }

        $this->meta['lastRefreshedAt'] = (new DateTimeImmutable('now'))->format(DATE_ATOM);
        return file_put_contents(
            $this->tempJsonPath,
            json_encode($this->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    /**
     * Gets temporary metadata from JSON file.
     *
     * @return array|null Array of metadata or null if not found
     */
    public function getTempMetadata(): ?array
    {
        if ($this->meta !== null) {
            return $this->meta;
        }

        if ($this->tempJsonPath !== null && is_file($this->tempJsonPath)) {
            $decoded = json_decode((string)file_get_contents($this->tempJsonPath), true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * Gets the directory schema.
     *
     * @return array<int, string> Directory schema components
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * Gets all related schemas.
     *
     * @return array<int, array<int, string>|string> Related schemas
     */
    public function getRelatedSchemas(): array
    {
        return $this->meta['relatedSchemas'] ?? [];
    }

    /**
     * Saves a file to the structure.
     *
     * @param string $relativeFileName Name of the file to save
     * @param string $content Content to write to the file
     * @param string|null $folderKey Identifier for the folder to save in
     * @return string Path to the saved file
     */
    public function saveFile(string $relativeFileName, string $content, ?string $folderKey = 'main'): string
    {
        $path = $this->getAbsoluteFilePath($relativeFileName, $folderKey);
        $dir = dirname($path);

        $this->ensureDirectory($dir, $this->dirPermissions);
        file_put_contents($path, $content);
        @chmod($path, $this->filePermissions);

        return $path;
    }

    /**
     * Resolves folder paths based on key.
     *
     * @param string|null $folderKey Identifier for the folder to resolve
     * @return array<int, string> Array of resolved folder paths
     */
    private function resolveSelectedFolders(?string $folderKey): array
    {
        $this->assertCreated();

        if ($folderKey === 'main') {
            return [$this->mainPath];
        }

        if ($folderKey === null) {
            return array_merge([$this->mainPath], $this->relatedPaths);
        }

        if (preg_match('/^related_(\d+)$/', $folderKey, $m)) {
            $idx = max(0, (int)$m[1] - 1);
            return isset($this->relatedPaths[$idx]) ? [$this->relatedPaths[$idx]] : [];
        }

        if ($folderKey === 'related') {
            return $this->relatedPaths;
        }

        throw new InvalidArgumentException('Unknown folder key.');
    }

    /**
     * Resolves a folder path based on key.
     *
     * @param string|null $folderKey Identifier for the folder to resolve
     * @return string|null Resolved folder path or null if not found
     */
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
     * Collects files from a directory.
     *
     * @param string $folder Path to the directory
     * @param bool $recursive Whether to include subdirectories
     * @return array<int, array<string, mixed>> Array of file information
     */
    private function collectFiles(string $folder, bool $recursive): array
    {
        $result = [];

        if (!is_dir($folder)) {
            return $result;
        }

        if ($recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
        } else {
            $iterator = new FilesystemIterator($folder, FilesystemIterator::SKIP_DOTS);
        }

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isFile()) {
                $result[] = $this->buildFileRecord($item);
            }
        }

        return $result;
    }

    /**
     * Builds file record from SplFileInfo.
     *
     * @param SplFileInfo $file File information object
     * @return array<string, mixed> Formatted file information
     */
    private function buildFileRecord(SplFileInfo $file): array
    {
        $path = $this->normalizePath($file->getPathname());
        $mime = $this->detectMimeType($path);

        return [
            'name' => $file->getFilename(),
            'basename' => $file->getBasename(),
            'path' => $path,
            'directory' => $this->normalizePath($file->getPath()),
            'extension' => strtolower($file->getExtension()),
            'size' => $file->getSize(),
            'permissions' => sprintf('%04o', $file->getPerms() & 0777),
            'createdAt' => (new DateTimeImmutable())->setTimestamp((int)$file->getCTime())->format(DATE_ATOM),
            'modifiedAt' => (new DateTimeImmutable())->setTimestamp((int)$file->getMTime())->format(DATE_ATOM),
            'createdTimestamp' => $file->getCTime(),
            'modifiedTimestamp' => $file->getMTime(),
            'mime' => $mime,
        ];
    }

    /**
     * Detects MIME type for a file.
     *
     * @param string $path Path to the file
     * @return string Detected MIME type or default type
     */
    private function detectMimeType(string $path): string
    {
        if (!is_file($path)) {
            return 'application/octet-stream';
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return \is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    /**
     * Normalizes directory schema.
     *
     * @param array<int, string>|string $schema Directory schema to normalize
     * @return array<int, string> Normalized schema components
     */
    private function normalizeSchema(array|string $schema): array
    {
        if (\is_string($schema)) {
            $schema = array_values(array_filter(
                array_map('trim', explode('/', trim($schema, '/'))),
                static fn ($v) => $v !== ''
            ));
        }

        if ($schema === []) {
            throw new InvalidArgumentException('Folder schema cannot be empty.');
        }

        return array_map(static fn (string $part): string => trim($part, DIRECTORY_SEPARATOR . " "), $schema);
    }

    /**
     * Builds directory path from schema.
     *
     * @param array<int, string> $schema Schema to use for building
     * @param DateTimeInterface $date Date to use in path construction
     * @return string Built directory path
     */
    private function buildSchemaPath(array $schema, DateTimeInterface $date): string
    {
        $parts = [];
        foreach ($schema as $part) {
            $parts[] = $this->replaceTokens($part, $date);
        }

        return implode(DIRECTORY_SEPARATOR, array_filter($parts, static fn ($v) => $v !== ''));
    }

    /**
     * Replaces tokens in a path segment.
     *
     * @param string $part Path segment with potential placeholders
     * @param DateTimeInterface $date Date to use for replacements
     * @return string Processed path segment
     */
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

        $part = strtr($part, $map);

        if ($part === 'prefix') {
            $part = $this->prefix;
        }

        return $this->sanitizePathSegment($part);
    }

    /**
     * Sanitizes a path segment.
     *
     * @param string $segment Segment to sanitize
     * @return string Sanitized segment
     */
    private function sanitizePathSegment(string $segment): string
    {
        $segment = trim($segment);
        $segment = str_replace(["\0", '/', '\\'], '-', $segment);
        $segment = preg_replace('/[<>:"|?*]+/u', '-', $segment) ?? $segment;

        return trim($segment, " .-");
    }

    /**
     * Normalizes file system path.
     *
     * @param string $path Path to normalize
     * @return string Normalized path
     */
    private function normalizePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = [];

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        $prefix = str_starts_with($path, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';
        return $prefix . implode(DIRECTORY_SEPARATOR, $parts);
    }

    /**
     * Ensures directory exists with specified permissions.
     *
     * @param string $path Path to the directory
     * @param int $permissions Permissions to set
     * @return void
     */
    private function ensureDirectory(string $path, int $permissions): void
    {
        if (!is_dir($path) && !mkdir($path, $permissions, true) && !is_dir($path)) {
            throw new RuntimeException("Failed to create directory: {$path}");
        }

        @chmod($path, $permissions);
    }

    /**
     * Deletes a directory and all its contents recursively.
     *
     * This method removes files and subdirectories within the specified directory,
     * then removes the directory itself. It handles nested directory structures
     * by using RecursiveIteratorIterator to process each file/directory in order.
     *
     * @param string $dir The path to the directory to delete.
     * @return void
     */
    private function deleteDirectoryRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    /**
     * Calculates the total size of a directory in bytes.
     *
     * @param string $dir The path to the directory.
     * @return int The total size in bytes.
     */
    private function calculateFolderSize(string $dir): int
    {
        $size = 0;

        if (!is_dir($dir)) {
            return 0;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isFile()) {
                $size += $item->getSize();
            }
        }

        return $size;
    }

    /**
     * Counts the number of subfolders within a given directory.
     *
     * This method recursively counts how many subdirectories are present
     * in the specified directory, excluding '.' and '..' entries.
     *
     * @param string $dir The directory path to count subfolders for.
     * @return int Returns the number of subdirectories found.
     */
    private function countSubfolders(string $dir): int
    {
        if (!is_dir($dir)) {
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

    /**
     * Generates a unique identifier for temporary directories.
     *
     * This method creates a cryptographically secure random hexadecimal string
     * that can be used as a unique identifier for files or folders.
     *
     * @return string A hexadecimal string representation of 16 bytes (32 characters long).
     */
    private function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Generates a consistent temporary JSON filename for a given main path.
     *
     * This method creates a unique but deterministic name for the JSON metadata file
     * associated with a temporary directory identified by its main path. The generated
     * name incorporates a hash of the main path and timestamp to ensure uniqueness.
     *
     * @param string $mainPath The absolute path of the main directory for which the JSON is being created.
     * @return string A unique filename (without extension) for the temporary metadata file.
     */
    private function generateTempJsonName(string $mainPath): string
    {
        return 'upload-folder-' . substr(hash('sha256', $mainPath . '|' . microtime(true)), 0, 24);
    }

    /**
     * Asserts that structure has been created.
     *
     * @return void
     * @throws RuntimeException If createStructure() hasn't been called
     */
    private function assertCreated(): void
    {
        if ($this->mainPath === null) {
            throw new RuntimeException('First, call createStructure().');
        }
    }
}
