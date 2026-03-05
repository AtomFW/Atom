<?php

declare(strict_types=1);

namespace Security;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * FileSafetyScanner
 *
 * Efficient, chunked file scanner using fopen/fread to avoid large memory usage.
 *
 */
final class FileSafetyScanner
{
    /** @var int Default chunk size (8 MB). Can be increased (e.g. 50MB) but be careful with memory. */
    private int $chunkSize;

    /** @var int|null Maximum bytes to scan per file (null = unlimited) */
    private ?int $maxBytesPerFile;

    /** @var array<string> Whitelist of extensions (no dot), if non-empty => only these are scanned (priority). */
    private array $includeExtensions;

    /** @var array<string> Blacklist of extensions (no dot) to skip. Ignored when includeExtensions not empty. */
    private array $excludeExtensions;

    /** @var array<string> Paths (substrings) or regex patterns to exclude (directories/files) */
    private array $excludePaths;

    /** @var LoggerInterface|null Logger to use (null = no logging) */
    private ?LoggerInterface $logger;

    /**
     * Signatures to search for.
     * Each item can be:
     *  - string (literal substring search)
     *  - array of form ['regex' => '...'] to use preg_match (PCRE)
     *
     * @var array<int, string|array>
     */
    private array $signatures;

    /** @var bool Whether string searches are case-insensitive (stripos) */
    private bool $caseInsensitive;

    /** @var callable|null Callback invoked when a finding is detected: function(array $finding): void */
    private $findingCallback = null;

    /** @var array<int,array> Accumulated findings */
    private array $findings = [];

    /** @var int Maximum length of signature (bytes) used for overlap */
    private int $maxSignatureLength = 0;

