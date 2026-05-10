<?php

declare(strict_types=1);

namespace Atom\FileSytem;

final class BrowserUploadManager
{
    private string $uploadDir;
    private string $tempDir;
    private int $maxFileSize;
    private int $maxTotalSize;
    private array $allowedExtensions;
    private array $allowedMimeTypes;
    private bool $allowMultiple;
    private int $maxFiles;
    private bool $allowChunked;
    private int $chunkSize;
    private bool $allowPauseResume;
    private bool $allowCancel;
    private bool $overwriteExisting;
    private bool $generateRandomNames;

    private \finfo $finfo;

    public function __construct(array $config = [])
    {
        $this->uploadDir = rtrim($config['upload_dir'] ?? __DIR__ . '/uploads', '/');
        $this->tempDir = rtrim($config['temp_dir'] ?? __DIR__ . '/uploads/.tmp', '/');

        $this->maxFileSize = (int)($config['max_file_size'] ?? 50 * 1024 * 1024);
        $this->maxTotalSize = (int)($config['max_total_size'] ?? 200 * 1024 * 1024);

        $this->allowedExtensions = array_values(array_map('strtolower', $config['allowed_extensions'] ?? []));
        $this->allowedMimeTypes = array_values(array_map('strtolower', $config['allowed_mime_types'] ?? []));

        $this->allowMultiple = (bool)($config['allow_multiple'] ?? true);
        $this->maxFiles = (int)($config['max_files'] ?? 10);

        $this->allowChunked = (bool)($config['allow_chunked'] ?? true);
        $this->chunkSize = max(1024, (int)($config['chunk_size'] ?? 100 * 1024));

        $this->allowPauseResume = (bool)($config['allow_pause_resume'] ?? true);
        $this->allowCancel = (bool)($config['allow_cancel'] ?? true);

        $this->overwriteExisting = (bool)($config['overwrite_existing'] ?? false);
        $this->generateRandomNames = (bool)($config['generate_random_names'] ?? true);

        $this->ensureDirectory($this->uploadDir);
        $this->ensureDirectory($this->tempDir);

        $this->finfo = new \finfo(FILEINFO_MIME_TYPE);
    }

    public function uploadSingle(array $files, array $meta = []): array
    {
        $normalized = $this->normalizeFilesArray($files);
        if (count($normalized) !== 1) {
            return $this->fail('Exactly one file expected.');
        }

        return $this->processStandardUpload($normalized, $meta, false);
    }

    public function uploadMultiple(array $files, array $meta = []): array
    {
        $normalized = $this->normalizeFilesArray($files);

        if (!$this->allowMultiple && count($normalized) > 1) {
            return $this->fail('Multiple file uploads are disabled.');
        }

        if (count($normalized) > $this->maxFiles) {
            return $this->fail('Maximum number of files exceeded.');
        }

        return $this->processStandardUpload($normalized, $meta, true);
    }

    public function uploadFromField(string $fieldName = 'file', array $meta = []): array
    {
        if (!isset($_FILES[$fieldName])) {
            return $this->fail("No field \${$fieldName} in \$_FILES.");
        }

        return $this->uploadSingle($_FILES[$fieldName], $meta);
    }

    public function uploadMultipleFromField(string $fieldName = 'files', array $meta = []): array
    {
        if (!isset($_FILES[$fieldName])) {
            return $this->fail("No field \${$fieldName} in \$_FILES.");
        }

        return $this->uploadMultiple($_FILES[$fieldName], $meta);
    }

