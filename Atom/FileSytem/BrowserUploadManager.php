<?php

declare(strict_types=1);

namespace Atom\FileSytem;

/**
 * The BrowserUploadManager class is responsible for managing file uploads through the browser.
 * It handles operations related to uploading files to the server using HTTP forms.
 */
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

    /**
     * Create a new instance
     *
     * @param array $config Configuration options
     * @return void
     */
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

    /**
     * Uploads a single file using standard file upload methods.
     *
     * This method handles the uploading of a single file by:
     * - Normalizing the file input array structure
     * - Validating that exactly one file was submitted
     * - Processing the file through the standard upload pipeline
     *
     * @param array $files The file data from $_FILES
     * @param array $meta Additional metadata about the file (optional)
     * @return array An array containing either a success or error result
     */
    public function uploadSingle(array $files, array $meta = []): array
    {
        $normalized = $this->normalizeFilesArray($files);
        if (\count($normalized) !== 1) {
            return $this->fail('Exactly one file expected.');
        }

        return $this->processStandardUpload($normalized, $meta, false);
    }

    /**
     * Uploads multiple files using standard file upload methods.
     *
     * This method handles the uploading of multiple files by:
     * - Normalizing the file input array structure
     * - Validating that the number of files doesn't exceed the allowed limit
     * - Processing all files through the standard upload pipeline
     *
     * @param array $files The file data from $_FILES
     * @param array $meta Additional metadata about the files (optional)
     * @return array An array containing either a success or error result
     */
    public function uploadMultiple(array $files, array $meta = []): array
    {
        $normalized = $this->normalizeFilesArray($files);

        if (!$this->allowMultiple && \count($normalized) > 1) {
            return $this->fail('Multiple file uploads are disabled.');
        }

        if (\count($normalized) > $this->maxFiles) {
            return $this->fail('Maximum number of files exceeded.');
        }

        return $this->processStandardUpload($normalized, $meta, true);
    }

    /**
     * Uploads a single file from a specific POST field.
     *
     * This method handles the uploading of a single file by:
     * - Validating that the specified field exists in $_FILES
     * - Delegating to the uploadSingle method for processing
     *
     * @param string $fieldName The name of the POST field containing the file
     * @param array $meta Additional metadata about the file (optional)
     * @return array An array containing either a success or error result
     */
    public function uploadFromField(string $fieldName = 'file', array $meta = []): array
    {
        if (!isset($_FILES[$fieldName])) {
            return $this->fail("No field \${$fieldName} in \$_FILES.");
        }

        return $this->uploadSingle($_FILES[$fieldName], $meta);
    }

    /**
     * Uploads multiple files from a specific POST field.
     *
     * This method handles the uploading of multiple files by:
     * - Validating that the specified field exists in $_FILES
     * - Delegating to the uploadMultiple method for processing
     *
     * @param string $fieldName The name of the POST field containing the files
     * @param array $meta Additional metadata about the files (optional)
     * @return array An array containing either a success or error result
     */
    public function uploadMultipleFromField(string $fieldName = 'files', array $meta = []): array
    {
        if (!isset($_FILES[$fieldName])) {
            return $this->fail("No field \${$fieldName} in \$_FILES.");
        }

        return $this->uploadMultiple($_FILES[$fieldName], $meta);
    }

    /**
     * Initiates a new chunked upload session for a file.
     *
     * This method creates and initializes a new upload session for a file that will be uploaded in chunks.
     * It performs validation on the file and session parameters, generates a unique upload ID,
     * and stores the session data for tracking the upload progress.
     *
     * @param string $originalName The original name of the file being uploaded
     * @param int $totalSize The total size of the file in bytes
     * @param int $totalChunks The total number of chunks the file will be divided into
     * @param array $meta Additional metadata about the file (optional)
     * @return array An array containing either a success or error result with the new upload session information
     */
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

    /**
     * Handles chunk saving from an HTTP request by reading the chunk data from $_FILES.
     *
     * This method processes an incoming chunk upload request by:
     * - Extracting the chunk index from POST data
     * - Reading the actual chunk data from the uploaded file
     * - Validating that the data was read successfully
     * - Delegating the chunk saving to the main saveChunk method
     *
     * @param string $uploadId The unique identifier for the upload session
     * @return array An array containing either a success or error result from the save operation
     */
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

    /**
     * Saves a chunk of data to temporary storage during multi-chunk upload processing.
     *
     * This method handles the saving of individual file chunks to disk, tracks which
     * chunks have been received, and maintains progress tracking for the overall upload.
     * It performs validation to ensure the chunk is valid and within the expected parameters.
     *
     * @param string $uploadId The unique identifier for the upload session
     * @param int $chunkIndex The index/position of the chunk being saved
     * @param string $chunkData The actual chunk data to save
     * @return array An array containing either a success or error result with progress information
     */
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

        if (\strlen($chunkData) > $this->chunkSize && $chunkIndex < $totalChunks - 1) {
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

    /**
     * Finalizes a multi-chunk upload by merging all chunks into a single file.
     *
     * This method handles the finalization process of a chunked upload by:
     * - Validating the existence of the upload session
     * - Verifying that all chunks have been received
     * - Merging all chunk files into a single temporary file
     * - Performing final validation on the merged file
     * - Moving the merged file to its final destination
     * - Cleaning up temporary files and session data
     *
     * @param string $uploadId The unique identifier for the upload session
     * @return array An array containing either a success or error result with finalization information
     */
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

        if (\count($received) !== $totalChunks) {
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

    /**
     * Pauses an ongoing upload session.
     *
     * This method handles pausing an active file upload by:
     * - Validating that pause/resume functionality is enabled
     * - Loading the existing session data
     * - Verifying that the current status is 'uploading'
     * - Updating the session status from 'uploading' to 'paused'
     * - Storing the updated session data for future resumption
     *
     * @param string $uploadId The unique identifier for the upload session
     * @return array An array containing either a success or error result with pause confirmation
     */
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

    /**
     * Resumes a paused upload session.
     *
     * This method handles resuming an upload that was previously paused by:
     * - Validating that pause/resume functionality is enabled
     * - Loading the existing session data
     * - Verifying that the current status is 'paused'
     * - Updating the session status from 'paused' to 'uploading'
     * - Returning information about the resumed upload
     *
     * @param string $uploadId The unique identifier for the upload session
     * @return array An array containing either a success or error result with resumed upload information
     */
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

    /**
     * Cancels an upload session and optionally deletes associated files.
     *
     * This method handles the cancellation of an ongoing file upload by:
     * - Validating that cancellation is permitted
     * - Loading the existing session data
     * - Updating the session status to cancelled
     * - Optionally deleting temporary chunk files and manifest files
     *
     * @param string $uploadId The unique identifier for the upload session
     * @param bool $deleteFiles Whether to also delete associated chunk files
     * @return array An array containing either a success or error result
     */
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

    /**
     * Retrieves the status of an upload session by its ID.
     *
     * This method fetches and returns the current status of an ongoing file upload,
     * including progress information, chunk data, and metadata about the upload session.
     *
     * @param string $uploadId The unique identifier for the upload session
     * @return array An array containing the upload status and related information
     */
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

    /**
     * Detects the MIME type of a file using finfo.
     *
     * This method uses PHP's finfo class to determine the MIME type of a file
     * by examining its contents rather than relying solely on file extensions.
     *
     * @param string $filePath The path to the file to analyze
     * @return string The detected MIME type in lowercase, or 'application/octet-stream' if undetermined
     */
    public function detectMime(string $filePath): string
    {
        return strtolower((string)($this->finfo->file($filePath) ?: 'application/octet-stream'));
    }

    /**
     * Determines if a file is an image based on its MIME type.
     *
     * This method uses the detectMime function to check if a file has an image MIME type
     * by examining if it starts with 'image/'.
     *
     * @param string $filePath The path to the file to check
     * @return bool True if the file is an image, false otherwise
     */
    public function isImage(string $filePath): bool
    {
        $mime = $this->detectMime($filePath);
        return str_starts_with($mime, 'image/');
    }

    /**
     * Converts bytes to a human-readable format.
     *
     * This method converts a given number of bytes into a more readable format
     * (B, KB, MB, GB, TB) by dividing by 1024 until the appropriate unit is found.
     *
     * @param int $bytes The number of bytes to convert
     * @return string A human-readable string representation of the byte value
     */
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

    /**
     * Processes standard file uploads for multiple files.
     *
     * This method handles the complete upload process for one or more files, including:
     * - Validating each uploaded file against size and type restrictions
     * - Checking total size limits across all files
     * - Moving valid files to their final destination
     * - Returning structured results with file information
     *
     * @param array $files The array of files from $_FILES
     * @param array $meta Additional metadata for the files
     * @param bool $multiple Flag indicating if multiple files are being processed
     * @return array An array containing either a success or error result with all processed files
     */
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

    /**
     * Processes the movement of an uploaded file to its final destination.
     *
     * This method handles the complete process of moving an uploaded file from the temporary
     * location to the final destination, including:
     * - Validating the upload status and file integrity
     * - Sanitizing the filename and extracting metadata
     * - Performing size and type validations
     * - Generating appropriate file paths
     * - Moving the file with proper error handling
     *
     * @param array $file The uploaded file data from $_FILES
     * @param array $meta Additional metadata for the file
     * @return array An array containing either a success or error result with file information
     */
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

    /**
     * Validates an uploaded file against multiple criteria.
     *
     * This method performs comprehensive validation on an uploaded file, checking for:
     * - Upload errors
     * - File integrity (temporary file validation)
     * - File name validity
     * - File size constraints
     * - Allowed file extensions
    * Allowed MIME types
    * File title validation
    *
    * @param array $file The uploaded file data from $_FILES
    * @param array $meta Additional metadata for the file
    * @return array An array containing either a success or error result
    */
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

    /**
     * Validates and sanitizes a title string.
     *
     * This method processes a title by trimming whitespace, replacing multiple consecutive
     * whitespace characters with a single space, and performing validation checks.
     * It ensures the title meets basic quality requirements for length and non-emptiness.
     *
     * @param string $title The title to validate and sanitize
     * @return string The sanitized title (trimmed and with normalized whitespace)
     * @throws \RuntimeException If the title is empty after trimming or too long
     */
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

    /**
     * Normalizes the $_FILES array structure to ensure consistent formatting.
     *
     * This method standardizes the structure of the $_FILES superglobal array,
     * which can have different formats depending on whether multiple files are uploaded
     * under the same name. It ensures that all file information is presented in a consistent
     * array format regardless of how many files were uploaded.
     *
     * @param array $files The raw $_FILES array or subset of it
     * @return array An array with normalized file information, where each element represents a single file
     */
    private function normalizeFilesArray(array $files): array
    {
        if (!isset($files['name'])) {
            return [];
        }

        if (!\is_array($files['name'])) {
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

    /**
     * Sanitizes a filename by removing/replacing invalid characters and normalizing whitespace.
     *
     * This method performs multiple sanitization steps on a filename:
     * 1. Extracts the basename (removes any directory paths)
     * 2. Trims whitespace from both ends
     * 3. Removes or replaces invalid characters like null bytes, carriage returns, etc.
     * 4. Replaces non-alphanumeric characters (except spaces, dots, and hyphens) with underscores
     * 5. Normalizes multiple consecutive whitespace characters into a single underscore
     * 6. Removes or replaces multiple consecutive underscores with a single underscore
     * 7. Trims dots, hyphens, and spaces from both ends
     *
     * @param string $name The original filename to sanitize
     * @return string The sanitized filename
     */
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

    /**
     * Builds the final file path for a file, with optional random name generation.
     *
     * This method takes an original filename, sanitizes it, and constructs the final
     * destination path in the upload directory. If random name generation is enabled,
     * it appends a random 16-character hexadecimal string to the filename to prevent conflicts.
     *
     * @param string $originalName The original filename provided by the user
     * @return string The complete file path where the file should be stored
     */
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

    /**
     * Builds a unique final file path by appending a random suffix to prevent filename conflicts.
     *
     * This method takes an original filename, sanitizes it, and appends a random 12-character
     * hexadecimal string to create a unique filename. It handles both files with and without
     * extensions by preserving the original extension when one exists.
     *
     * @param string $originalName The original filename provided by the user
     * @return string A unique file path that helps prevent filename conflicts
     */
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

    /**
     * Generates a unique upload ID.
     *
     * This method creates a cryptographically secure random identifier
     * using binary-to-hexadecimal conversion of random bytes for use
     * as a unique identifier for upload sessions.
     *
     * @return string A hexadecimal encoded random string
     */
    private function generateUploadId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generates the file path for a session file.
     *
     * This method creates a complete file path for a session data file
     * by combining the temporary directory path with the upload ID and JSON extension.
     *
     * @param string $uploadId The unique identifier for the upload session
     * @return string The complete file path for the session file
     */
    private function sessionPath(string $uploadId): string
    {
        return $this->tempDir . '/' . $uploadId . '.json';
    }

    /**
     * Generates the directory path for chunk files.
     *
     * This method creates a directory path for storing chunk files
     * by combining the temporary directory path with the upload ID.
     *
     * @param string $uploadId The unique identifier for the upload session
     * @return string The complete directory path for the chunks
     */
    private function chunkDir(string $uploadId): string
    {
        return $this->tempDir . '/' . $uploadId;
    }

    /**
     * Generates the file path for a specific chunk file.
     *
     * This method creates a unique file path for a chunk file by combining
     * the chunks directory path with the chunk index and .part extension.
     *
     * @param string $uploadId The unique identifier for the upload session
     * @param int $chunkIndex The index/position of the chunk
     * @return string The complete file path for the chunk
     */
    private function chunkPath(string $uploadId, int $chunkIndex): string
    {
        return $this->chunkDir($uploadId) . '/' . $chunkIndex . '.part';
    }

    /**
     * Loads session data from a file.
     *
     * This method retrieves stored session data (such as upload progress information)
     * from a JSON file in the temporary directory based on the upload ID.
     * It reads the file, decodes the JSON data, and returns the parsed array.
     *
     * @param string $uploadId The unique identifier for the upload session
     * @return array|null The session data if successful, null otherwise
     */
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

    /**
     * Saves session data to a file.
     *
     * This method stores the session data (such as upload progress information)
     * to a JSON file in the temporary directory. The session data includes
     * information about uploaded chunks, total chunks, and other relevant metadata.
     *
     * @param string $uploadId The unique identifier for the upload session
     * @param array $session The session data to save
     * @return void
     */
    private function saveSession(string $uploadId, array $session): void
    {
        $this->ensureDirectory($this->tempDir);
        $path = $this->sessionPath($uploadId);
        file_put_contents($path, json_encode($session, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Deletes chunk files associated with a specific upload.
     *
     * This method removes all temporary chunk files and the directory for a given upload ID.
     * It first checks if the directory exists, then iterates through all .part files
     * in the directory and unlinks them one by one. Finally, it removes the directory itself.
     *
     * @param string $uploadId The unique identifier for the upload
     * @return void
     */
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

    /**
     * Calculates the progress percentage from session data.
     *
     * This method determines the progress of a multi-part file upload or operation
     * by comparing the number of received chunks with the total number of expected chunks.
     *
     * @param array $session Session data containing chunk information
     * @return int The progress percentage (0-100), or 0 if no valid data
     */
    private function getProgressFromSession(array $session): int
    {
        $total = (int)($session['total_chunks'] ?? 0);
        if ($total <= 0) {
            return 0;
        }

        $received = \count($session['received_chunks'] ?? []);
        return (int)floor(($received / $total) * 100);
    }

    /**
     * The ensureDirectory method ensures that a specified directory exists.
     * If the directory doesn't exist, it attempts to create it with the appropriate permissions.
     * If the directory creation fails, it throws a RuntimeException.
     * 
     * @param string $dir The directory path to check or create
     * @throws \RuntimeException Thrown when directory creation fails
     * @return void
     */
    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }

    /**
     * The uploadErrorMessage method returns an error message based on the file upload error code.
     * 
     * @param int $code The file upload error code
     * @return string The appropriate error message to display
     */
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

    /**
     * The ok method is used to return the success of an operation.
     *
     * @param array $data Data to return on success
     * @return array Array containing the success information
     */
    private function ok(array $data): array
    {
        return [
            'ok' => true,
            'data' => $data,
        ];
    }

    /**
     * The fail method is used to return the failure of an operation.
     *
     * @param string $message Error message
     * @param array $extra Additional error information
     * @return array Array containing the failure information
     */
    private function fail(string $message, array $extra = []): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'extra' => $extra,
        ];
    }
}
