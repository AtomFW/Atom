<?php

declare(strict_types=1);

namespace Atom\Images;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MetadataManager
 *
 * - Read EXIF / IPTC / XMP from images (JPEG, PNG).
 * - Strip all metadata (Imagick preferred, GD fallback).
 * - Remove sensitive/tracking metadata (uses exiftool if available; otherwise strips all).
 * - Add basic IPTC tags (JPEG), add simple XMP packet to JPEG (best-effort).
 * - Export/import metadata as JSON (import requires exiftool for fidelity).
 *
 */
final class MetadataManager
{
    private ?LoggerInterface $logger;
    private bool $hasImagick;
    private ?string $exiftoolPath;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->hasImagick = extension_loaded('imagick') && class_exists(\Imagick::class);
        $this->exiftoolPath = $this->locateExiftool();
    }

    // -----------------------------
    // Utility checks
    // -----------------------------
    private function locateExiftool(): ?string
    {
        // try "exiftool" in PATH (cross-platform)
        $paths = [];
        if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            // on Windows assume exiftool.exe in PATH or perl wrapper "exiftool.pl"
            $paths[] = 'exiftool.exe';
            $paths[] = 'exiftool';
        } else {
            $paths[] = 'exiftool';
        }

        foreach ($paths as $cmd) {
            $which = null;
            if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
                // try where.exe
                $out = [];
                @exec("where $cmd 2>NUL", $out, $rc);
                if ($rc === 0 && !empty($out[0])) {
                    return $out[0];
                }
            } else {
                $out = @shell_exec("command -v $cmd 2>/dev/null");
                if (!empty($out)) {
                    return trim($out);
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

    // -----------------------------
    // Reading metadata
    // -----------------------------

    /**
     * Read EXIF using PHP exif functions (works for files).
     * Returns associative array or empty array on failure.
     */
    public function readExif(string $filepath): array
    {
        if (!is_file($filepath)) {
            $this->logger->warning('readExif: file not found', ['file' => $filepath]);
            return [];
        }

        // exif_read_data only supports certain formats and requires exif extension
        if (!function_exists('exif_read_data')) {
            $this->logger->warning('exif_read_data not available (exif extension missing)');
            return [];
        }

        try {
            $data = @exif_read_data($filepath, 'ANY_TAG', true, false);
            return $data ?: [];
        } catch (\Throwable $e) {
            $this->logger->warning(
                'readExif failed',
                ['exception' => $e->getMessage(), 'method' => __METHOD__, 'file' => $filepath]
            );
            return [];
        }
    }

    /**
     * Read IPTC via getimagesize and iptcparse (JPEG only).
     */
    public function readIptc(string $filepath): array
    {
        if (!is_file($filepath)) {
            $this->logger->warning('readIptc: file not found', ['method' => __METHOD__, 'file' => $filepath]);
            return [];
        }

        $size = @getimagesize($filepath, $info);
        if ($size === false || empty($info['APP13'])) {
            return [];
        }

        $iptc = @iptcparse($info['APP13']);
        return $iptc ?: [];
    }

    /**
     * Read XMP packet (best-effort).
     * Returns associative array parsed from XMP RDF if available.
     */
    public function readXmp(string $filepath): array
    {
        if (!is_file($filepath)) {
            $this->logger->warning('readXmp: file not found', ['method' => __METHOD__, 'file' => $filepath]);
            return [];
        }

        $contents = @file_get_contents($filepath);
        if ($contents === false) {
            return [];
        }

        // Look for <x:xmpmeta ...> ... </x:xmpmeta>
        if (preg_match('/<\s*x:xmpmeta\b.*?>.*?<\s*\/\s*x:xmpmeta>/si', $contents, $m)) {
            $xmp = $m[0];
        } elseif (preg_match('/<\s*rdf:RDF\b.*?>.*?<\s*\/\s*rdf:RDF>/si', $contents, $m)) {
            $xmp = $m[0];
        } else {
            return [];
        }

        // Try to load as XML (best-effort)
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($xmp);
        if ($xml === false) {
            $this->logger->debug('readXmp: simplexml failed to parse XMP');
            return ['raw' => $xmp];
        }

        $json = json_decode(json_encode($xml), true);
        return $json ?? ['raw' => $xmp];
    }

    /**
     * Get all metadata combined.
     */
    public function getAllMetadata(string $filepath): array
    {
        return [
            'exif' => $this->readExif($filepath),
            'iptc' => $this->readIptc($filepath),
            'xmp'  => $this->readXmp($filepath),
        ];
    }

    // -----------------------------
    // Count metadata keys
    // -----------------------------
    public function countMetadataKeys(string $filepath): int
    {
        $all = $this->getAllMetadata($filepath);
        $cnt = 0;
        foreach ($all as $group) {
            if (is_array($group)) {
                $cnt += $this->recursiveCount($group);
            }
        }
        return $cnt;
    }

    private function recursiveCount(array $arr): int
    {
        $c = 0;
        foreach ($arr as $v) {
            $c++;
            if (is_array($v)) {
                $c += $this->recursiveCount($v);
            }
        }
        return $c;
    }

    // -----------------------------
    // Strip all metadata
    // -----------------------------
    /**
     * Strip ALL metadata (EXIF/IPTC/XMP). Preferred method: Imagick::stripImage()
     * If Imagick not available: re-save image via GD (JPEG/PNG) which drops metadata.
     *
     * @param string $input
     * @param string|null $output If null will overwrite input (safe: create backup first)
     * @param bool $createBackup create .bak copy before overwriting
     * @return bool
     */
    public function stripAllMetadata(string $input, ?string $output = null, bool $createBackup = false): bool
    {
        if (!is_file($input) || !is_readable($input)) {
            $this->logger->warning(
                'stripAllMetadata: input missing or unreadable',
                ['method' => __METHOD__, 'file' => $input]
            );
            return false;
        }

        if ($output === null) {
            $output = $input;
            if ($createBackup) {
                $bak = $input . '.bak';
                if (!copy($input, $bak)) {
                    $this->logger->warning(
                        'stripAllMetadata: could not create backup',
                        ['method' => __METHOD__, 'file' => $input, 'backup' => $bak]
                    );
                } else {
                    $this->logger->info(
                        'stripAllMetadata: backup created',
                        ['method' => __METHOD__, 'file' => $input, 'backup' => $bak]
                    );
                }
            }
        }

        if ($this->hasImagick) {
            try {
                $im = new \Imagick($input);
                // stripImage removes profiles and comments
                $im->stripImage();
                $im->writeImage($output);
                $im->clear();
                $im->destroy();
                $this->logger->info(
                    'stripAllMetadata: Imagick stripImage used',
                    ['method' => __METHOD__, 'file' => $input, 'out' => $output]
                );
                return true;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'stripAllMetadata: Imagick failed, falling back to GD',
                    ['exception' => $e->getMessage(), 'method' => __METHOD__, 'file' => $input]
                );
                // fallthrough to GD fallback
            }
        }

        // GD fallback - re-save image which generally removes metadata
        $ext = strtolower(pathinfo($input, PATHINFO_EXTENSION));
        try {
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    $img = @imagecreatefromjpeg($input);
                    if ($img === false) {
                        throw new \RuntimeException('GD: imagecreatefromjpeg failed');
                    }
                    imagejpeg($img, $output, 90);
                    $img = null;
                    break;
                case 'png':
                    $img = @imagecreatefrompng($input);
                    if ($img === false) {
                        throw new \RuntimeException('GD: imagecreatefrompng failed');
                    }
                    imagepng($img, $output);
                    $img = null;
                    break;
                case 'gif':
                    $img = @imagecreatefromgif($input);
                    if ($img === false) {
                        throw new \RuntimeException('GD: imagecreatefromgif failed');
                    }
                    imagegif($img, $output);
                    $img = null;
                    break;
                default:
                    // try to use file copy as fallback (no metadata editing possible)
                    if ($input !== $output) {
                        copy($input, $output);
                    }
                    $this->logger->warning(
                        'stripAllMetadata: unknown extension; fallback copy used',
                        ['method' => __METHOD__, 'file' => $input, 'ext' => $ext]
                    );
            }
            $this->logger->info(
                'stripAllMetadata: GD fallback used',
                ['method' => __METHOD__, 'file' => $input, 'out' => $output]
            );
            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                'stripAllMetadata failed',
                ['exception' => $e->getMessage(), 'method' => __METHOD__, 'file' => $input]
            );
            return false;
        }
    }

    // -----------------------------
    // Remove sensitive metadata (identifiers/tracking)
    // -----------------------------
    /**
     * Remove sensitive metadata used for identification/tracking.
     *
     * If exiftool is available we call it to delete specific tags:
     * (GPS, serials, unique ids, maker notes, artist, creator, device identifiers, xmpMM DocumentID, etc).
     * If exiftool not available we fall back to stripping all metadata (safe).
     *
     * @param string $input source file
     * @param string|null $output destination (if null overwrite input; a backup will be created)
     * @param array|null $additionalTags optional list of additional tags to remove (exiftool tag names)
     * @return bool
     */
    public function removeSensitiveMetadata(string $input, ?string $output = null, ?array $additionalTags = null): bool
    {
        if (!is_file($input)) {
            $this->logger->warning('removeSensitiveMetadata: file missing', ['method' => __METHOD__, 'file' => $input]);
            return false;
        }

        // canonical sensitive tags (exiftool-style)
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

        if (!empty($additionalTags)) {
            $sensitive = array_merge($sensitive, $additionalTags);
        }

        if ($this->isExiftoolAvailable()) {
            // build exiftool -overwrite_original -all= -tagsFromFile @ "-<TAG>" syntax is complex;
            //better to remove specific tags
            $cmdParts = [];
            foreach ($sensitive as $tag) {
                // exiftool remove by prefixing with "-" and tag name
                // To be safe wrap tag in quotes
                $cmdParts[] = '-' . $this->escapeShellArg($tag);
            }

            // ensure write to output or overwrite original
            if ($output === null) {
                // create backup
                $bak = $input . '.bak';
                if (!copy($input, $bak)) {
                    $this->logger->warning(
                        'removeSensitiveMetadata: failed backup',
                        ['method' => __METHOD__, 'file' => $input, 'backup' => $bak]
                    );
                } else {
                    $this->logger->info(
                        'removeSensitiveMetadata: backup created',
                        ['method' => __METHOD__, 'file' => $input, 'backup' => $bak]
                    );
                }
                $target = $input;
            } else {
                // copy to output first then operate on output
                if (!copy($input, $output)) {
                    $this->logger->error(
                        'removeSensitiveMetadata: cannot copy to output',
                        ['method' => __METHOD__, 'file' => $input, 'in' => $input, 'out' => $output]
                    );
                    return false;
                }
                $target = $output;
            }

            $cmd = sprintf(
                '%s %s -overwrite_original %s 2>&1',
                escapeshellcmd($this->exiftoolPath),
                implode(' ', $cmdParts),
                $this->escapeShellArg($target)
            );

            exec($cmd, $out, $rc);
            $this->logger->info(
                'removeSensitiveMetadata: exiftool executed',
                ['method' => __METHOD__, 'file' => $input, 'cmd' => $cmd, 'rc' => $rc, 'output' => $out]
            );
            return $rc === 0;
        }

        // exiftool not available -> strip all metadata (safe, but aggressive)
        $this->logger->info(
            'removeSensitiveMetadata: exiftool not available - falling back to stripAllMetadata (aggressive)',
            ['method' => __METHOD__, 'file' => $input]
        );
        return $this->stripAllMetadata($input, $output, true);
    }

    // -----------------------------
    // Add / update IPTC (JPEG)
    // -----------------------------
    /**
     * Add IPTC tags to JPEG using iptcembed (PHP native approach).
     * $iptc is assoc like ['2#005' => 'Byline', '2#025' => 'Keywords'] OR friendly keys -> we will map common names.
     *
     * NOTE: IPTC embedding works for JPEG only.
     */
    public function addIptcTags(string $inputJpeg, array $iptc, ?string $output = null): bool
    {
        if (!is_file($inputJpeg)) {
            $this->logger->warning(
                'addIptcTags: input missing',
                ['method' => __METHOD__, 'file' => $inputJpeg]
            );
            return false;
        }
        $ext = strtolower(pathinfo($inputJpeg, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg'])) {
            $this->logger->warning(
                'addIptcTags: IPTC embedding supported for JPEG only',
                ['method' => __METHOD__, 'file' => $inputJpeg, 'ext' => $ext]
            );
            return false;
        }

        // Build IPTC tags in php format
        $iptcData = [];
        foreach ($iptc as $key => $val) {
            // accept friendly names like 'Byline' => 'Name'
            if (preg_match('/^\d#\d{3}$/', (string)$key)) {
                $tag = $key;
            } else {
                // map some friendly names
                $map = [
                    'Byline' => '2#080',
                    'Caption' => '2#120',
                    'Keywords' => '2#025',
                    'Credit' => '2#110',
                    'Source' => '2#115',
                ];
                $tag = $map[$key] ?? null;
                if ($tag === null) {
                    $this->logger->warning(
                        'addIptcTags: unknown key, skipping',
                        ['method' => __METHOD__, 'file' => $inputJpeg, 'key' => $key]
                    );
                    continue;
                }
            }
            $iptcData[$tag] = is_array($val) ? $val : [$val];
        }

        $content = file_get_contents($inputJpeg);
        if ($content === false) {
            return false;
        }

        $iptcBinary = '';
        foreach ($iptcData as $tag => $values) {
            foreach ($values as $value) {
                $tagParts = explode('#', $tag);
                $record = (int)$tagParts[0];
                $dataset = (int)$tagParts[1];
                $data = mb_convert_encoding((string)$value, 'UTF-8');
                $iptcBinary .= $this->iptcMakeTag($record, $dataset, $data);
            }
        }

        $newContent = iptcembed($iptcBinary, $inputJpeg, 0);
        if ($newContent === false) {
            $this->logger->error(
                'addIptcTags: iptcembed failed',
                ['method' => __METHOD__, 'file' => $inputJpeg]
            );
            return false;
        }

        $out = $output ?? $inputJpeg;
        $res = file_put_contents($out, $newContent);
        if ($res === false) {
            $this->logger->error(
                'addIptcTags: save failed',
                ['method' => __METHOD__, 'file' => $inputJpeg, 'out' => $out]
            );
            return false;
        }

        $this->logger->info(
            'addIptcTags: embedded IPTC',
            ['method' => __METHOD__, 'file' => $inputJpeg, 'out' => $out]
        );
        return true;
    }

    // build IPTC tag binary
    private function iptcMakeTag(int $rec, int $dat, string $value): string
    {
        $len = \strlen($value);
        $retval = \chr(0x1C) . \chr($rec) . \chr($dat);
        if ($len < 0x8000) {
            $retval .= \chr(($len >> 8) & 0xff) . \chr($len & 0xff);
        } else {
            // large length (rare)
            $retval .=
                \chr(0x80) .
                \chr(0x04) .
                \chr(($len >> 24) & 0xff) .
                \chr(($len >> 16) & 0xff) .
                \chr(($len >> 8) & 0xff) .
                \chr($len & 0xff
            );
        }
        $retval .= $value;
        return $retval;
    }

    // -----------------------------
    // Add simple XMP into JPEG (best-effort)
    // -----------------------------
    /**
     * Inject a simple XMP packet into JPEG (if missing). Best-effort: not a full XMP editor.
     * Works only for JPEG files.
     */
    public function addXmpTag(string $inputJpeg, string $tagName, string $tagValue, ?string $output = null): bool
    {
        if (!is_file($inputJpeg)) {
            $this->logger->warning(
                'addXmpTag: input missing',
                ['method' => __METHOD__, 'file' => $inputJpeg]
            );
            return false;
        }
        $ext = strtolower(pathinfo($inputJpeg, PATHINFO_EXTENSION));
        if (!\in_array($ext, ['jpg','jpeg'])) {
            $this->logger->warning(
                'addXmpTag: XMP injection currently supported for JPEG only',
                ['method' => __METHOD__, 'file' => $inputJpeg, 'ext' => $ext]
            );
            return false;
        }

        $contents = file_get_contents($inputJpeg);
        if ($contents === false) {
            return false;
        }

        // build minimal XMP packet
        $xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:custom="http://example.com/custom/">'
            . '<custom:' .
                htmlspecialchars($tagName) .
            '>' .
                htmlspecialchars($tagValue) .
            '</custom:' .
                htmlspecialchars($tagName) .
            '>'
            . '</rdf:Description>'
            . '</rdf:RDF>'
            . '</x:xmpmeta>'
            . '<?xpacket end="w"?>';

        // Insert XMP after SOI marker (0xFFD8) but before first APP1/APPn
        $soi = "\xFF\xD8";
        if (substr($contents, 0, 2) !== $soi) {
            $this->logger->warning(
                'addXmpTag: not a JPEG file (missing SOI)',
                ['method' => __METHOD__, 'file' => $inputJpeg]
            );
            return false;
        }

        // If existing XMP exists, append in a naive way: remove existing and replace
        $contents = preg_match('/<\?xpacket begin=.*?\?>.*?<\?xpacket end=.*?\?>/si', $contents) ?
            preg_replace('/<\?xpacket begin=.*?\?>.*?<\?xpacket end=.*?\?>/si', $xmp, $contents, 1) :
            substr($contents, 0, 2) . $this->packApp1Xmp($xmp) . substr($contents, 2); // Insert after SOI

        $out = $output ?? $inputJpeg;
        $res = file_put_contents($out, $contents);
        if ($res === false) {
            $this->logger->error(
                'addXmpTag: write failed',
                ['method' => __METHOD__, 'file' => $inputJpeg, 'out' => $out]
            );
            return false;
        }

        $this->logger->info(
            'addXmpTag: injected (best-effort)',
            ['method' => __METHOD__, 'file' => $inputJpeg, 'out' => $out]
        );
        return true;
    }

    private function packApp1Xmp(string $xmp): string
    {
        // APP1 marker with XMP header "http://ns.adobe.com/xap/1.0/\0" + XMP packet
        $header = "http://ns.adobe.com/xap/1.0/\x00";
        $payload = $header . $xmp;
        $len = \strlen($payload) + 2; // length bytes include themselves
        return "\xFF\xE1" . \chr(($len >> 8) & 0xFF) . \chr($len & 0xFF) . $payload;
    }

    // -----------------------------
    // Export/Import metadata as JSON (import uses exiftool)
    // -----------------------------
    public function exportMetadataJson(string $inputFile): ?string
    {
        $data = $this->getAllMetadata($inputFile);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->logger->error(
                'exportMetadataJson: json_encode failed',
                ['method' => __METHOD__, 'file' => $inputFile]
            );
            return null;
        }
        return $json;
    }

    /**
     * Import metadata from JSON into file.
     * This requires exiftool for faithful import. If exiftool not available returns false.
     */
    public function importMetadataJson(string $inputFile, string $json, ?string $output = null): bool
    {
        if (!$this->isExiftoolAvailable()) {
            $this->logger->warning(
                'importMetadataJson: exiftool not available; cannot import JSON metadata',
                ['method' => __METHOD__, 'file' => $inputFile]
            );
            return false;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'metajson_');
        if ($tmp === false) {
            return false;
        }
        // save JSON to temp
        file_put_contents($tmp, $json);

        // exiftool can import from JSON: -json=FILE ??? exiftool supports -json<=file? We'll use -json=-
        // Simpler approach: parse JSON and run exiftool -<Tag>=value for each entry (best-effort)
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->logger->error(
                'importMetadataJson: json decode failed',
                ['method' => __METHOD__, 'file' => $inputFile]
            );
            @unlink($tmp);
            return false;
        }

        // Build exiftool args
        $args = [];
        foreach (['iptc','exif','xmp'] as $group) {
            if (empty($data[$group]) || !is_array($data[$group])) {
                continue;
            }
            // Flatten and create -TAG="value" arguments - this may be lossy for complex structures
            $flat = $this->flattenArrayWithPrefix($data[$group], $group);
            foreach ($flat as $tag => $val) {
                // exiftool tag names may require translation; we attempt direct mapping
                $args[] = $this->escapeShellArg(\sprintf('%s=%s', $tag, (string)$val));
            }
        }

        if ($output === null) {
            $output = $inputFile;
        } else {
            if (!copy($inputFile, $output)) {
                $this->logger->error(
                    'importMetadataJson: copy to output failed',
                    ['method' => __METHOD__, 'file' => $inputFile, 'output' => $output]
                );
                @unlink($tmp);
                return false;
            }
        }

        $cmd = \sprintf(
            '%s -overwrite_original %s %s 2>&1',
            escapeshellcmd($this->exiftoolPath),
            implode(' ', $args),
            $this->escapeShellArg($output)
        );
        exec($cmd, $out, $rc);
        @unlink($tmp);
        $this->logger->info(
            'importMetadataJson: exiftool executed',
            ['method' => __METHOD__, 'file' => $inputFile, 'cmd' => $cmd, 'rc' => $rc]
        );
        return $rc === 0;
    }

    private function flattenArrayWithPrefix(array $arr, string $prefix, string $parentKey = ''): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $key = ($parentKey === '') ? ($prefix . ':' . $k) : ($parentKey . '.' . $k);
            if (\is_array($v)) {
                $out = \array_merge($out, $this->flattenArrayWithPrefix($v, $prefix, $key));
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    // -----------------------------
    // Helpers
    // -----------------------------
    private function escapeShellArg(string $s): string
    {
        // keep it portable
        return escapeshellarg($s);
    }

    /**
     * Backup file to specified dir or same dir with .bak extension
     */
    public function backupFile(string $file, ?string $destDir = null): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $dest =
            $destDir ?
            rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($file) :
            $file . '.bak';
        if (!copy($file, $dest)) {
            $this->logger->warning(
                'backupFile: copy failed',
                ['method' => __METHOD__, 'file' => $file, 'src' => $file, 'dest' => $dest]
            );
            return null;
        }
        return $dest;
    }
}