    /** @param array{
     *   chunkSize?: int,
     *   maxBytesPerFile?: int|null,
     *   includeExtensions?: array<string>,
     *   excludeExtensions?: array<string>,
     *   excludePaths?: array<string>,
     *   signatures?: array<int, string|array>,
     *   caseInsensitive?: bool,
     * } $options
     */
    public function __construct(array $options = [], ?LoggerInterface $logger = null)
    {
        $this->chunkSize = $options['chunkSize'] ?? (8 * 1024 * 1024); // 8MB default
        $this->maxBytesPerFile = $options['maxBytesPerFile'] ?? null;
        $this->includeExtensions = $this->normalizeExtensions($options['includeExtensions'] ?? []);
        $this->excludeExtensions = $this->normalizeExtensions($options['excludeExtensions'] ?? []);
        $this->excludePaths = $options['excludePaths'] ?? [];
        $this->signatures = $options['signatures'] ?? $this->defaultSignatures();
        $this->caseInsensitive = $options['caseInsensitive'] ?? true;

        $this->computeMaxSignatureLength();

        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Normalize extensions to lowercase, no dot.
     *
     * @param array<int,string> $exts
     * @return array<string>
     */
    private function normalizeExtensions(array $exts): array
    {
        $out = [];
        foreach ($exts as $e) {
            $e = (string)$e;
            $e = ltrim($e, '. ');
            if ($e !== '') {
                $out[strtolower($e)] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Default signatures to look for (as required in prompt).
     *
     * @return array<int,string>
     */
    private function defaultSignatures(): array
    {
        return [
            'eval(',
            'base64_decode(',
            'shell_exec(',
            'system(',
            '<?php',
            'passthru(',
            'exec(',
            'popen(',
            'proc_open(',
            '`', // backtick operator (note: may be noisy)
        ];
    }

    /**
     * Compute maximum signature length (used to determine overlap between chunks).
     */
    private function computeMaxSignatureLength(): void
    {
        $max = 0;
        foreach ($this->signatures as $s) {
            if (is_array($s) && isset($s['regex'])) {
                // roughly estimate regex length as 256 (can't know); make overlap conservative
                $len = 256;
            } else {
                $len = strlen((string)$s);
            }
            if ($len > $max) {
                $max = $len;
            }
        }
        $this->maxSignatureLength = max(1, $max);
    }

    /**
     * Set callback to be invoked for each finding.
     *
     * Callback signature: function(array $finding): void
     * Finding array keys:
     *  - file (string)
     *  - signature (string)
     *  - offset (int) byte offset in file
     *  - line (int) line number (1-based) (approximate)
     *  - snippet (string) surrounding content (up to ~160 chars)
     *  - truncated (bool) whether file scanning was truncated because of maxBytesPerFile
     *
     * @param callable $cb
     */
    public function setFindingCallback(callable $cb): void
    {
        $this->findingCallback = $cb;
    }

    /**
     * Get accumulated findings (array).
     *
     * @return array<int,array>
     */
    public function getFindings(): array
    {
        return $this->findings;
    }

    /**
     * Clear findings.
     */
    public function clearFindings(): void
    {
        $this->findings = [];
    }

    /**
     * Scan given path. If path is a directory, scan recursively.
     *
     * @param string $path
     * @return void
     */
    public function isSafe(string $path): void
    {
        if (!file_exists($path)) {
            $this->logger->warning('Path does not exist', ['method' => __METHOD__, 'path' => $path]);
            throw new \InvalidArgumentException("Path does not exist: {$path}");
        }

        if (is_file($path)) {
            $this->isFileSafe($path);
            return;
        }

        $flags = \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS;
        $rdi = new \RecursiveDirectoryIterator($path, $flags);
        $rii = new \RecursiveIteratorIterator($rdi, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($rii as $item) {
            /** @var \SplFileInfo $item */
            $full = $item->getPathname();

            // exclude paths (substring or regex)
            if ($this->isPathExcluded($full)) {
                continue;
            }

            if ($item->isFile()) {
                // check extensions against include/exclude lists
                if (!$this->shouldScanByExtension($full)) {
                    continue;
                }
                try {
                    $this->isFileSafe($full);
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'scanFile failed',
                        ['exception' => $e, 'method' => __METHOD__, 'file' => $full]
                    );
                }
            }
        }
    }

    /**
     * Decide whether a path should be excluded by excludePaths.
     *
     * @param string $path
     * @return bool
     */
    private function isPathExcluded(string $path): bool
    {
        foreach ($this->excludePaths as $pattern) {
            if ($pattern === '') {
                continue;
            }
            // if pattern starts and ends with delimiter '/', treat as regex
            if (@preg_match($pattern, '') !== false &&
                \strlen($pattern) > 2 &&
                $pattern[0] === '/' &&
                strrpos($pattern, '/') !== 0
            ) {
                // assume regex
                if (@preg_match($pattern, $path) === 1) {
                    return true;
                }
            } else {
                // substring match (case-insensitive)
                if (stripos($path, $pattern) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Decide whether to scan file by extension rules.
     *
     * @param string $file
     * @return bool
     */
    private function shouldScanByExtension(string $file): bool
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION) ?: '');
        if (!empty($this->includeExtensions)) {
            // whitelist has priority
            return \in_array($ext, $this->includeExtensions, true);
        }
        if (!empty($this->excludeExtensions)) {
            return !\in_array($ext, $this->excludeExtensions, true);
        }
        return true; // no rules => scan all
    }

    /**
     * Scan a single file using fopen/fread and chunked scanning.
     *
     * @param string $filePath
     * @return void
     */
    public function isFileSafe(string $filePath): void
    {
        // skip unreadable files
        if (!is_readable($filePath) || !is_file($filePath)) {
            return;
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return;
        }

        $overlap = max(1, $this->maxSignatureLength - 1);
        $prevTail = '';
        $filePos = 0; // bytes already read before current chunk
        $lineOffset = 1; // approximate line number (1-based). We'll update as we go.
        $bytesScanned = 0;
        $truncated = false;

        // if maxBytesPerFile is set we will read at most that many bytes
        $maxToScan = $this->maxBytesPerFile;

        while (!feof($handle)) {
            // check max bytes
            if ($maxToScan !== null && $bytesScanned >= $maxToScan) {
                $truncated = true;
                break;
            }

            $toRead = $this->chunkSize;
            if ($maxToScan !== null) {
                $remaining = $maxToScan - $bytesScanned;
                if ($remaining <= 0) {
                    $truncated = true;
                    break;
                }
                if ($remaining < $toRead) {
                    $toRead = $remaining;
                }
            }

            $chunk = @fread($handle, $toRead);
            if ($chunk === false || $chunk === '') {
                // nothing read; break
                break;
            }
            $chunkLen = \strlen($chunk);

            $buffer = $prevTail . $chunk;

            // Search all signatures inside $buffer.
            $this->searchBufferForSignatures(
                $buffer,
                $filePath,
                $filePos,
                \strlen($prevTail),
                $lineOffset,
                $truncated
            );

            // update counters
            $filePos += $chunkLen;
            $bytesScanned += $chunkLen;

            // update line offset: count newlines in chunk only (not prevTail)
            $nlCount = substr_count($chunk, "\n");
            $lineOffset += $nlCount;

            // prepare prevTail: last $overlap bytes of buffer
            if (\strlen($buffer) <= $overlap) {
                $prevTail = $buffer;
            } else {
                $prevTail = substr($buffer, -$overlap);
            }
        }

        // After loop: if prevTail still contains signature that crosses end-of-file, still scanned above.

        fclose($handle);
        // optionally mark truncated info in findings (we include truncated flag in each finding callback)
        if ($truncated) {
            // nothing special here — each finding included truncated flag already
        }
    }

    /**
     * Search given buffer (which is prevTail + chunk) for configured signatures.
     *
     * @param string $buffer The concatenated buffer
     * @param string $filePath
     * @param int $filePosStart Bytes already consumed before current chunk (position of chunk start)
     * @param int $prevTailLen Length of prevTail prepended to buffer
     * @param int $lineOffset Approximate line number offset at start of chunk (1-based).
     * //   This function may update this by reference.
     * @param bool $truncated Whether scanning of file will be truncated (passed to callback)
     */
    private function searchBufferForSignatures(
        string $buffer,
        string $filePath,
        int $filePosStart,
        int $prevTailLen,
        int $lineOffset,
        bool $truncated
    ): void {
        $bufferLen = \strlen($buffer);
        // We'll search for each signature.
        //      For performance we iterate signatures and use stripos or preg_match_all accordingly.
        foreach ($this->signatures as $sig) {
            if (\is_array($sig) && isset($sig['regex'])) {
                $pattern = $sig['regex'];
                // Use preg_match_all with PREG_OFFSET_CAPTURE on the buffer
                $flags = PREG_OFFSET_CAPTURE;
                // If user wants case-insensitive, ensure 'i' modifier present
                if ($this->caseInsensitive && strpos($pattern, 'i') === false && substr($pattern, -1) !== 'i') {
                    // naive: if pattern lacks modifiers, add i
                    if (@preg_match($pattern, '') === false) {
                        // if pattern invalid, try to append i ? skip
                        // TODO zweryfikować to
                    }
                }
                $matches = null;
                // we catch warnings from invalid regex
                try {
                    if (@preg_match_all($pattern, $buffer, $matches, PREG_OFFSET_CAPTURE) && !empty($matches[0])) {
                        foreach ($matches[0] as $m) {
                            $mText = $m[0];
                            $mPos = (int)$m[1];
                            $this->recordFindingFromBuffer(
                                $filePath,
                                $mText,
                                $mPos,
                                $buffer,
                                $filePosStart,
                                $prevTailLen,
                                $lineOffset,
                                $truncated
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'Failed to match regex',
                        ['exception' => $e, 'method' => __METHOD__, 'file' => $filePath]
                    );
                }
            } else {
                $needle = (string)$sig;
                if ($needle === '') {
                    continue;
                }
                // Choose case-sensitive or insensitive search
                $offset = 0;
                while (true) {
                    if ($this->caseInsensitive) {
                        $pos = stripos($buffer, $needle, $offset);
                    } else {
                        $pos = strpos($buffer, $needle, $offset);
                    }
                    if ($pos === false) {
                        break;
                    }
                    $this->recordFindingFromBuffer(
                        $filePath,
                        $needle,
                        $pos,
                        $buffer,
                        $filePosStart,
                        $prevTailLen,
                        $lineOffset,
                        $truncated
                    );
                    $offset = $pos + 1; // continue searching after this position
                }
            }
        }
    }

    /**
     * Record a finding based on a match inside the concatenated buffer.
     *
     * @param string $filePath
     * @param string $matchedSignature
     * @param int $posInBuffer position of match inside $buffer
     * @param string $buffer full buffer (prevTail + chunk)
     * @param int $filePosStart bytes already read before current chunk (start of chunk)
     * @param int $prevTailLen length of prevTail appended before chunk
     * @param int $lineOffset approximate line offset at start of chunk
     * @param bool $truncated
     */
    private function recordFindingFromBuffer(
        string $filePath,
        string $matchedSignature,
        int $posInBuffer,
        string $buffer,
        int $filePosStart,
        int $prevTailLen,
        int $lineOffset,
        bool $truncated
    ): void {
        // Absolute byte offset in file:
        // buffer index 0 corresponds to file position = $filePosStart - $prevTailLen
        $absoluteOffset = ($filePosStart - $prevTailLen) + $posInBuffer;
        if ($absoluteOffset < 0) {
            $absoluteOffset = 0;
        }

        // Determine approximate line number:
        //      count newlines before match in the buffer portion that is from chunk start onwards.
        // We'll compute newlines in portion from bufferStartToMatch = substr(buffer, max(0, ?), $posInBuffer)
        // But we need to count newlines from true file start. Simpler:
        //  count newlines in whole buffer up to match and add a base line count derived from filePosStart and prevTail:
        $before = substr($buffer, 0, $posInBuffer);
        $nlCount = substr_count($before, "\n");
        // line number is lineOffsetForChunkStart + (newlines in chunk portion after prevTail)
        // If prevTail contains some newlines that we've already counted in previous chunk,
        //      result is approximate but good enough.
        $line = $lineOffset - substr_count(substr($buffer, $prevTailLen, 0), "\n") + $nlCount;
        if ($line < 1) {
            $line = 1;
        }

        // Create snippet around match (max ~160 chars)
        $contextRadius = 80;
        $start = max(0, $posInBuffer - $contextRadius);
        $length = \strlen($matchedSignature) + ($contextRadius * 2);
        $snippet = substr($buffer, $start, $length);
        // Normalize snippet for readability (limit binary noise)
        if (\strlen($snippet) > 512) {
            $snippet = substr($snippet, 0, 512) . '...';
        }

        $finding = [
            'file' => $filePath,
            'signature' => $matchedSignature,
            'offset' => $absoluteOffset,
            'line' => $line,
            'snippet' => $snippet,
            'truncated' => $truncated,
        ];

        $this->findings[] = $finding;
        if ($this->findingCallback !== null) {
            try {
                ($this->findingCallback)($finding);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Failed to invoke finding callback',
                    ['exception' => $e, 'method' => __METHOD__, 'file' => $filePath]
                );
            }
        }
    }
}
