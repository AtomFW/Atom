<?php

declare(strict_types=1);

namespace Atom\Security;

use FilesystemIterator;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Constant-memory streaming file scanner (`fopen`/`fread` with `try/finally` handle safety and PHP 8.6 `clamp()`).
 */
final class FileSafetyScanner
{
    private int $chunkSize;
    private ?int $maxBytesPerFile;

    /** @var list<string> */
    private array $includeExtensions;

    /** @var list<string> */
    private array $excludeExtensions;

    /** @var list<string> */
    private array $excludePaths;

    private LoggerInterface $logger;

    /** @var array<int, string|array{regex?: string}> */
    private array $signatures;

    private bool $caseInsensitive;

    /** @var callable|null */
    private $findingCallback = null;

    /** @var list<array<string, mixed>> */
    private array $findings = [];

    private int $maxSignatureLength = 1;

    /**
     * @param array{
     *   chunkSize?: int,
     *   maxBytesPerFile?: int|null,
     *   includeExtensions?: list<string>,
     *   excludeExtensions?: list<string>,
     *   excludePaths?: list<string>,
     *   signatures?: array<int, string|array{regex?: string}>,
     *   caseInsensitive?: bool,
     * } $options
     */
    public function __construct(array $options = [], ?LoggerInterface $logger = null)
    {
        $rawChunkSize = (int) ($options['chunkSize'] ?? (8 * 1024 * 1024));
        $this->chunkSize = \clamp($rawChunkSize, 64, 64 * 1024 * 1024);
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
     * @param array<int, string> $exts
     * @return list<string>
     */
    private function normalizeExtensions(array $exts): array
    {
        $out = [];
        foreach ($exts as $e) {
            $clean = \ltrim((string) $e, '. ');
            if ($clean !== '') {
                $out[\strtolower($clean)] = true;
            }
        }

        return \array_keys($out);
    }

    /**
     * @return list<string>
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
            '`',
        ];
    }

    private function computeMaxSignatureLength(): void
    {
        $max = 1;
        foreach ($this->signatures as $s) {
            $len = (\is_array($s) && isset($s['regex'])) ? 256 : \strlen((string) $s);
            if ($len > $max) {
                $max = $len;
            }
        }

        $this->maxSignatureLength = $max;
    }

    public function setFindingCallback(callable $cb): void
    {
        $this->findingCallback = $cb;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFindings(): array
    {
        return $this->findings;
    }

    public function clearFindings(): void
    {
        $this->findings = [];
    }

    public function isSafe(string $path): void
    {
        if (!\file_exists($path)) {
            $this->logger->warning('Path does not exist', ['method' => __METHOD__, 'path' => $path]);
            throw new InvalidArgumentException("Path does not exist: {$path}");
        }

        if (\is_file($path)) {
            $this->isFileSafe($path);
            return;
        }

        $flags = FilesystemIterator::SKIP_DOTS;
        $rdi = new RecursiveDirectoryIterator($path, $flags);
        $rii = new RecursiveIteratorIterator($rdi, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($rii as $item) {
            /** @var SplFileInfo $item */
            $full = $item->getPathname();

            if ($this->isPathExcluded($full)) {
                continue;
            }

            if ($item->isFile() && $this->shouldScanByExtension($full)) {
                try {
                    $this->isFileSafe($full);
                } catch (Throwable $e) {
                    $this->logger->error(
                        'scanFile failed',
                        ['exception' => $e, 'method' => __METHOD__, 'file' => $full]
                    );
                }
            }
        }
    }

    private function isPathExcluded(string $path): bool
    {
        foreach ($this->excludePaths as $pattern) {
            if ($pattern === '') {
                continue;
            }

            if (
                \strlen($pattern) > 2
                && $pattern[0] === '/'
                && \strrpos($pattern, '/') !== 0
                && self::isValidRegex($pattern)
            ) {
                if (\preg_match($pattern, $path) === 1) {
                    return true;
                }
            } elseif (\stripos($path, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function isValidRegex(string $pattern): bool
    {
        \set_error_handler(static fn(): bool => true);
        try {
            return \preg_match($pattern, '') !== false;
        } finally {
            \restore_error_handler();
        }
    }

    private function shouldScanByExtension(string $file): bool
    {
        $ext = \strtolower((string) \pathinfo($file, \PATHINFO_EXTENSION));
        if ($this->includeExtensions !== []) {
            return \in_array($ext, $this->includeExtensions, true);
        }
        if ($this->excludeExtensions !== []) {
            return !\in_array($ext, $this->excludeExtensions, true);
        }

        return true;
    }

    public function isFileSafe(string $filePath): void
    {
        if (!\is_file($filePath)) {
            return;
        }

        \set_error_handler(static fn(): bool => true);
        try {
            $handle = \fopen($filePath, 'rb');
        } finally {
            \restore_error_handler();
        }

        if ($handle === false) {
            return;
        }

        try {
            $overlap = \max(1, $this->maxSignatureLength - 1);
            $prevTail = '';
            $filePos = 0;
            $lineOffset = 1;
            $bytesScanned = 0;
            $truncated = false;
            $maxToScan = $this->maxBytesPerFile;

            while (!\feof($handle)) {
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

                $chunk = \fread($handle, $toRead);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $chunkLen = \strlen($chunk);
                $buffer = $prevTail . $chunk;

                $this->searchBufferForSignatures(
                    $buffer,
                    $filePath,
                    $filePos,
                    \strlen($prevTail),
                    $lineOffset,
                    $truncated,
                );

                $filePos += $chunkLen;
                $bytesScanned += $chunkLen;
                $lineOffset += \substr_count($chunk, "\n");

                $prevTail = \strlen($buffer) <= $overlap
                    ? $buffer
                    : \substr($buffer, -$overlap);
            }
        } finally {
            \fclose($handle);
        }
    }

    private function searchBufferForSignatures(
        string $buffer,
        string $filePath,
        int $filePosStart,
        int $prevTailLen,
        int $lineOffset,
        bool $truncated,
    ): void {
        foreach ($this->signatures as $sig) {
            if (\is_array($sig) && isset($sig['regex'])) {
                $pattern = (string) $sig['regex'];
                if (!self::isValidRegex($pattern)) {
                    continue;
                }

                try {
                    $matches = [];
                    if (\preg_match_all($pattern, $buffer, $matches, \PREG_OFFSET_CAPTURE) && !empty($matches[0])) {
                        foreach ($matches[0] as $m) {
                            $this->recordFindingFromBuffer(
                                $filePath,
                                (string) $m[0],
                                (int) $m[1],
                                $buffer,
                                $filePosStart,
                                $prevTailLen,
                                $lineOffset,
                                $truncated,
                            );
                        }
                    }
                } catch (Throwable $e) {
                    $this->logger->error(
                        'Failed to match regex',
                        ['exception' => $e, 'method' => __METHOD__, 'file' => $filePath]
                    );
                }
            } else {
                $needle = (string) $sig;
                if ($needle === '') {
                    continue;
                }

                $offset = 0;
                while (true) {
                    $pos = $this->caseInsensitive
                        ? \stripos($buffer, $needle, $offset)
                        : \strpos($buffer, $needle, $offset);

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
                        $truncated,
                    );
                    $offset = $pos + 1;
                }
            }
        }
    }

    private function recordFindingFromBuffer(
        string $filePath,
        string $matchedSignature,
        int $posInBuffer,
        string $buffer,
        int $filePosStart,
        int $prevTailLen,
        int $lineOffset,
        bool $truncated,
    ): void {
        $absoluteOffset = \max(0, ($filePosStart - $prevTailLen) + $posInBuffer);

        $before = \substr($buffer, 0, $posInBuffer);
        $prevTailNewlines = $prevTailLen > 0 ? \substr_count(\substr($buffer, 0, $prevTailLen), "\n") : 0;
        $line = \max(1, $lineOffset - $prevTailNewlines + \substr_count($before, "\n"));

        $contextRadius = 80;
        $start = \max(0, $posInBuffer - $contextRadius);
        $length = \strlen($matchedSignature) + ($contextRadius * 2);
        $snippet = \substr($buffer, $start, $length);
        if (\strlen($snippet) > 512) {
            $snippet = \substr($snippet, 0, 512) . '...';
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
            } catch (Throwable $e) {
                $this->logger->error(
                    'Failed to invoke finding callback',
                    ['exception' => $e, 'method' => __METHOD__, 'file' => $filePath]
                );
            }
        }
    }
}
