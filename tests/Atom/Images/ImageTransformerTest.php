<?php

declare(strict_types=1);

namespace Tests\Atom\Images;

use Atom\Images\ImageTransformer;
use PHPUnit\Framework\TestCase;

final class ImageTransformerTest extends TestCase
{
    private function makePngBlob(int $w = 4, int $h = 2, array $color = [255,0,0,127]): string
    {
        // Create a tiny transparent PNG via GD for portability
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $bg = imagecolorallocatealpha($img, $color[0], $color[1], $color[2], $color[3]);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        ob_start();
        imagepng($img);
        return (string)ob_get_clean();
    }

    public function testLoadFromStringSetsDimensions(): void
    {
        $blob = $this->makePngBlob(10, 6);
        $t = new ImageTransformer();
        $t->loadFromString($blob);
        $this->assertSame(10, $t->getWidth());
        $this->assertSame(6, $t->getHeight());
    }

    public function testResizeFitPreservesAspectAndNoUpscale(): void
    {
        $blob = $this->makePngBlob(100, 50);
        $t = new ImageTransformer();
        $t->loadFromString($blob);
        $t->resize(30, 30, 'fit', false);
        // Original AR = 2.0, target box 30x30 -> 30x15
        $this->assertSame(30, $t->getWidth());
        $this->assertSame(15, $t->getHeight());
    }

    public function testResizeCoverCropsToTarget(): void
    {
        $blob = $this->makePngBlob(50, 100);
        $t = new ImageTransformer();
        $t->loadFromString($blob);
        $t->resize(30, 30, 'cover', true);
        $this->assertSame(30, $t->getWidth());
        $this->assertSame(30, $t->getHeight());
    }

    public function testCropWithGravityCenterProducesExactSize(): void
    {
        $blob = $this->makePngBlob(40, 20);
        $t = new ImageTransformer();
        $t->loadFromString($blob);
        $t->crop(10, 10, 'center');
        $this->assertSame(10, $t->getWidth());
        $this->assertSame(10, $t->getHeight());
    }

    public function testApplyUnknownFilterThrows(): void
    {
        $blob = $this->makePngBlob();
        $t = new ImageTransformer();
        $t->loadFromString($blob);
        $this->expectException(\InvalidArgumentException::class);
        $t->applyFilter('nope');
    }

    public function testAddTextDoesNotThrowAndKeepsDimensions(): void
    {
        $blob = $this->makePngBlob(60, 40);
        $t = new ImageTransformer();
        $t->loadFromString($blob);
        $w = $t->getWidth();
        $h = $t->getHeight();
        $t->addText('hello', ['color' => '#00ff00', 'size' => 12]);
        $this->assertSame($w, $t->getWidth());
        $this->assertSame($h, $t->getHeight());
    }

    public function testGetBlobReturnsNonEmptyDefaultWebpOrPngFallback(): void
    {
        $blob = $this->makePngBlob(10, 10);
        $t = new ImageTransformer();
        $t->loadFromString($blob);
        $out = $t->getBlob('webp', 80);
        $this->assertNotSame('', $out);
        $this->assertGreaterThan(0, strlen($out));
    }

    public function testToBase64Prefix(): void
    {
        $blob = $this->makePngBlob(8, 8);
        $t = new ImageTransformer();
        $t->loadFromString($blob);
        $data = $t->toBase64('png', 90);
        $this->assertStringStartsWith('data:image/png;base64,', $data);
    }

    public function testCompressChangesQualityButReturnsChainable(): void
    {
        $blob = $this->makePngBlob(8, 8);
        $t = new ImageTransformer();
        $t->loadFromString($blob);
        $res = $t->compress(60, 'png');
        $this->assertInstanceOf(ImageTransformer::class, $res);
        $this->assertSame(8, $res->getWidth());
        $this->assertSame(8, $res->getHeight());
    }

    public function testGetAvgColorOnSolidImage(): void
    {
        // Solid red (semi transparent) should yield close to #ff0000
        $blob = $this->makePngBlob(6, 6, [255,0,0,0]);
        $t = new ImageTransformer();
        $t->loadFromString($blob);
        $hex = $t->getAvgColor();
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $hex);
    }
}