    public function startChunkSession(
        string $originalName,
        int $totalSize,
        int $totalChunks,
        array $meta = []
    ): array {
        if (!$this->allowChunked) {
            return $this->fail('Chunked upload is disabled.');
        }

        $originalName = $this->sanitizeFilename($originalName);
        if ($originalName === '') {
            return $this->fail('Invalid file name.');
        }

        if ($totalSize <= 0 || $totalChunks <= 0) {
            return $this->fail('Incorrect session parameters.');
        }

        if ($totalSize > $this->maxTotalSize) {
            return $this->fail('File exceeds maximum total size.');
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($this->allowedExtensions && !in_array($ext, $this->allowedExtensions, true)) {
            return $this->fail('Illegal extension.');
        }

        $uploadId = $this->generateUploadId();
        $session = [
            'upload_id' => $uploadId,
            'original_name' => $originalName,
            'title' => $this->validateTitle($meta['title'] ?? pathinfo($originalName, PATHINFO_FILENAME)),
            'ext' => $ext,
            'total_size' => $totalSize,
            'total_chunks' => $totalChunks,
            'chunk_size' => $this->chunkSize,
            'received_chunks' => [],
            'status' => 'uploading', // uploading | paused | cancelled | completed | error
            'created_at' => time(),
            'updated_at' => time(),
            'meta' => $meta,
        ];

        $this->saveSession($uploadId, $session);
        return $this->ok(['upload_id' => $uploadId, 'status' => 'uploading']);
    }

    public function saveChunkFromRequest(string $uploadId): array
    {
        $chunkIndex = isset($_POST['chunk_index']) ? (int)$_POST['chunk_index'] : null;
        if ($chunkIndex === null) {
            return $this->fail('No chunk_index.');
        }

        if (!isset($_FILES['file'])) {
            return $this->fail('No Chanku data to save.');
        }

        $raw = file_get_contents($_FILES['file']['tmp_name']);

        if ($raw === false) {
            return $this->fail('Failed to read chunk data.');
        }

        return $this->saveChunk($uploadId, $chunkIndex, $raw);
    }

    public function saveChunk(string $uploadId, int $chunkIndex, string $chunkData): array
    {
        $session = $this->loadSession($uploadId);
        if (!$session) {
            return $this->fail('No upload session found.');
        }

        if (($session['status'] ?? '') === 'cancelled') {
            return $this->fail('The upload was canceled.');
        }

        if (($session['status'] ?? '') === 'paused') {
            return $this->fail('Upload is paused.');
        }

        if (($session['status'] ?? '') === 'completed') {
            return $this->fail('The upload is now complete.');
        }

        $totalChunks = (int)$session['total_chunks'];
        if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            return $this->fail('Incorrect chunk number.');
        }

        if (strlen($chunkData) > $this->chunkSize && $chunkIndex < $totalChunks - 1) {
            return $this->fail('Chunk is larger than the allowed size.');
        }

        $chunkDir = $this->chunkDir($uploadId);
        $this->ensureDirectory($chunkDir);

        $chunkPath = $this->chunkPath($uploadId, $chunkIndex);
        if (file_put_contents($chunkPath, $chunkData, LOCK_EX) === false) {
            return $this->fail('Could not save chunk.');
        }

        $received = $session['received_chunks'] ?? [];
        if (!in_array($chunkIndex, $received, true)) {
            $received[] = $chunkIndex;
            sort($received);
        }

        $session['received_chunks'] = $received;
        $session['updated_at'] = time();
        $this->saveSession($uploadId, $session);

        $progress = $this->getProgressFromSession($session);

        return $this->ok([
            'upload_id' => $uploadId,
            'chunk_index' => $chunkIndex,
            'received_chunks' => $received,
            'progress' => $progress,
            'status' => $session['status'],
        ]);
    }

