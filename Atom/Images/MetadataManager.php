<?php

declare(strict_types=1);

namespace Atom\Images;

use Imagick;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Image EXIF / IPTC / XMP metadata reader, stripper, and exporter.
 * Uses runtime/temp for temporary JSON files and avoids @ error suppression.
 */
final class MetadataManager
{
    private LoggerInterface $logger;
    private bool $hasImagick;
    private ?string $exiftoolPath;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->hasImagick = \extension_loaded('imagick') && \class_exists(Imagick::class);
        $this->exiftoolPath = $this->locateExiftool();
    }

    private function locateExiftool(): ?string
    {
        $pathEnv = \getenv('PATH');
        if (!\is_string($pathEnv) || $pathEnv === '') {
            return null;
        }

        $isWindows = \stripos(\PHP_OS_FAMILY, 'Windows') !== false;
        $candidates = $isWindows ? ['exiftool.exe', 'exiftool'] : ['exiftool'];
        $dirs = \explode(\PATH_SEPARATOR, $pathEnv);

        foreach ($dirs as $dir) {
            $trimmedDir = \rtrim($dir, '/\\');
            if ($trimmedDir === '') {
                continue;
            }
            foreach ($candidates as $bin) {
                $full = $trimmedDir . \DIRECTORY_SEPARATOR . $bin;
                if (\is_file($full) && ($isWindows || \is_executable($full))) {
                    return $full;
                }
            }
        }

        return null;
    }

    public function isImagickAvailable(): bool
    {
        return $this->hasImagick;
    }

    public function isExiftoolAvailable(): bool
    {
        return $this->exiftoolPath !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function readExif(string $filepath): array
    {
        if (!\is_file($filepath)) {
            $this->logger->warning('readExif: file not found', ['file' => $filepath]);
            return [];
        }

        if (!\function_exists('exif_read_data')) {
            $this->logger->warning('exif_read_data not available (exif extension missing)');
            return [];
        }

        try {
            $data = $this->callQuietly(static fn(): array|false => \exif_read_data($filepath, 'ANY_TAG', true, false));
            return \is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            $this->logger->warning('readExif failed', [
                'exception' => $e->getMessage(),
                'method' => __METHOD__,
                'file' => $filepath,
            ]);
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function readIptc(string $filepath): array
    {
        if (!\is_file($filepath)) {
            $this->logger->warning('readIptc: file not found', ['method' => __METHOD__, 'file' => $filepath]);
            return [];
        }

        $info = [];
        $size = $this->callQuietly(static function () use ($filepath, &$info): array|false {
            return \getimagesize($filepath, $info);
        });

        if ($size === false || empty($info['APP13']) || !\is_string($info['APP13'])) {
            return [];
        }

        $app13 = $info['APP13'];
        $iptc = $this->callQuietly(static fn(): array|false => \iptcparse($app13));
        return \is_array($iptc) ? $iptc : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function readXmp(string $filepath): array
    {
        if (!\is_file($filepath)) {
            $this->logger->warning('readXmp: file not found', ['method' => __METHOD__, 'file' => $filepath]);
            return [];
        }

        $contents = $this->callQuietly(static fn(): string|false => \file_get_contents($filepath));
        if ($contents === false) {
            return [];
        }

        if (\preg_match('/<\s*x:xmpmeta\b.*?>.*?<\s*\/\s*x:xmpmeta>/si', $contents, $m) === 1) {
            $xmp = $m[0];
        } elseif (\preg_match('/<\s*rdf:RDF\b.*?>.*?<\s*\/\s*rdf:RDF>/si', $contents, $m) === 1) {
            $xmp = $m[0];
        } else {
            return [];
        }

        $prevErrors = \libxml_use_internal_errors(true);
        try {
            $xml = \simplexml_load_string($xmp, 'SimpleXMLElement', \LIBXML_NONET);
            if ($xml === false) {
                $this->logger->debug('readXmp: simplexml failed to parse XMP');
                return ['raw' => $xmp];
            }

            $encoded = \json_encode($xml);
            $decoded = $encoded !== false ? \json_decode($encoded, true) : null;
            return \is_array($decoded) ? $decoded : ['raw' => $xmp];
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($prevErrors);
        }
    }

    /**
     * @return array{exif: array<string, mixed>, iptc: array<string, mixed>, xmp: array<string, mixed>}
     */
    public function getAllMetadata(string $filepath): array
    {
        return [
            'exif' => $this->readExif($filepath),
            'iptc' => $this->readIptc($filepath),
            'xmp' => $this->readXmp($filepath),
        ];
    }

    public function countMetadataKeys(string $filepath): int
    {
        $cnt = 0;
        foreach ($this->getAllMetadata($filepath) as $group) {
            $cnt += $this->recursiveCount($group);
        }

        return $cnt;
    }

    /**
     * @param array<mixed, mixed> $arr
     */
    private function recursiveCount(array $arr): int
    {
        $c = 0;
        foreach ($arr as $v) {
            $c++;
            if (\is_array($v)) {
                $c += $this->recursiveCount($v);
            }
        }

        return $c;
    }

    public function stripAllMetadata(string $input, ?string $output = null, bool $createBackup = false): bool
    {
        if (!\is_file($input)) {
            $this->logger->warning('stripAllMetadata: input missing or unreadable', [
                'method' => __METHOD__,
                'file' => $input,
            ]);
            return false;
        }

        if ($output === null) {
            $output = $input;
            if ($createBackup) {
                $this->backupFile($input);
            }
        }

        if ($this->hasImagick) {
            $im = null;
            try {
                $im = new Imagick($input);
                $im->stripImage();
                $im->writeImage($output);
                $this->logger->info('stripAllMetadata: Imagick stripImage used', [
                    'method' => __METHOD__,
                    'file' => $input,
                    'out' => $output,
                ]);
                return true;
            } catch (\Throwable $e) {
                $this->logger->warning('stripAllMetadata: Imagick failed, falling back to GD', [
                    'exception' => $e->getMessage(),
                    'method' => __METHOD__,
                    'file' => $input,
                ]);
            } finally {
                if ($im instanceof Imagick) {
                    $im->clear();
                    $im->destroy();
                }
            }
        }

        $ext = \strtolower(\pathinfo($input, \PATHINFO_EXTENSION));
        try {
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    $img = $this->callQuietly(static fn() => \imagecreatefromjpeg($input));
                    if ($img === false) {
                        throw new \RuntimeException('GD: imagecreatefromjpeg failed');
                    }
                    \imagejpeg($img, $output, 90);
                    break;
                case 'png':
                    $img = $this->callQuietly(static fn() => \imagecreatefrompng($input));
                    if ($img === false) {
                        throw new \RuntimeException('GD: imagecreatefrompng failed');
                    }
                    \imagepng($img, $output);
                    break;
                case 'gif':
                    $img = $this->callQuietly(static fn() => \imagecreatefromgif($input));
                    if ($img === false) {
                        throw new \RuntimeException('GD: imagecreatefromgif failed');
                    }
                    \imagegif($img, $output);
                    break;
                default:
                    if ($input !== $output && !\copy($input, $output)) {
                        return false;
                    }
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('stripAllMetadata failed', [
                'exception' => $e->getMessage(),
                'method' => __METHOD__,
                'file' => $input,
            ]);
            return false;
        }
    }

    /**
     * @param list<string>|null $additionalTags
     */
    public function removeSensitiveMetadata(
        string $input,
        ?string $output = null,
        ?array $additionalTags = null,
    ): bool {
        if (!\is_file($input)) {
            $this->logger->warning('removeSensitiveMetadata: file missing', ['method' => __METHOD__, 'file' => $input]);
            return false;
        }

        $sensitive = [
            'GPS*',
            'Exif:DateTimeOriginal',
            'Exif:CreateDate',
            'XMP:xmpMM:DocumentID',
            'XMP:CreateDate',
            'XMP:CreatorTool',
            'XMP:MetadataDate',
            'EXIF:ImageUniqueID',
            'EXIF:OwnerName',
            'MakerNotes*',
            'CameraSerialNumber',
            'SerialNumber',
            'Artist',
            'By-line',
            'By-lineTitle',
            'Credit',
            'Source',
            'Creator',
            'Rights',
        ];

        if ($additionalTags !== null && $additionalTags !== []) {
            $sensitive = [...$sensitive, ...$additionalTags];
        }

        if ($this->isExiftoolAvailable() && $this->exiftoolPath !== null) {
            $cmdParts = [];
            foreach ($sensitive as $tag) {
                $cleanTag = \preg_replace('/[^A-Za-z0-9:*_\-]/', '', $tag) ?? '';
                if ($cleanTag !== '') {
                    $cmdParts[] = $this->escapeShellArg('-' . $cleanTag . '=');
                }
            }

            if ($output === null) {
                $this->backupFile($input);
                $target = $input;
            } else {
                if (!\copy($input, $output)) {
                    return false;
                }
                $target = $output;
            }

            $cmd = \sprintf(
                '%s %s -overwrite_original %s 2>&1',
                \escapeshellarg($this->exiftoolPath),
                \implode(' ', $cmdParts),
                $this->escapeShellArg($target),
            );

            $out = [];
            $rc = 1;
            \exec($cmd, $out, $rc);
            return $rc === 0;
        }

        return $this->stripAllMetadata($input, $output, true);
    }

    /**
     * @param array<string, string|list<string>> $iptc
     */
    public function addIptcTags(string $inputJpeg, array $iptc, ?string $output = null): bool
    {
        if (!\is_file($inputJpeg)) {
            return false;
        }

        $ext = \strtolower(\pathinfo($inputJpeg, \PATHINFO_EXTENSION));
        if (!\in_array($ext, ['jpg', 'jpeg'], true)) {
            return false;
        }

        $map = [
            'Byline' => '2#080',
            'Caption' => '2#120',
            'Keywords' => '2#025',
            'Credit' => '2#110',
            'Source' => '2#115',
        ];

        $iptcData = [];
        foreach ($iptc as $key => $val) {
            $keyStr = (string) $key;
            $tag = \preg_match('/^\d#\d{3}$/', $keyStr) === 1 ? $keyStr : ($map[$keyStr] ?? null);
            if ($tag === null) {
                continue;
            }
            $iptcData[$tag] = \is_array($val) ? $val : [$val];
        }

        $iptcBinary = '';
        foreach ($iptcData as $tag => $values) {
            [$recStr, $datStr] = \explode('#', $tag, 2);
            $record = (int) $recStr;
            $dataset = (int) $datStr;
            foreach ($values as $value) {
                $data = \mb_convert_encoding((string) $value, 'UTF-8');
                $iptcBinary .= $this->iptcMakeTag($record, $dataset, $data);
            }
        }

        $newContent = \iptcembed($iptcBinary, $inputJpeg, 0);
        if (!\is_string($newContent)) {
            return false;
        }

        $out = $output ?? $inputJpeg;
        return \file_put_contents($out, $newContent) !== false;
    }

    private function iptcMakeTag(int $rec, int $dat, string $value): string
    {
        $len = \strlen($value);
        $retval = \chr(0x1C) . \chr($rec) . \chr($dat);
        if ($len < 0x8000) {
            $retval .= \chr(($len >> 8) & 0xff) . \chr($len & 0xff);
        } else {
            $retval .= \chr(0x80)
                . \chr(0x04)
                . \chr(($len >> 24) & 0xff)
                . \chr(($len >> 16) & 0xff)
                . \chr(($len >> 8) & 0xff)
                . \chr($len & 0xff);
        }

        return $retval . $value;
    }

    public function addXmpTag(string $inputJpeg, string $tagName, string $tagValue, ?string $output = null): bool
    {
        if (!\is_file($inputJpeg)) {
            return false;
        }

        $ext = \strtolower(\pathinfo($inputJpeg, \PATHINFO_EXTENSION));
        if (!\in_array($ext, ['jpg', 'jpeg'], true)) {
            return false;
        }

        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*$/', $tagName) !== 1) {
            throw new \InvalidArgumentException("Invalid XMP XML tag name: {$tagName}");
        }

        $contents = \file_get_contents($inputJpeg);
        if ($contents === false || !\str_starts_with($contents, "\xFF\xD8")) {
            return false;
        }

        $escapedValue = \htmlspecialchars($tagValue, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
        $xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:custom="http://example.com/custom/">'
            . '<custom:' . $tagName . '>' . $escapedValue . '</custom:' . $tagName . '>'
            . '</rdf:Description>'
            . '</rdf:RDF>'
            . '</x:xmpmeta>'
            . '<?xpacket end="w"?>';

        $updated = \preg_match('/<\?xpacket begin=.*?\?>.*?<\?xpacket end=.*?\?>/si', $contents) === 1
            ? (\preg_replace('/<\?xpacket begin=.*?\?>.*?<\?xpacket end=.*?\?>/si', $xmp, $contents, 1) ?? $contents)
            : (\substr($contents, 0, 2) . $this->packApp1Xmp($xmp) . \substr($contents, 2));

        $out = $output ?? $inputJpeg;
        return \file_put_contents($out, $updated) !== false;
    }

    private function packApp1Xmp(string $xmp): string
    {
        $payload = "http://ns.adobe.com/xap/1.0/\x00" . $xmp;
        $len = \strlen($payload) + 2;
        return "\xFF\xE1" . \chr(($len >> 8) & 0xFF) . \chr($len & 0xFF) . $payload;
    }

    public function exportMetadataJson(string $inputFile): ?string
    {
        $json = \json_encode($this->getAllMetadata($inputFile), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : null;
    }

    public function importMetadataJson(string $inputFile, string $json, ?string $output = null): bool
    {
        if (!$this->isExiftoolAvailable() || $this->exiftoolPath === null) {
            return false;
        }

        $data = \json_decode($json, true);
        if (!\is_array($data)) {
            return false;
        }

        $runtimeTempDir = \dirname(__DIR__, 2) . \DIRECTORY_SEPARATOR . 'runtime' . \DIRECTORY_SEPARATOR . 'temp';
        if (!\is_dir($runtimeTempDir) && !\mkdir($runtimeTempDir, 0775, true) && !\is_dir($runtimeTempDir)) {
            return false;
        }

        $tmp = $runtimeTempDir . \DIRECTORY_SEPARATOR . 'metajson_' . \bin2hex(\random_bytes(8)) . '.json';
        if (\file_put_contents($tmp, $json, \LOCK_EX) === false) {
            return false;
        }

        try {
            $args = [];
            foreach (['iptc', 'exif', 'xmp'] as $group) {
                if (empty($data[$group]) || !\is_array($data[$group])) {
                    continue;
                }
                $flat = $this->flattenArrayWithPrefix($data[$group], $group);
                foreach ($flat as $tag => $val) {
                    $cleanTag = \preg_replace('/[^A-Za-z0-9:._\-]/', '', (string) $tag) ?? '';
                    if ($cleanTag !== '') {
                        $args[] = $this->escapeShellArg(\sprintf('-%s=%s', $cleanTag, (string) $val));
                    }
                }
            }

            $target = $output ?? $inputFile;
            if ($output !== null && !\copy($inputFile, $output)) {
                return false;
            }

            $cmd = \sprintf(
                '%s -overwrite_original %s %s 2>&1',
                \escapeshellarg($this->exiftoolPath),
                \implode(' ', $args),
                $this->escapeShellArg($target),
            );
            $out = [];
            $rc = 1;
            \exec($cmd, $out, $rc);
            return $rc === 0;
        } finally {
            if (\is_file($tmp)) {
                $this->callQuietly(static fn(): bool => \unlink($tmp));
            }
        }
    }

    /**
     * @param array<string|int, mixed> $arr
     * @return array<string, mixed>
     */
    private function flattenArrayWithPrefix(array $arr, string $prefix, string $parentKey = ''): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $key = $parentKey === '' ? ($prefix . ':' . $k) : ($parentKey . '.' . $k);
            if (\is_array($v)) {
                $out = \array_merge($out, $this->flattenArrayWithPrefix($v, $prefix, $key));
            } else {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    private function escapeShellArg(string $s): string
    {
        return \escapeshellarg($s);
    }

    public function backupFile(string $file, ?string $destDir = null): ?string
    {
        if (!\is_file($file)) {
            return null;
        }

        $dest = $destDir !== null
            ? \rtrim($destDir, '/\\') . \DIRECTORY_SEPARATOR . \basename($file)
            : $file . '.bak';

        return \copy($file, $dest) ? $dest : null;
    }

    private function callQuietly(callable $fn): mixed
    {
        \set_error_handler(static fn(): bool => true);
        try {
            return $fn();
        } finally {
            \restore_error_handler();
        }
    }
}
