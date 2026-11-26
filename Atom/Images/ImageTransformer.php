<?php

declare(strict_types=1);

namespace Atom\Images;

use GdImage;
use Imagick;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Image manipulation service supporting Imagick and GD with alpha preservation,
 * PHP 8.6 clamp() bounds, and deterministic buffer/resource cleanup.
 */
final class ImageTransformer
{
    private ?Imagick $imagick = null;
    private ?GdImage $gd = null;
    private ?string $loadedFormat = null;
    private int $width = 0;
    private int $height = 0;
    private bool $useImagick = false;
    private LoggerInterface $logger;
    private string $path = 'unknown';

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->useImagick = \extension_loaded('imagick') && \class_exists(Imagick::class);
    }

    public function loadFromFile(string $path): self
    {
        if (!\is_file($path)) {
            $this->logger->warning('File not found or not readable', ['method' => __METHOD__, 'file' => $path]);
            throw new \RuntimeException("File not found or not readable: {$path}");
        }

        \set_error_handler(static fn(): bool => true);
        try {
            $data = \file_get_contents($path);
        } finally {
            \restore_error_handler();
        }
        if ($data === false) {
            $this->logger->warning('Failed to read file', ['method' => __METHOD__, 'file' => $path]);
            throw new \RuntimeException("Failed to read file: {$path}");
        }

        $this->path = $path;
        return $this->loadFromString($data);
    }

    public function loadFromString(string $blob): self
    {
        $this->clear();

        if ($this->useImagick) {
            $this->imagick = new Imagick();
            $this->imagick->readImageBlob($blob);
            $this->imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
            $this->imagick->setImageBackgroundColor(new \ImagickPixel('transparent'));
            $this->width = $this->imagick->getImageWidth();
            $this->height = $this->imagick->getImageHeight();
            $this->loadedFormat = \strtolower($this->imagick->getImageFormat());
            return $this;
        }

        \set_error_handler(static fn(): bool => true);
        try {
            $im = \imagecreatefromstring($blob);
        } finally {
            \restore_error_handler();
        }

        if ($im === false) {
            $this->logger->warning('Failed to create GD image from string', ['method' => __METHOD__, 'file' => $this->path]);
            throw new \RuntimeException('Failed to create GD image from string.');
        }

        $this->gd = $im;
        $this->width = \imagesx($im);
        $this->height = \imagesy($im);
        $this->loadedFormat = null;

        return $this;
    }

    public function clear(): void
    {
        if ($this->imagick instanceof Imagick) {
            try {
                $this->imagick->clear();
                $this->imagick->destroy();
            } catch (Throwable $e) {
                $this->logger->warning('Failed to clear Imagick', [
                    'method' => __METHOD__,
                    'file' => $this->path,
                    'exception' => $e,
                ]);
            }
        }

        $this->imagick = null;
        $this->gd = null;
        $this->width = 0;
        $this->height = 0;
        $this->loadedFormat = null;
    }

    public function isUsingImagick(): bool
    {
        return $this->useImagick && $this->imagick instanceof Imagick;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getLoadedFormat(): ?string
    {
        return $this->loadedFormat;
    }

    public function resize(int $targetW, int $targetH, string $mode = 'fit', bool $allowUpscale = true): self
    {
        if ($targetW <= 0 || $targetH <= 0) {
            $this->logger->warning('resize: target dimensions must be > 0', ['method' => __METHOD__, 'path' => $this->path]);
            throw new \InvalidArgumentException('Target dimensions must be > 0');
        }

        if ($this->isUsingImagick() && $this->imagick !== null) {
            $im = $this->imagick;
            $origW = $im->getImageWidth();
            $origH = $im->getImageHeight();

            if ($mode === 'stretch') {
                $im->resizeImage($targetW, $targetH, Imagick::FILTER_LANCZOS, 1, true);
            } elseif ($mode === 'fit' || $mode === 'contain') {
                [$newW, $newH] = $this->calculateContain($origW, $origH, $targetW, $targetH, $allowUpscale);
                $im->resizeImage($newW, $newH, Imagick::FILTER_LANCZOS, 1, true);
            } elseif ($mode === 'cover') {
                $ratio = \max($targetW / $origW, $targetH / $origH);
                if ($ratio < 1 && !$allowUpscale) {
                    $ratio = 1.0;
                }
                $interW = \max(1, (int) \round($origW * $ratio));
                $interH = \max(1, (int) \round($origH * $ratio));
                $im->resizeImage($interW, $interH, Imagick::FILTER_LANCZOS, 1, true);
                $x = (int) \floor(($interW - $targetW) / 2);
                $y = (int) \floor(($interH - $targetH) / 2);
                $im->cropImage($targetW, $targetH, $x, $y);
                $im->setImagePage($targetW, $targetH, 0, 0);
            } else {
                $this->logger->warning('resize: unknown resize mode', ['method' => __METHOD__, 'path' => $this->path]);
                throw new \InvalidArgumentException('Unknown resize mode: ' . $mode);
            }

            $this->width = $im->getImageWidth();
            $this->height = $im->getImageHeight();
            return $this;
        }

        $gd = $this->requireGd();
        $origW = \imagesx($gd);
        $origH = \imagesy($gd);

        if ($mode === 'stretch') {
            $newW = $targetW;
            $newH = $targetH;
        } elseif ($mode === 'fit' || $mode === 'contain') {
            [$newW, $newH] = $this->calculateContain($origW, $origH, $targetW, $targetH, $allowUpscale);
        } elseif ($mode === 'cover') {
            $ratio = \max($targetW / $origW, $targetH / $origH);
            if ($ratio < 1 && !$allowUpscale) {
                $ratio = 1.0;
            }
            $interW = \max(1, (int) \round($origW * $ratio));
            $interH = \max(1, (int) \round($origH * $ratio));
            $tmp = $this->createTrueColor($targetW, $targetH, true);
            $tmp2 = $this->createTrueColor($interW, $interH, true);
            \imagecopyresampled($tmp2, $gd, 0, 0, 0, 0, $interW, $interH, $origW, $origH);
            $x = (int) \floor(($interW - $targetW) / 2);
            $y = (int) \floor(($interH - $targetH) / 2);
            \imagecopy($tmp, $tmp2, 0, 0, $x, $y, $targetW, $targetH);
            $this->gd = $tmp;
            $this->width = $targetW;
            $this->height = $targetH;
            return $this;
        } else {
            $this->logger->warning('resize: unknown resize mode', ['method' => __METHOD__, 'path' => $this->path]);
            throw new \InvalidArgumentException('Unknown resize mode: ' . $mode);
        }

        $new = $this->createTrueColor($newW, $newH, true);
        \imagecopyresampled($new, $gd, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        $this->gd = $new;
        $this->width = $newW;
        $this->height = $newH;

        return $this;
    }

    public function crop(int $w, int $h, string $gravity = 'center'): self
    {
        if ($this->isUsingImagick() && $this->imagick !== null) {
            $im = $this->imagick;
            [$x, $y] = $this->gravityToXY($im->getImageWidth(), $im->getImageHeight(), $w, $h, $gravity);
            $im->cropImage($w, $h, $x, $y);
            $im->setImagePage($w, $h, 0, 0);
            $this->width = $w;
            $this->height = $h;
            return $this;
        }

        $gd = $this->requireGd();
        [$x, $y] = $this->gravityToXY(\imagesx($gd), \imagesy($gd), $w, $h, $gravity);
        $tmp = $this->createTrueColor($w, $h, true);
        \imagecopy($tmp, $gd, 0, 0, $x, $y, $w, $h);
        $this->gd = $tmp;
        $this->width = $w;
        $this->height = $h;

        return $this;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function calculateContain(int $origW, int $origH, int $targetW, int $targetH, bool $allowUpscale): array
    {
        $ratio = \min($targetW / $origW, $targetH / $origH);
        if ($ratio > 1.0 && !$allowUpscale) {
            $ratio = 1.0;
        }

        return [
            \max(1, (int) \round($origW * $ratio)),
            \max(1, (int) \round($origH * $ratio)),
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function gravityToXY(int $origW, int $origH, int $w, int $h, string $gravity): array
    {
        [$x, $y] = match ($gravity) {
            'top' => [(int) \floor(($origW - $w) / 2), 0],
            'bottom' => [(int) \floor(($origW - $w) / 2), \max(0, $origH - $h)],
            'left' => [0, (int) \floor(($origH - $h) / 2)],
            'right' => [\max(0, $origW - $w), (int) \floor(($origH - $h) / 2)],
            default => [(int) \floor(($origW - $w) / 2), (int) \floor(($origH - $h) / 2)],
        };

        return [\max(0, $x), \max(0, $y)];
    }

    public function rotate(float $degrees, string $bgcolor = 'transparent'): self
    {
        if ($this->isUsingImagick() && $this->imagick !== null) {
            $pixel = new \ImagickPixel($bgcolor === 'transparent' ? 'transparent' : $bgcolor);
            $this->imagick->rotateImage($pixel, $degrees);
            $this->imagick->setImagePage(0, 0, 0, 0);
            $this->width = $this->imagick->getImageWidth();
            $this->height = $this->imagick->getImageHeight();
            return $this;
        }

        $gd = $this->requireGd();
        $bg = $bgcolor === 'transparent'
            ? (int) \imagecolorallocatealpha($gd, 0, 0, 0, 127)
            : $this->colorAlloc($gd, $bgcolor);

        $rot = \imagerotate($gd, -$degrees, $bg);
        if ($rot === false) {
            throw new \RuntimeException('Failed to rotate GD image.');
        }

        \imagesavealpha($rot, true);
        $this->gd = $rot;
        $this->width = \imagesx($rot);
        $this->height = \imagesy($rot);

        return $this;
    }

    public function roundCorners(int $radius): self
    {
        if ($radius <= 0) {
            return $this;
        }

        if ($this->isUsingImagick() && $this->imagick !== null) {
            $mask = new Imagick();
            try {
                $mask->newImage($this->width, $this->height, new \ImagickPixel('transparent'));
                $draw = new \ImagickDraw();
                $draw->setFillColor('white');
                $draw->roundRectangle(0, 0, $this->width, $this->height, $radius, $radius);
                $mask->drawImage($draw);
                $this->imagick->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0);
                $this->imagick->setImagePage(0, 0, 0, 0);
            } finally {
                $mask->clear();
                $mask->destroy();
            }
            return $this;
        }

        $gd = $this->requireGd();
        $w = $this->width;
        $h = $this->height;
        $mask = $this->createTrueColor($w, $h, true);
        $transparent = (int) \imagecolorallocatealpha($mask, 0, 0, 0, 127);
        \imagefill($mask, 0, 0, $transparent);
        $col = (int) \imagecolorallocate($mask, 255, 255, 255);
        $this->roundRect($mask, 0, 0, $w, $h, $radius, $col);

        $result = $this->createTrueColor($w, $h, true);
        \imagefill($result, 0, 0, $transparent);

        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                if ((\imagecolorat($mask, $x, $y) & 0xFF) > 0) {
                    \imagesetpixel($result, $x, $y, (int) \imagecolorat($gd, $x, $y));
                }
            }
        }

        $this->gd = $result;
        return $this;
    }

    private function roundRect(GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $col): void
    {
        \imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $col);
        \imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $col);
        \imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $col);
        \imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $col);
        \imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $col);
        \imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $col);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function applyFilter(string $filter, array $options = []): self
    {
        $filter = \strtolower($filter);
        if ($this->isUsingImagick() && $this->imagick !== null) {
            switch ($filter) {
                case 'grayscale':
                case 'greyscale':
                    $this->imagick->setImageColorspace(Imagick::COLORSPACE_GRAY);
                    break;
                case 'sepia':
                    $this->imagick->sepiaToneImage(isset($options['threshold']) ? (int) $options['threshold'] : 80);
                    break;
                case 'brightness':
                    $this->imagick->modulateImage(100 + (int) ($options['amount'] ?? 0), 100, 100);
                    break;
                case 'contrast':
                    $times = \clamp((int) ($options['amount'] ?? 10), 0, 100);
                    for ($i = 0; $i < $times; $i++) {
                        $this->imagick->contrastImage(true);
                    }
                    break;
                case 'blur':
                    $this->imagick->blurImage((float) ($options['radius'] ?? 1), (float) ($options['sigma'] ?? 0.5));
                    break;
                default:
                    $this->logger->warning("Unknown filter: {$filter}", ['method' => __METHOD__, 'path' => $this->path]);
                    throw new \InvalidArgumentException("Unknown filter: {$filter}");
            }
            return $this;
        }

        $gd = $this->requireGd();
        switch ($filter) {
            case 'grayscale':
            case 'greyscale':
                \imagefilter($gd, \IMG_FILTER_GRAYSCALE);
                break;
            case 'sepia':
                \imagefilter($gd, \IMG_FILTER_GRAYSCALE);
                \imagefilter($gd, \IMG_FILTER_COLORIZE, 90, 60, 30);
                break;
            case 'brightness':
                \imagefilter($gd, \IMG_FILTER_BRIGHTNESS, (int) ($options['amount'] ?? 0));
                break;
            case 'contrast':
                \imagefilter($gd, \IMG_FILTER_CONTRAST, (int) ($options['amount'] ?? -10));
                break;
            case 'blur':
                \imagefilter($gd, \IMG_FILTER_GAUSSIAN_BLUR);
                break;
            default:
                $this->logger->warning("Unknown filter: {$filter}", ['method' => __METHOD__, 'path' => $this->path]);
                throw new \InvalidArgumentException("Unknown filter: {$filter}");
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function addText(string $text, array $options = []): self
    {
        $font = isset($options['fontFile']) ? (string) $options['fontFile'] : null;
        $size = \clamp((int) ($options['size'] ?? 16), 1, 1024);
        $color = (string) ($options['color'] ?? '#ffffff');
        $opacity = isset($options['opacity']) ? \clamp((float) $options['opacity'], 0.0, 1.0) : 1.0;
        $angle = (float) ($options['angle'] ?? 0.0);

        if ($this->isUsingImagick() && $this->imagick !== null) {
            $draw = new \ImagickDraw();
            $draw->setFillColor(new \ImagickPixel($color));
            $draw->setFillOpacity($opacity);
            $defaultFont = __DIR__ . '/../../resources/font/Oxygen.ttf';
            if ($font !== null || \is_file($defaultFont)) {
                $draw->setFont($font ?? $defaultFont);
            }
            $draw->setFontSize($size);
            $metrics = $this->imagick->queryFontMetrics($draw, $text);
            $textW = (int) $metrics['textWidth'];
            $textH = (int) $metrics['textHeight'];
            [$x, $y] = $this->textPositionFromOptions($textW, $textH, $options);
            $this->imagick->annotateImage($draw, $x, $y + $textH, $angle, $text);
            return $this;
        }

        $gd = $this->requireGd();
        $bbox = ($font !== null && \is_file($font)) ? \imagettfbbox($size, $angle, $font, $text) : false;
        $textW = \is_array($bbox) ? \abs($bbox[2] - $bbox[0]) : (int) (\strlen($text) * ($size / 2));
        $textH = \is_array($bbox) ? \abs($bbox[7] - $bbox[1]) : $size;
        [$x, $y] = $this->textPositionFromOptions($textW, $textH, $options);
        $col = $this->hexColorAllocate($gd, $color, $opacity);

        if ($font !== null && \is_file($font)) {
            \imagettftext($gd, $size, $angle, $x, $y + $textH, $col, $font, $text);
        } else {
            \imagestring($gd, 5, $x, $y, $text, $col);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: int, 1: int}
     */
    private function textPositionFromOptions(int $textW, int $textH, array $options): array
    {
        $x = isset($options['x']) ? (int) $options['x'] : null;
        $y = isset($options['y']) ? (int) $options['y'] : null;
        $align = (string) ($options['align'] ?? 'left');
        $valign = (string) ($options['valign'] ?? 'top');

        $x ??= match ($align) {
            'center' => (int) (($this->width - $textW) / 2),
            'right' => $this->width - $textW - 10,
            default => 10,
        };

        $y ??= match ($valign) {
            'middle' => (int) (($this->height - $textH) / 2),
            'bottom' => $this->height - $textH - 10,
            default => 10,
        };

        return [$x, $y];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function addWatermark(string $wmPathOrBlob, array $options = []): self
    {
        $opacity = isset($options['opacity']) ? \clamp((float) $options['opacity'], 0.0, 1.0) : 0.5;
        $scale = isset($options['scale']) ? \clamp((float) $options['scale'], 0.01, 10.0) : 0.2;
        $position = (string) ($options['position'] ?? 'bottom-right');
        $x = isset($options['x']) ? (int) $options['x'] : null;
        $y = isset($options['y']) ? (int) $options['y'] : null;

        $wm = new self($this->logger);
        try {
            if (\is_file($wmPathOrBlob)) {
                $wm->loadFromFile($wmPathOrBlob);
            } else {
                $wm->loadFromString($wmPathOrBlob);
            }

            $targetW = \max(1, (int) \round($this->width * $scale));
            $targetH = \max(
                1,
                (int) \round($this->height * $scale * ($wm->getWidth() > 0 ? $wm->getHeight() / $wm->getWidth() : 1.0)),
            );
            $wm->resize($targetW, $targetH, 'fit', true);
            $wmBlob = $wm->getBlob('png', 90);
        } finally {
            $wm->clear();
        }

        if ($this->isUsingImagick() && $this->imagick !== null) {
            $overlay = new Imagick();
            try {
                $overlay->readImageBlob($wmBlob);
                if ($opacity < 1.0) {
                    $overlay->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
                    $overlay->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity, Imagick::CHANNEL_ALPHA);
                }
                [$px, $py] = $this->positionCoords(
                    $overlay->getImageWidth(),
                    $overlay->getImageHeight(),
                    $position,
                    $x,
                    $y,
                );
                $this->imagick->compositeImage($overlay, Imagick::COMPOSITE_OVER, $px, $py);
            } finally {
                $overlay->clear();
                $overlay->destroy();
            }
            return $this;
        }

        $gd = $this->requireGd();
        $overlay = \imagecreatefromstring($wmBlob);
        if ($overlay === false) {
            throw new \RuntimeException('Failed to decode watermark blob.');
        }

        \imagesavealpha($overlay, true);
        $ow = \imagesx($overlay);
        $oh = \imagesy($overlay);
        [$px, $py] = $this->positionCoords($ow, $oh, $position, $x, $y);
        $this->gdImageCopyMergeAlpha($gd, $overlay, $px, $py, 0, 0, $ow, $oh, $opacity);

        return $this;
    }

    public function getBlob(string $format = 'webp', int $quality = 80): string
    {
        $format = \strtolower($format);
        $clampedQuality = \clamp($quality, 0, 100);

        if ($this->isUsingImagick() && $this->imagick !== null) {
            $im = clone $this->imagick;
            try {
                $fmt = $format === 'jpg' ? 'jpeg' : $format;
                if ($fmt === 'jpeg' && $im->getImageAlphaChannel()) {
                    $bg = new Imagick();
                    $bg->newImage($im->getImageWidth(), $im->getImageHeight(), new \ImagickPixel('white'));
                    $bg->compositeImage($im, Imagick::COMPOSITE_OVER, 0, 0);
                    $im->clear();
                    $im->destroy();
                    $im = $bg;
                }
                $im->setImageFormat($fmt === 'webp' || $fmt === 'avif' ? $fmt : \strtoupper($fmt));
                $im->setImageCompressionQuality($clampedQuality);
                return $im->getImagesBlob();
            } finally {
                $im->clear();
                $im->destroy();
            }
        }

        $gd = $this->requireGd();
        \ob_start();
        $blob = '';
        try {
            match ($format) {
                'webp' => \function_exists('imagewebp') ? \imagewebp($gd, null, $clampedQuality) : \imagepng($gd),
                'jpg', 'jpeg' => \imagejpeg($gd, null, $clampedQuality),
                'gif' => \imagegif($gd),
                default => \imagepng($gd),
            };
            $blob = (string) \ob_get_contents();
        } finally {
            \ob_end_clean();
        }

        if ($blob === '') {
            $this->logger->warning('Failed to produce image blob (GD).', [
                'method' => __METHOD__,
                'path' => $this->path,
                'format' => $format,
            ]);
            throw new \RuntimeException('Failed to produce image blob (GD).');
        }

        return $blob;
    }

    public function save(string $path, string $format = 'webp', int $quality = 80): bool
    {
        $format = \strtolower($format);
        $clampedQuality = \clamp($quality, 0, 100);

        if ($this->isUsingImagick()) {
            $blob = $this->getBlob($format, $clampedQuality);
            return \file_put_contents($path, $blob) !== false;
        }

        $gd = $this->requireGd();
        return match ($format) {
            'webp' => \function_exists('imagewebp') ? \imagewebp($gd, $path, $clampedQuality) : \imagepng($gd, $path),
            'jpg', 'jpeg' => \imagejpeg($gd, $path, $clampedQuality),
            'gif' => \imagegif($gd, $path),
            default => \imagepng($gd, $path),
        };
    }

    public function toBase64(string $format = 'webp', int $quality = 80): string
    {
        $blob = $this->getBlob($format, $quality);
        return 'data:image/' . \strtolower($format) . ';base64,' . \base64_encode($blob);
    }

    public function convert(string $format = 'webp', int $quality = 80): self
    {
        return $this->loadFromString($this->getBlob($format, $quality));
    }

    public function compress(int $quality = 75, string $format = 'webp'): self
    {
        return $this->convert($format, $quality);
    }

    public function getAvgColor(): string
    {
        if ($this->isUsingImagick() && $this->imagick !== null) {
            $tmp = clone $this->imagick;
            try {
                $tmp->resizeImage(1, 1, Imagick::FILTER_BOX, 1);
                $pixel = $tmp->getImagePixelColor(0, 0);
                $color = $pixel->getColor();
                return \sprintf('#%02x%02x%02x', $color['r'], $color['g'], $color['b']);
            } finally {
                $tmp->clear();
                $tmp->destroy();
            }
        }

        $gd = $this->requireGd();
        $tmp = \imagecreatetruecolor(1, 1);
        if ($tmp === false) {
            return '#000000';
        }

        \imagecopyresampled($tmp, $gd, 0, 0, 0, 0, 1, 1, $this->width, $this->height);
        $rgb = (int) \imagecolorat($tmp, 0, 0);

        return \sprintf('#%02x%02x%02x', ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
    }

    public function getDominantColor(int $paletteSize = 8): string
    {
        $clampedPalette = \clamp($paletteSize, 1, 256);

        if ($this->isUsingImagick() && $this->imagick !== null) {
            $tmp = clone $this->imagick;
            try {
                $tmp->resizeImage(64, 64, Imagick::FILTER_BOX, 1, true);
                $tmp->quantizeImage($clampedPalette, Imagick::COLORSPACE_RGB, 0, false, false);
                $histogram = $tmp->getImageHistogram();

                $max = 0;
                $dominant = null;
                foreach ($histogram as $pixel) {
                    $count = $pixel->getColorCount();
                    if ($count > $max) {
                        $max = $count;
                        $dominant = $pixel->getColor();
                    }
                }

                if (\is_array($dominant)) {
                    return \sprintf('#%02x%02x%02x', $dominant['r'], $dominant['g'], $dominant['b']);
                }
                return '#000000';
            } finally {
                $tmp->clear();
                $tmp->destroy();
            }
        }

        $gd = $this->requireGd();
        $sampleSize = 64;
        $tmp = \imagecreatetruecolor($sampleSize, $sampleSize);
        if ($tmp === false) {
            return '#000000';
        }

        \imagecopyresampled($tmp, $gd, 0, 0, 0, 0, $sampleSize, $sampleSize, $this->width, $this->height);

        $colorMap = [];
        for ($x = 0; $x < $sampleSize; $x++) {
            for ($y = 0; $y < $sampleSize; $y++) {
                $rgb = (int) \imagecolorat($tmp, $x, $y);
                $r = \clamp((int) (\round((($rgb >> 16) & 0xFF) / 32) * 32), 0, 255);
                $g = \clamp((int) (\round((($rgb >> 8) & 0xFF) / 32) * 32), 0, 255);
                $b = \clamp((int) (\round(($rgb & 0xFF) / 32) * 32), 0, 255);
                $key = ($r << 16) | ($g << 8) | $b;
                $colorMap[$key] = ($colorMap[$key] ?? 0) + 1;
            }
        }

        \arsort($colorMap);
        $key = (int) \array_key_first($colorMap);

        return \sprintf('#%02x%02x%02x', ($key >> 16) & 0xFF, ($key >> 8) & 0xFF, $key & 0xFF);
    }

    private function requireGd(): GdImage
    {
        if ($this->gd === null) {
            throw new \RuntimeException('No image loaded into ImageTransformer.');
        }

        return $this->gd;
    }

    private function createTrueColor(int $w, int $h, bool $preserveAlpha = false): GdImage
    {
        $img = \imagecreatetruecolor(\max(1, $w), \max(1, $h));
        if ($img === false) {
            throw new \RuntimeException('Failed to allocate truecolor GD image.');
        }

        if ($preserveAlpha) {
            \imagealphablending($img, false);
            \imagesavealpha($img, true);
            $col = (int) \imagecolorallocatealpha($img, 0, 0, 0, 127);
            \imagefilledrectangle($img, 0, 0, $w, $h, $col);
        }

        return $img;
    }

    private function colorAlloc(GdImage $gd, string $hex): int
    {
        [$r, $g, $b] = $this->parseHexRgb($hex);
        return (int) \imagecolorallocate($gd, $r, $g, $b);
    }

    private function hexColorAllocate(GdImage $gd, string $hex, float $opacity = 1.0): int
    {
        [$r, $g, $b] = $this->parseHexRgb($hex);
        $alpha = \clamp((int) \round((1.0 - $opacity) * 127), 0, 127);
        return (int) \imagecolorallocatealpha($gd, $r, $g, $b, $alpha);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function parseHexRgb(string $hex): array
    {
        $hex = \ltrim($hex, '#');
        if (\strlen($hex) === 3) {
            return [
                (int) \hexdec(\str_repeat($hex[0], 2)),
                (int) \hexdec(\str_repeat($hex[1], 2)),
                (int) \hexdec(\str_repeat($hex[2], 2)),
            ];
        }

        return [
            (int) \hexdec(\substr($hex, 0, 2)),
            (int) \hexdec(\substr($hex, 2, 2)),
            (int) \hexdec(\substr($hex, 4, 2)),
        ];
    }

    private function gdImageCopyMergeAlpha(
        GdImage $dstImg,
        GdImage $srcImg,
        int $dstX,
        int $dstY,
        int $srcX,
        int $srcY,
        int $srcW,
        int $srcH,
        float $pct,
    ): void {
        $tmp = $this->createTrueColor($srcW, $srcH, true);
        \imagecopy($tmp, $srcImg, 0, 0, $srcX, $srcY, $srcW, $srcH);

        for ($x = 0; $x < $srcW; $x++) {
            for ($y = 0; $y < $srcH; $y++) {
                $rgba = (int) \imagecolorat($tmp, $x, $y);
                $a = ($rgba & 0x7F000000) >> 24;
                $alpha = 1.0 - ($a / 127.0);
                $dstColor = (int) \imagecolorat($dstImg, $dstX + $x, $dstY + $y);
                $dr = ($dstColor >> 16) & 0xFF;
                $dg = ($dstColor >> 8) & 0xFF;
                $db = $dstColor & 0xFF;
                $sr = ($rgba >> 16) & 0xFF;
                $sg = ($rgba >> 8) & 0xFF;
                $sb = $rgba & 0xFF;
                $finalAlpha = $alpha * $pct;
                $r = \clamp((int) \round($sr * $finalAlpha + $dr * (1.0 - $finalAlpha)), 0, 255);
                $g = \clamp((int) \round($sg * $finalAlpha + $dg * (1.0 - $finalAlpha)), 0, 255);
                $b = \clamp((int) \round($sb * $finalAlpha + $db * (1.0 - $finalAlpha)), 0, 255);
                $col = (int) \imagecolorallocatealpha($dstImg, $r, $g, $b, 0);
                \imagesetpixel($dstImg, $dstX + $x, $dstY + $y, $col);
            }
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function positionCoords(
        int $wOverlay,
        int $hOverlay,
        string $position,
        ?int $x = null,
        ?int $y = null,
    ): array {
        if ($x !== null && $y !== null) {
            return [$x, $y];
        }

        return match ($position) {
            'center' => [(int) (($this->width - $wOverlay) / 2), (int) (($this->height - $hOverlay) / 2)],
            'top-left' => [10, 10],
            'top-right' => [$this->width - $wOverlay - 10, 10],
            'bottom-left' => [10, $this->height - $hOverlay - 10],
            default => [$this->width - $wOverlay - 10, $this->height - $hOverlay - 10],
        };
    }

    public function __destruct()
    {
        $this->clear();
    }
}