    public function finalizeChunk(string $uploadId): array
    {
        $session = $this->loadSession($uploadId);
        if (!$session) {
            return $this->fail('No upload session found.');
        }

        if (($session['status'] ?? '') === 'cancelled') {
            return $this->fail('The upload was canceled.');
        }

        $totalChunks = (int)$session['total_chunks'];
        $received = $session['received_chunks'] ?? [];

        if (count($received) !== $totalChunks) {
            return $this->fail('Not all chunks were sent.');
        }

        $mergedTemp = $this->tempDir . '/' . $uploadId . '.merged.tmp';
        $out = fopen($mergedTemp, 'wb');
        if ($out === false) {
            return $this->fail('Failed to create output file.');
        }

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $this->chunkPath($uploadId, $i);
                if (!is_file($chunkPath)) {
                    fclose($out);
                    return $this->fail("Chunk is missing #{$i}.");
                }

                $in = fopen($chunkPath, 'rb');
                if ($in === false) {
                    fclose($out);
                    return $this->fail("Could not read chunk #{$i}.");
                }

                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        $finalSize = filesize($mergedTemp);
        if ($finalSize === false || $finalSize <= 0) {
            @unlink($mergedTemp);
            return $this->fail('The submitted file is empty.');
        }

        if ($finalSize > $this->maxFileSize) {
            @unlink($mergedTemp);
            return $this->fail('The file exceeds the maximum size.');
        }

        $mime = $this->detectMime($mergedTemp);
        $ext = strtolower((string)$session['ext']);

        if ($this->allowedMimeTypes && !\in_array($mime, $this->allowedMimeTypes, true)) {
            @unlink($mergedTemp);
            return $this->fail('Illegal MIME type.');
        }

        if ($this->allowedExtensions && !\in_array($ext, $this->allowedExtensions, true)) {
            @unlink($mergedTemp);
            return $this->fail('Illegal extension.');
        }

        $target = $this->buildFinalPath($session['original_name']);
        if (!$this->overwriteExisting && file_exists($target)) {
            $target = $this->buildUniqueFinalPath($session['original_name']);
        }

        if (!rename($mergedTemp, $target)) {
            @unlink($mergedTemp);
            return $this->fail('Failed to move file to destination directory.');
        }

        $session['status'] = 'completed';
        $session['updated_at'] = time();
        $session['final_path'] = $target;
        $session['mime'] = $mime;
        $session['size'] = $finalSize;
        $this->saveSession($uploadId, $session);

        $this->deleteChunkFiles($uploadId);
        $manifest = $this->sessionPath($uploadId);
        if (is_file($manifest)) {
            @unlink($manifest);
        }

        return $this->ok([
            'upload_id' => $uploadId,
            'status' => 'completed',
            'path' => $target,
            'filename' => basename($target),
            'mime' => $mime,
            'size' => $finalSize,
            'progress' => 100,
        ]);
    }

    public function pause(string $uploadId): array
    {
        if (!$this->allowPauseResume) {
            return $this->fail('Pause/resume is disabled.');
        }

        $session = $this->loadSession($uploadId);
        if (!$session) {
            return $this->fail('No upload session found.');
        }

        if (($session['status'] ?? '') !== 'uploading') {
            return $this->fail('This upload cannot be paused.');
        }

        $session['status'] = 'paused';
        $session['updated_at'] = time();
        $this->saveSession($uploadId, $session);

        return $this->ok(['upload_id' => $uploadId, 'status' => 'paused']);
    }

    public function resume(string $uploadId): array
    {
        if (!$this->allowPauseResume) {
            return $this->fail('Pause/resume is disabled.');
        }

        $session = $this->loadSession($uploadId);
        if (!$session) {
            return $this->fail('No upload session found.');
        }

        if (($session['status'] ?? '') !== 'paused') {
            return $this->fail('Upload is not paused.');
        }

        $session['status'] = 'uploading';
        $session['updated_at'] = time();
        $this->saveSession($uploadId, $session);

        return $this->ok([
            'upload_id' => $uploadId,
            'status' => 'uploading',
            'received_chunks' => $session['received_chunks'] ?? [],
            'progress' => $this->getProgressFromSession($session),
        ]);
    }

    public function cancel(string $uploadId, bool $deleteFiles = true): array
    {
        if (!$this->allowCancel) {
            return $this->fail('Cancellation is disabled.');
        }

        $session = $this->loadSession($uploadId);
        if (!$session) {
            return $this->fail('No upload session found.');
        }

        $session['status'] = 'cancelled';
        $session['updated_at'] = time();
        $this->saveSession($uploadId, $session);

        if ($deleteFiles) {
            $this->deleteChunkFiles($uploadId);
            $manifest = $this->sessionPath($uploadId);
            if (is_file($manifest)) {
                @unlink($manifest);
            }
        }

        return $this->ok(['upload_id' => $uploadId, 'status' => 'cancelled']);
    }

