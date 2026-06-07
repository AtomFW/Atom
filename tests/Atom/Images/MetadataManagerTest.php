<?php

declare(strict_types=1);

namespace Tests\Atom\Images;

use Atom\Images\MetadataManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Atom\Images\Metadata\MetadataManager
 *
 * Conventions:
 * - Uses real small assets from resources/ or public/ when possible.
 * - Skips EXIF-related checks if PHP exif extension is not installed.
 */
final class MetadataManagerTest extends TestCase
{
    private string $projectRoot;
    private ?string $sampleImage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = realpath(__DIR__ . '/../TestFile/') ?: getcwd();

        $candidates = [
            $this->projectRoot . '/source.png',
            $this->projectRoot . '/out/out.webp',
            $this->projectRoot . '/out.webp',
            $this->projectRoot . '/source.png',
        ];
        $this->sampleImage = null;
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $this->sampleImage = $path;
                break;
            }
        }
    }

    private function ensureSampleImage(): void
    {
        if ($this->sampleImage === null) {
            $this->markTestSkipped('No sample image available in expected locations.');
        }
    }

    public function testGetAllMetadataOnNonexistentFileReturnsEmptyGroups(): void
    {
        $manager = new MetadataManager();
        $fakePath = $this->projectRoot . '/resources/image/does-not-exist-12345.webp';

        $all = $manager->getAllMetadata($fakePath);
        $this->assertIsArray($all);
        $this->assertArrayHasKey('exif', $all);
        $this->assertArrayHasKey('iptc', $all);
        $this->assertArrayHasKey('xmp', $all);
        $this->assertSame([], $all['exif']);
        $this->assertSame([], $all['iptc']);
        $this->assertSame([], $all['xmp']);
    }

    public function testReadExifGracefullyWithoutExtensionOrUnsupportedFormat(): void
    {
        $manager = new MetadataManager();
        $tmp = tempnam(sys_get_temp_dir(), 'meta_');
        file_put_contents($tmp, "not an image");

        $data = $manager->readExif($tmp);
        $this->assertIsArray($data);
        @unlink($tmp);
    }

    public function testReadIptcOnNonJpegReturnsEmpty(): void
    {
        $this->ensureSampleImage();
        $manager = new MetadataManager();
        $iptc = $manager->readIptc($this->sampleImage);
        $this->assertIsArray($iptc);
        $this->assertSame([], $iptc, 'WebP/PNG/GIF should not report IPTC via native reader');
    }

    public function testReadXmpReturnsArrayOrEmpty(): void
    {
        $this->ensureSampleImage();
        $manager = new MetadataManager();
        $xmp = $manager->readXmp($this->sampleImage);
        $this->assertIsArray($xmp);
    }

    public function testCountMetadataKeysIsZeroForNonexistent(): void
    {
        $manager = new MetadataManager();
        $fakePath = $this->projectRoot . '/resources/image/does-not-exist-xyz.webp';
        $this->assertSame(0, $manager->countMetadataKeys($fakePath));
    }

    public function testStripAllMetadataSucceedsUsingFallbackForWebp(): void
    {
        $this->ensureSampleImage();
        $manager = new MetadataManager();
        $out = sys_get_temp_dir() . '/out_' . uniqid() . '.webp';
        @unlink($out);

        $ok = $manager->stripAllMetadata($this->sampleImage, $out, false);
        $this->assertTrue($ok, 'Expected stripAllMetadata to succeed using fallback copy for webp');
        $this->assertFileExists($out);

        @unlink($out);
    }

    public function testStripAllMetadataFailsForMissingFile(): void
    {
        $manager = new MetadataManager();
        $fakePath = $this->projectRoot . '/resources/image/missing_abc.webp';
        $this->assertFalse($manager->stripAllMetadata($fakePath));
    }

    public function testAddIptcTagsReturnsFalseForNonJpeg(): void
    {
        $this->ensureSampleImage();
        $manager = new MetadataManager();
        $this->assertFalse($manager->addIptcTags($this->sampleImage, ['Byline' => 'Author']));
    }

    public function testAddXmpTagReturnsFalseForNonJpeg(): void
    {
        $this->ensureSampleImage();
        $manager = new MetadataManager();
        $this->assertFalse($manager->addXmpTag($this->sampleImage, 'Flag', '1'));
    }

    public function testExportMetadataJsonProducesValidJson(): void
    {
        $this->ensureSampleImage();
        $manager = new MetadataManager();
        $json = $manager->exportMetadataJson($this->sampleImage);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('exif', $decoded);
        $this->assertArrayHasKey('iptc', $decoded);
        $this->assertArrayHasKey('xmp', $decoded);
    }

    public function testBackupFileCreatesBakInSameDir(): void
    {
        $this->ensureSampleImage();
        $manager = new MetadataManager();

        // Copy sample to temp location to avoid touching repo file
        $tmpDir = sys_get_temp_dir() . '/meta_bak_' . uniqid();
        @mkdir($tmpDir);
        $src = $tmpDir . '/image.webp';
        $this->assertTrue(copy($this->sampleImage, $src));

        try {
            $bak = $manager->backupFile($src);
            $this->assertIsString($bak);
            $this->assertFileExists($bak);
        } finally {
            @unlink($src);
            if (isset($bak) && $bak) {
                @unlink($bak);
            }
            @rmdir($tmpDir);
        }
    }
}