    public function status(string $uploadId): array
    {
        $session = $this->loadSession($uploadId);
        if (!$session) {
            return $this->fail('No upload session found.');
        }

        return $this->ok([
            'upload_id' => $uploadId,
            'status' => $session['status'],
            'progress' => $this->getProgressFromSession($session),
            'received_chunks' => $session['received_chunks'] ?? [],
            'total_chunks' => $session['total_chunks'] ?? 0,
            'total_size' => $session['total_size'] ?? 0,
            'updated_at' => $session['updated_at'] ?? null,
        ]);
    }

    public function detectMime(string $filePath): string
    {
        return strtolower((string)($this->finfo->file($filePath) ?: 'application/octet-stream'));
    }

    public function isImage(string $filePath): bool
    {
        $mime = $this->detectMime($filePath);
        return str_starts_with($mime, 'image/');
    }

    public function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float)$bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$i];
    }

    private function processStandardUpload(array $files, array $meta, bool $multiple): array
    {
        $results = [];
        $totalSize = 0;

        foreach ($files as $file) {
            $check = $this->validateUploadedFile($file, $meta);
            if (!$check['ok']) {
                return $check;
            }
            $totalSize += (int)$file['size'];
        }

        if ($totalSize > $this->maxTotalSize) {
            return $this->fail('Maximum total file size exceeded.');
        }

        foreach ($files as $file) {
            $result = $this->moveUploadedFile($file, $meta);
            if (!$result['ok']) {
                return $result;
            }
            $results[] = $result['data'];
        }

        return $this->ok([
            'multiple' => $multiple,
            'files' => $results,
        ]);
    }

    private function moveUploadedFile(array $file, array $meta): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->fail($this->uploadErrorMessage((int)$file['error']));
        }

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return $this->fail('Invalid upload file.');
        }

        $originalName = $this->sanitizeFilename((string)$file['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $size = (int)$file['size'];
        $mime = $this->detectMime($file['tmp_name']);
        $title = $this->validateTitle($meta['title'] ?? pathinfo($originalName, PATHINFO_FILENAME));

        if ($originalName === '') {
            return $this->fail('Empty filename.');
        }

        if ($size <= 0) {
            return $this->fail('The file is empty.');
        }

        if ($size > $this->maxFileSize) {
            return $this->fail('Plik przekracza maksymalny rozmiar.');
        }

        if ($this->allowedExtensions && !in_array($ext, $this->allowedExtensions, true)) {
            return $this->fail('Illegal extension.');
        }

        if ($this->allowedMimeTypes && !in_array($mime, $this->allowedMimeTypes, true)) {
            return $this->fail('Illegal MIME type.');
        }

        $target = $this->buildFinalPath($originalName);
        if (!$this->overwriteExisting && file_exists($target)) {
            $target = $this->buildUniqueFinalPath($originalName);
        }

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return $this->fail('Failed to save file.');
        }

        return $this->ok([
            'original_name' => $originalName,
            'title' => $title,
            'path' => $target,
            'filename' => basename($target),
            'extension' => $ext,
            'mime' => $mime,
            'size' => $size,
            'human_size' => $this->humanBytes($size),
        ]);
    }

    private function validateUploadedFile(array $file, array $meta = []): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->fail($this->uploadErrorMessage((int)$file['error']));
        }

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return $this->fail('Invalid upload file.');
        }

        $originalName = $this->sanitizeFilename((string)($file['name'] ?? ''));
        if ($originalName === '') {
            return $this->fail('Invalid file name.');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            return $this->fail('The file is empty.');
        }

        if ($size > $this->maxFileSize) {
            return $this->fail('The file exceeds the maximum size.');
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($this->allowedExtensions && !\in_array($ext, $this->allowedExtensions, true)) {
            return $this->fail('Illegal extension.');
        }

        $mime = $this->detectMime($file['tmp_name']);
        if ($this->allowedMimeTypes && !\in_array($mime, $this->allowedMimeTypes, true)) {
            return $this->fail('Illegal MIME type.');
        }

        $this->validateTitle($meta['title'] ?? pathinfo($originalName, PATHINFO_FILENAME));

        return $this->ok([]);
    }

    private function validateTitle(string $title): string
    {
        $title = trim($title);
        $title = preg_replace('/\s+/u', ' ', $title) ?? '';

        if ($title === '') {
            throw new \RuntimeException('Title cannot be empty.');
        }

        if (mb_strlen($title) > 255) {
            throw new \RuntimeException('The title is too long.');
        }

        return $title;
    }

    private function normalizeFilesArray(array $files): array
    {
        if (!isset($files['name'])) {
            return [];
        }

        if (!is_array($files['name'])) {
            return [$files];
        }

        $normalized = [];
        $count = count($files['name']);

        for ($i = 0; $i < $count; $i++) {
            $normalized[] = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
        }

        return $normalized;
    }

    private function sanitizeFilename(string $name): string
    {
        $name = basename(trim($name));
        $name = str_replace(["\0", "\r", "\n", "\t"], '', $name);
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?? '';
        $name = preg_replace('/\s+/u', '_', $name) ?? '';
        $name = preg_replace('/_+/', '_', $name) ?? '';
        $name = trim($name, '._- ');

        return $name;
    }

    private function buildFinalPath(string $originalName): string
    {
        $safe = $this->sanitizeFilename($originalName);
        if ($safe === '') {
            $safe = 'file';
        }

        if ($this->generateRandomNames) {
            $ext = pathinfo($safe, PATHINFO_EXTENSION);
            $base = pathinfo($safe, PATHINFO_FILENAME);
            $rand = bin2hex(random_bytes(8));
            $safe = $base . '_' . $rand . ($ext !== '' ? '.' . $ext : '');
        }

        return $this->uploadDir . '/' . $safe;
    }

    private function buildUniqueFinalPath(string $originalName): string
    {
        $safe = $this->sanitizeFilename($originalName);
        if ($safe === '') {
            $safe = 'file';
        }

        $ext = pathinfo($safe, PATHINFO_EXTENSION);
        $base = pathinfo($safe, PATHINFO_FILENAME);
        $rand = bin2hex(random_bytes(6));

        return $this->uploadDir . '/' . $base . '_' . $rand . ($ext !== '' ? '.' . $ext : '');
    }

    private function generateUploadId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function sessionPath(string $uploadId): string
    {
        return $this->tempDir . '/' . $uploadId . '.json';
    }

    private function chunkDir(string $uploadId): string
    {
        return $this->tempDir . '/' . $uploadId;
    }

    private function chunkPath(string $uploadId, int $chunkIndex): string
    {
        return $this->chunkDir($uploadId) . '/' . $chunkIndex . '.part';
    }

    private function loadSession(string $uploadId): ?array
    {
        $path = $this->sessionPath($uploadId);
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        return \is_array($data) ? $data : null;
    }

    private function saveSession(string $uploadId, array $session): void
    {
        $this->ensureDirectory($this->tempDir);
        $path = $this->sessionPath($uploadId);
        file_put_contents($path, json_encode($session, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function deleteChunkFiles(string $uploadId): void
    {
        $dir = $this->chunkDir($uploadId);
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.part') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private function getProgressFromSession(array $session): int
    {
        $total = (int)($session['total_chunks'] ?? 0);
        if ($total <= 0) {
            return 0;
        }

        $received = \count($session['received_chunks'] ?? []);
        return (int)floor(($received / $total) * 100);
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE from form.',
            UPLOAD_ERR_PARTIAL => 'The file was partially sent.',
            UPLOAD_ERR_NO_FILE => 'No file selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'No temporary directory.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to save file to disk.',
            UPLOAD_ERR_EXTENSION => 'Upload was stopped by a PHP extension.',
            default => 'Unknown upload error.',
        };
    }

    private function ok(array $data): array
    {
        return [
            'ok' => true,
            'data' => $data,
        ];
    }

    private function fail(string $message, array $extra = []): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'extra' => $extra,
        ];
    }
}
