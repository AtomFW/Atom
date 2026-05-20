<?php

declare(strict_types=1);

namespace Atom\Images;

use Throwable;
use Imagick;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * ImageTransformer
 *
 * - Uses Imagick if available, otherwise falls back to GD.
 * - Preserves transparency where possible.
 * - Default export format: webp (if supported by runtime).
 *
 * Methods return $this for chaining where relevant.
 */
final class ImageTransformer
{
    private ?Imagick $imagick = null;
    private $gd; // GD image resource or null
    private ?string $loadedFormat = null;
    private int $width = 0;
    private int $height = 0;
    private bool $useImagick = false;
    private ?LoggerInterface $logger;
    private string $path = 'unknown';

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->useImagick = extension_loaded('imagick') && class_exists(Imagick::class);
    }

    // -------------------------
    // Loading / clearing
    // -------------------------
    /**
     * Load image from file path
     */
    public function loadFromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            $this->logger->warning('File not found or not readable', ['method' => __METHOD__, 'file' => $this->path]);
            throw new \RuntimeException("File not found or not readable: $path");
        }
        $data = file_get_contents($path);
        if ($data === false) {
            $this->logger->warning('Failed to read file', ['method' => __METHOD__, 'file' => $this->pathh]);
            throw new \RuntimeException("Failed to read file: $path");
        }
        $this->path = $path;
        return $this->loadFromString($data);
    }

    /**
     * Load image from binary string
     */
    public function loadFromString(string $blob): self
    {
        $this->clear();

        if ($this->useImagick) {
            $this->imagick = new \Imagick();
            $this->imagick->readImageBlob($blob);
            $this->imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
            $this->imagick->setImageBackgroundColor(new \ImagickPixel('transparent'));
            $this->width = $this->imagick->getImageWidth();
            $this->height = $this->imagick->getImageHeight();
            $this->loadedFormat = strtolower($this->imagick->getImageFormat());
            return $this;
        }

        // GD fallback
        $im = @imagecreatefromstring($blob);
        if ($im === false) {
            $this->logger->warning('Failed to create GD image from string', ['method' => __METHOD__, 'file' => $this->path]);
            throw new \RuntimeException("Failed to create GD image from string.");
        }
        $this->gd = $im;
        $this->width = imagesx($im);
        $this->height = imagesy($im);
        $this->loadedFormat = null;
        return $this;
    }

    /**
     * Clear resources
     */
    public function clear(): void
    {
        if ($this->imagick instanceof \Imagick) {
            try {
                $this->imagick->clear();
                $this->imagick->destroy();
            } catch (Throwable $e) {
                $this->logger->warning('Failed to clear Imagick', ['method' => __METHOD__, 'file' => $this->path, 'exception' => $e]);
            }
        }
        if ($this->gd !== null) {
            $this->gd = null;
        }
        $this->imagick = null;
        $this->gd = null;
        $this->width = $this->height = 0;
        $this->loadedFormat = null;
    }

    public function isUsingImagick(): bool
    {
        return $this->useImagick && $this->imagick instanceof \Imagick;
    }

    // -------------------------
    // Basic info
    // -------------------------
    public function getWidth(): int
    {
        return $this->width;
    }
    public function getHeight(): int
    {
        return $this->height;
    }

    // -------------------------
    // Resize / aspect
    // -------------------------

    /**
     * Resize with optional preserving aspect.
     * $mode: 'fit' (contain), 'cover' (crop to fill), 'stretch' (ignore aspect)
     */
    public function resize(int $targetW, int $targetH, string $mode = 'fit', bool $allowUpscale = true): self
    {
        if ($targetW <= 0 || $targetH <= 0) {
            $this->logger->warning('resize: target dimensions must be > 0', ["method" => __METHOD__, "path" => $this->path]);
            throw new \InvalidArgumentException('Target dimensions must be > 0');
        }

        if ($this->isUsingImagick()) {
            $im = $this->imagick;
            $origW = $im->getImageWidth();
            $origH = $im->getImageHeight();

            if ($mode === 'stretch') {
                $im->resizeImage($targetW, $targetH, \Imagick::FILTER_LANCZOS, 1, true);
            } elseif ($mode === 'fit' || $mode === 'contain') {
                [$newW, $newH] = $this->calculateContain($origW, $origH, $targetW, $targetH, $allowUpscale);
                $im->resizeImage($newW, $newH, \Imagick::FILTER_LANCZOS, 1, true);
            } elseif ($mode === 'cover') {
                // scale then crop center
                $ratio = max($targetW / $origW, $targetH / $origH);
                if ($ratio < 1 && !$allowUpscale) {
                    $ratio = 1;
                }
                $interW = (int)round($origW * $ratio);
                $interH = (int)round($origH * $ratio);
                $im->resizeImage($interW, $interH, \Imagick::FILTER_LANCZOS, 1, true);
                $x = (int)floor(($interW - $targetW) / 2);
                $y = (int)floor(($interH - $targetH) / 2);
                $im->cropImage($targetW, $targetH, $x, $y);
                $im->setImagePage($targetW, $targetH, 0, 0);
            } else {
                $this->logger->warning('resize: unknown resize mode', ["method" => __METHOD__, "path" => $this->path]);
                throw new \InvalidArgumentException('Unknown resize mode: ' . $mode);
            }

            $this->width = $im->getImageWidth();
            $this->height = $im->getImageHeight();
            return $this;
        }

        // GD fallback
        $origW = imagesx($this->gd);
        $origH = imagesy($this->gd);

        if ($mode === 'stretch') {
            $newW = $targetW;
            $newH = $targetH;
        } elseif ($mode === 'fit' || $mode === 'contain') {
            [$newW, $newH] = $this->calculateContain($origW, $origH, $targetW, $targetH, $allowUpscale);
        } elseif ($mode === 'cover') {
            $ratio = max($targetW / $origW, $targetH / $origH);
            if ($ratio < 1 && !$allowUpscale) {
                $ratio = 1;
            }
            $interW = (int)round($origW * $ratio);
            $interH = (int)round($origH * $ratio);
            $tmp = $this->createTrueColor($targetW, $targetH, true);
            // resample then copy centered crop
            $tmp2 = $this->createTrueColor($interW, $interH, true);
            imagecopyresampled($tmp2, $this->gd, 0, 0, 0, 0, $interW, $interH, $origW, $origH);
            $x = (int)floor(($interW - $targetW) / 2);
            $y = (int)floor(($interH - $targetH) / 2);
            imagecopy($tmp, $tmp2, 0, 0, $x, $y, $targetW, $targetH);
            $tmp2 = null;
            $this->gd = null;
            $this->gd = $tmp;
            $this->width = $targetW;
            $this->height = $targetH;
            return $this;
        } else {
            $this->logger->warning('resize: unknown resize mode', ["method" => __METHOD__, "path" => $this->path]);
            throw new \InvalidArgumentException('Unknown resize mode: ' . $mode);
        }

        $new = $this->createTrueColor($newW, $newH, true);
        imagecopyresampled($new, $this->gd, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        $this->gd = null;
        $this->gd = $new;
        $this->width = $newW;
        $this->height = $newH;
        return $this;
    }

    /**
     * Crop to exact size from gravity: 'center'|'top'|'bottom'|'left'|'right'
     */
    public function crop(int $w, int $h, string $gravity = 'center'): self
    {
        if ($this->isUsingImagick()) {
            $im = $this->imagick;
            $origW = $im->getImageWidth();
            $origH = $im->getImageHeight();
            [$x,$y] = $this->gravityToXY($origW, $origH, $w, $h, $gravity);
            $im->cropImage($w, $h, $x, $y);
            $im->setImagePage($w, $h, 0, 0);
            $this->width = $w;
            $this->height = $h;
            return $this;
        }

        $origW = imagesx($this->gd);
        $origH = imagesy($this->gd);
        [$x,$y] = $this->gravityToXY($origW, $origH, $w, $h, $gravity);
        $tmp = $this->createTrueColor($w, $h, true);
        imagecopy($tmp, $this->gd, 0, 0, $x, $y, $w, $h);
        $this->gd = null;
        $this->gd = $tmp;
        $this->width = $w;
        $this->height = $h;
        return $this;
    }

    /**
     * Calculate contain size
     */
    private function calculateContain(int $origW, int $origH, int $targetW, int $targetH, bool $allowUpscale): array
    {
        $ratio = min($targetW / $origW, $targetH / $origH);
        if ($ratio > 1 && !$allowUpscale) {
            $ratio = 1.0;
        }
        $newW = max(1, (int)round($origW * $ratio));
        $newH = max(1, (int)round($origH * $ratio));
        return [$newW, $newH];
    }

    private function gravityToXY(int $origW, int $origH, int $w, int $h, string $gravity): array
    {
        $x = 0;
        $y = 0;
        switch ($gravity) {
            case 'center':
                $x = (int)floor(($origW - $w) / 2);
                $y = (int)floor(($origH - $h) / 2);
                break;
            case 'top':
                $x = (int)floor(($origW - $w) / 2);
                $y = 0;
                break;
            case 'bottom':
                $x = (int)floor(($origW - $w) / 2);
                $y = max(0, $origH - $h);
                break;
            case 'left':
                $x = 0;
                $y = (int)floor(($origH - $h) / 2);
                break;
            case 'right':
                $x = max(0, $origW - $w);
                $y = (int)floor(($origH - $h) / 2);
                break;
            default:
                $x = (int)floor(($origW - $w) / 2);
                $y = (int)floor(($origH - $h) / 2);
        }
        $x = max(0, $x);
        $y = max(0, $y);
        return [$x, $y];
    }

    // -------------------------
    // Rotate
    // -------------------------
    public function rotate(float $degrees, string $bgcolor = 'transparent'): self
    {
        if ($this->isUsingImagick()) {
            $pixel = new \ImagickPixel($bgcolor === 'transparent' ? 'transparent' : $bgcolor);
            $this->imagick->rotateImage($pixel, $degrees);
            $this->imagick->setImagePage(0, 0, 0, 0);
            $this->width = $this->imagick->getImageWidth();
            $this->height = $this->imagick->getImageHeight();
            return $this;
        }

        // GD
        $bg = $bgcolor === 'transparent' ?
            imagecolorallocatealpha(
                $this->gd,
                0,
                0,
                0,
                127
            ) :
            $this->colorAlloc(
                $this->gd,
                $bgcolor
            );
        $rot = imagerotate($this->gd, -$degrees, $bg);
        // preserve alpha
        imagesavealpha($rot, true);
        $this->gd = null;
        $this->gd = $rot;
        $this->width = imagesx($this->gd);
        $this->height = imagesy($this->gd);
        return $this;
    }

    // -------------------------
    // Rounded corners / border radius
    // -------------------------
    public function roundCorners(int $radius): self
    {
        if ($radius <= 0) {
            return $this;
        }
        if ($this->isUsingImagick()) {
            $mask = new \Imagick();
            $mask->newImage($this->width, $this->height, new \ImagickPixel('transparent'));
            $draw = new \ImagickDraw();
            $draw->setFillColor('white');
            $draw->roundRectangle(0, 0, $this->width, $this->height, $radius, $radius);
            $mask->drawImage($draw);
            $this->imagick->compositeImage($mask, \Imagick::COMPOSITE_DSTIN, 0, 0);
            $mask->clear();
            $mask->destroy();
            $this->imagick->setImagePage(0, 0, 0, 0);
            return $this;
        }

        // GD
        $w = $this->width;
        $h = $this->height;
        $mask = $this->createTrueColor($w, $h, true);
        $transparent = imagecolorallocatealpha($mask, 0, 0, 0, 127);
        imagefill($mask, 0, 0, $transparent);
        $col = imagecolorallocate($mask, 255, 255, 255);
        $this->roundRect($mask, 0, 0, $w, $h, $radius, $col);
        // apply mask: preserve alpha
        $result = $this->createTrueColor($w, $h, true);
        imagefill($result, 0, 0, $transparent);
        // Copy pixels where mask is white
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                $alpha = (imagecolorat($mask, $x, $y) & 0xFF);
                // if mask pixel is nonzero we copy original pixel
                if ($alpha > 0) {
                    $color = imagecolorat($this->gd, $x, $y);
                    imagesetpixel($result, $x, $y, $color);
                }
            }
        }
        $this->gd = null;
        $mask = null;

        $this->gd = $result;
        return $this;
    }

    // draw rounded rectangle on GD
    private function roundRect($img, $x1, $y1, $x2, $y2, $r, $col)
    {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $col);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $col);
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $col);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $col);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $col);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $col);
    }

    // -------------------------
    // Filters
    // -------------------------
    public function applyFilter(string $filter, array $options = []): self
    {
        $filter = strtolower($filter);
        if ($this->isUsingImagick()) {
            switch ($filter) {
                case 'grayscale':
                case 'greyscale':
                    $this->imagick->setImageColorspace(\Imagick::COLORSPACE_GRAY);
                    break;
                case 'sepia':
                    $this->imagick->sepiaToneImage(isset($options['threshold']) ? (int)$options['threshold'] : 80);
                    break;
                case 'brightness':
                    $this->imagick->modulateImage(100 + (int)($options['amount'] ?? 0), 100, 100);
                    break;
                case 'contrast':
                    $times = (int)($options['amount'] ?? 10);
                    for ($i = 0; $i < $times; $i++) {
                        $this->imagick->contrastImage(true);
                    }
                    break;
                case 'blur':
                    $this->imagick->blurImage((float)($options['radius'] ?? 1), (float)($options['sigma'] ?? 0.5));
                    break;
                default:
                    $this->logger->warning("Unknown filter: $filter", ["method" => __METHOD__, "path" => $this->path]);
                    throw new \InvalidArgumentException("Unknown filter: $filter");
            }
            return $this;
        }

        // GD fallback
        switch ($filter) {
            case 'grayscale':
            case 'greyscale':
                imagefilter($this->gd, IMG_FILTER_GRAYSCALE);
                break;
            case 'sepia':
                imagefilter($this->gd, IMG_FILTER_GRAYSCALE);
                imagefilter($this->gd, IMG_FILTER_COLORIZE, 90, 60, 30);
                break;
            case 'brightness':
                imagefilter($this->gd, IMG_FILTER_BRIGHTNESS, (int)($options['amount'] ?? 0));
                break;
            case 'contrast':
                imagefilter($this->gd, IMG_FILTER_CONTRAST, (int)($options['amount'] ?? -10));
                break;
            case 'blur':
                imagefilter($this->gd, IMG_FILTER_GAUSSIAN_BLUR);
                break;
            default:
                $this->logger->warning("Unknown filter: $filter", ["method" => __METHOD__, "path" => $this->path]);
                throw new \InvalidArgumentException("Unknown filter: $filter");
        }
        return $this;
    }

    // -------------------------
    // Text overlay
    // -------------------------
    /**
     * Add text onto image.
     * $options: [
     *   fontFile => '/path/to.ttf',
     *   size => 16,
     *   color => '#ffffff',
     *   opacity => 1.0 (0..1),
     *   angle => 0,
     *   x => int|null,
     *   y => int|null,
     *   align => 'left'|'center'|'right',
     *   valign => 'top'|'middle'|'bottom'
     * ]
     */
    public function addText(string $text, array $options = []): self
    {
        $font = $options['fontFile'] ?? null;
        $size = (int)($options['size'] ?? 16);
        $color = $options['color'] ?? '#ffffff';
        $opacity = isset($options['opacity']) ? max(0, min(1, (float)$options['opacity'])) : 1.0;
        $angle = (float)($options['angle'] ?? 0);

        if ($this->isUsingImagick()) {
            $draw = new \ImagickDraw();
            $pixel = new \ImagickPixel($color);
            $draw->setFillColor($pixel);
            $draw->setFillOpacity($opacity);
            $draw->setFont($font ?? __DIR__ . "/../../resources/font/Oxygen.ttf");
            $draw->setFontSize($size);
            $metrics = $this->imagick->queryFontMetrics($draw, $text);
            $textW = (int)$metrics['textWidth'];
            $textH = (int)$metrics['textHeight'];

            // position
            [$x,$y] = $this->textPositionFromOptions($textW, $textH, $options);

            $this->imagick->annotateImage($draw, $x, $y + $textH, $angle, $text);
            return $this;
        }

        // GD drawing: use imagettftext
        $bbox = $font ? imagettfbbox($size, $angle, $font, $text) : null;
        $textW = $bbox ? abs($bbox[2] - $bbox[0]) : strlen($text) * ($size / 2);
        $textH = $bbox ? abs($bbox[7] - $bbox[1]) : $size;
        [$x,$y] = $this->textPositionFromOptions($textW, $textH, $options);

        $col = $this->hexColorAllocate($this->gd, $color, $opacity);

        if ($font && file_exists($font)) {
            imagettftext($this->gd, $size, $angle, $x, $y + $textH, $col, $font, $text);
        } else {
            imagestring($this->gd, 5, $x, $y, $text, $col);
        }
        return $this;
    }

    private function textPositionFromOptions(int $textW, int $textH, array $options): array
    {
        $x = $options['x'] ?? null;
        $y = $options['y'] ?? null;
        $align = $options['align'] ?? 'left';
        $valign = $options['valign'] ?? 'top';
        if ($x === null) {
            if ($align === 'center') {
                $x = (int)(($this->width - $textW) / 2);
            } elseif ($align === 'right') {
                $x = $this->width - $textW - 10;
            } else {
                $x = 10;
            }
        }
        if ($y === null) {
            if ($valign === 'middle') {
                $y = (int)(($this->height - $textH) / 2);
            } elseif ($valign === 'bottom') {
                $y = $this->height - $textH - 10;
            } else {
                $y = 10;
            }
        }
        return [$x, $y];
    }

    // -------------------------
    // Watermark (image)
    // -------------------------
    /**
     * Add watermark image path or binary.
     * $options: scale (0..1 relative to base), x/y, opacity (0..1), position: 'center'|'top-left'|...
     */
    public function addWatermark(string $wmPathOrBlob, array $options = []): self
    {
        $opacity = isset($options['opacity']) ? max(0, min(1, (float)$options['opacity'])) : 0.5;
        $scale = isset($options['scale']) ? (float)$options['scale'] : 0.2;
        $position = $options['position'] ?? 'bottom-right';
        $x = $options['x'] ?? null;
        $y = $options['y'] ?? null;

        // load watermark as ImageTransformer
        $wm = new self();
        // detect if path or blob
        if (file_exists($wmPathOrBlob)) {
            $wm->loadFromFile($wmPathOrBlob);
        } else {
            $wm->loadFromString($wmPathOrBlob);
        }

        // scale watermark to fraction of base image
        $targetW = max(1, (int)round($this->width * $scale));
        $targetH = max(
            1,
            (int)round($this->height * $scale * ($wm->getHeight() ? $wm->getHeight() / $wm->getWidth() : 1))
        );
        $wm->resize($targetW, $targetH, 'fit', true);
        $wmBlob = $wm->getBlob(); // binary of watermark

        if ($this->isUsingImagick()) {
            $overlay = new \Imagick();
            $overlay->readImageBlob($wmBlob);
            // adjust opacity
            if ($opacity < 1.0) {
                $overlay->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
                $overlay->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $opacity, \Imagick::CHANNEL_ALPHA);
            }
            // position
            [$px,$py] = $this->positionCoords(
                (int)$overlay->getImageWidth(),
                (int)$overlay->getImageHeight(),
                $position,
                $x,
                $y
            );
            $this->imagick->compositeImage($overlay, \Imagick::COMPOSITE_OVER, $px, $py);
            $overlay->clear();
            $overlay->destroy();
            return $this;
        }

        // GD: create image from blob
        $overlay = imagecreatefromstring($wmBlob);
        imagesavealpha($overlay, true);
        $ow = imagesx($overlay);
        $oh = imagesy($overlay);
        [$px,$py] = $this->positionCoords($ow, $oh, $position, $x, $y);
        // merge with opacity
        $this->gdImageCopyMergeAlpha($this->gd, $overlay, $px, $py, 0, 0, $ow, $oh, $opacity);
        $overlay = null;
        return $this;
    }

    // -------------------------
    // Convert / compress / save
    // -------------------------
    /**
     * Convert to target format and return binary.
     * $format: 'webp','png','jpeg','jpg','gif','avif'
     */
    public function getBlob(string $format = 'webp', int $quality = 80): string
    {
        $format = strtolower($format);
        if ($this->isUsingImagick()) {
            $im = clone $this->imagick;
            // for formats that require flattening if no alpha support (jpeg)
            $hasAlpha = $im->getImageAlphaChannel();
            $fmt = strtoupper($format);
            if ($format === 'jpg') {
                $fmt = 'jpeg';
            }
            if ($format === 'jpeg' && $hasAlpha) {
                // flatten on white background
                $bg = new \Imagick();
                $bg->newImage($im->getImageWidth(), $im->getImageHeight(), new \ImagickPixel('white'));
                $bg->compositeImage($im, \Imagick::COMPOSITE_OVER, 0, 0);
                $im = $bg;
            }
            // set format and quality
            if ($format === 'webp') {
                if (defined('\Imagick::COMPRESSION_WEBP')) {
                    $im->setImageFormat('webp');
                } else {
                    $im->setImageFormat('webp');
                }
                $im->setImageCompressionQuality($quality);
            } elseif ($format === 'avif') {
                $im->setImageFormat('avif');
                $im->setImageCompressionQuality($quality);
            } else {
                $im->setImageFormat($fmt);
                $im->setImageCompressionQuality($quality);
            }

            $blob = $im->getImagesBlob();
            $im->clear();
            $im->destroy();
            return $blob;
        }

        // GD fallback
        ob_start();
        $success = false;
        switch ($format) {
            case 'webp':
                if (function_exists('imagewebp')) {
                    imagewebp($this->gd, null, $quality);
                    $success = true;
                } else {
                    // fallback to png
                    imagepng($this->gd);
                }
                break;
            case 'png':
                imagepng($this->gd);
                $success = true;
                break;
            case 'jpg':
            case 'jpeg':
                imagejpeg($this->gd, null, $quality);
                $success = true;
                break;
            case 'gif':
                imagegif($this->gd);
                $success = true;
                break;
            default:
                // unknown -> png
                imagepng($this->gd);
                $success = true;
        }
        $blob = (string)ob_get_clean();
        if ($blob === '') {
            $this->logger->warning('Failed to produce image blob (GD).', ['method' => __METHOD__, 'path' => $this->path, 'format' => $format]);
            throw new \RuntimeException('Failed to produce image blob (GD).');
        }
        return $blob;
    }

    /**
     * Save to file
     */
    public function save(string $path, string $format = 'webp', int $quality = 80): bool
    {
        $format = strtolower($format);
        if ($this->isUsingImagick()) {
            $blob = $this->getBlob($format, $quality);
            $res = file_put_contents($path, $blob);
            return $res !== false;
        }

        // GD: direct save if functions available
        switch ($format) {
            case 'webp':
                if (function_exists('imagewebp')) {
                    return imagewebp($this->gd, $path, $quality);
                }
                return imagepng($this->gd, $path);
            case 'png':
                return imagepng($this->gd, $path);
            case 'jpg':
            case 'jpeg':
                return imagejpeg($this->gd, $path, $quality);
            case 'gif':
                return imagegif($this->gd, $path);
            default:
                return imagepng($this->gd, $path);
        }
    }

    public function toBase64(string $format = 'webp', int $quality = 80): string
    {
        $blob = $this->getBlob($format, $quality);
        return 'data:image/' . $format . ';base64,' . base64_encode($blob);
    }

    public function convert(string $format = 'webp', int $quality = 80): self
    {
        $blob = $this->getBlob($format, $quality);
        return $this->loadFromString($blob);
    }

    public function compress(int $quality = 75, string $format = 'webp'): self
    {
        return $this->convert($format, $quality);
    }

    // -------------------------
    // Basic info
    // TODO doać prawdziwą funkcjię
    // -------------------------
    public function getAvgColor(): string
    {
        // Imagick (most accurate)
        if ($this->isUsingImagick()) {
            $tmp = clone $this->imagick;
            $tmp->resizeImage(1, 1, \Imagick::FILTER_BOX, 1);
            $pixel = $tmp->getImagePixelColor(0, 0);
            $color = $pixel->getColor();

            $tmp->clear();
            $tmp->destroy();

            return sprintf('#%02x%02x%02x', $color['r'], $color['g'], $color['b']);
        }

        // GD fallback
        $tmp = imagecreatetruecolor(1, 1);

        imagecopyresampled(
            $tmp,
            $this->gd,
            0,
            0,
            0,
            0,
            1,
            1,
            $this->width,
            $this->height
        );

        $rgb = imagecolorat($tmp, 0, 0);

        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        // PHP 8.5 – we do not use imagedestroy
        $tmp = null;

        return \sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    // -------------------------
    // Utility helpers (GD)
    // -------------------------
    private function createTrueColor(int $w, int $h, bool $preserveAlpha = false)
    {
        $img = imagecreatetruecolor($w, $h);
        if ($preserveAlpha) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $col = imagecolorallocatealpha($img, 0, 0, 0, 127);
            imagefilledrectangle($img, 0, 0, $w, $h, $col);
        }
        return $img;
    }

    private function colorAlloc($gd, string $hex)
    {
        $hex = ltrim($hex, '#');
        if (\strlen($hex) === 3) {
            $r = hexdec(str_repeat($hex[0], 2));
            $g = hexdec(str_repeat($hex[1], 2));
            $b = hexdec(str_repeat($hex[2], 2));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        return imagecolorallocate($gd, $r, $g, $b);
    }

    private function hexColorAllocate($gd, string $hex, float $opacity = 1.0)
    {
        $hex = ltrim($hex, '#');
        if (\strlen($hex) === 3) {
            $r = hexdec(str_repeat($hex[0], 2));
            $g = hexdec(str_repeat($hex[1], 2));
            $b = hexdec(str_repeat($hex[2], 2));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        $alpha = (int)round((1 - $opacity) * 127);
        return imagecolorallocatealpha($gd, $r, $g, $b, $alpha);
    }

    // copy with alpha merge for GD
    private function gdImageCopyMergeAlpha($dstImg, $srcImg, $dstX, $dstY, $srcX, $srcY, $srcW, $srcH, $pct)
    {
        // $pct is 0..1
        $tmp = imagecreatetruecolor($srcW, $srcH);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefilledrectangle($tmp, 0, 0, $srcW, $srcH, $transparent);
        imagecopy($tmp, $srcImg, 0, 0, $srcX, $srcY, $srcW, $srcH);
        // merge with opacity using imagecopymerge (does not support alpha), so we do pixel-wise
        for ($x = 0; $x < $srcW; $x++) {
            for ($y = 0; $y < $srcH; $y++) {
                $rgba = imagecolorat($tmp, $x, $y);
                $a = ($rgba & 0x7F000000) >> 24;
                // $a = $a < 0 ? 127 + $a : $a; // dedth code // compatibility
                $alpha = 1 - ($a / 127);
                $dstColor = imagecolorat($dstImg, $dstX + $x, $dstY + $y);
                $dr = ($dstColor >> 16) & 0xFF;
                $dg = ($dstColor >> 8) & 0xFF;
                $db = $dstColor & 0xFF;
                $sr = ($rgba >> 16) & 0xFF;
                $sg = ($rgba >> 8) & 0xFF;
                $sb = $rgba & 0xFF;
                $finalAlpha = $alpha * $pct;
                $r = (int)round($sr * $finalAlpha + $dr * (1 - $finalAlpha));
                $g = (int)round($sg * $finalAlpha + $dg * (1 - $finalAlpha));
                $b = (int)round($sb * $finalAlpha + $db * (1 - $finalAlpha));
                $col = imagecolorallocatealpha($dstImg, $r, $g, $b, 0);
                imagesetpixel($dstImg, $dstX + $x, $dstY + $y, $col);
            }
        }
        $tmp = null;
    }

    private function positionCoords(
        int $wOverlay,
        int $hOverlay,
        string $position,
        ?int $x = null,
        ?int $y = null
    ): array {
        if ($x !== null && $y !== null) {
            return [$x, $y];
        }

        return [$px, $py] = match ($position) {
            'center' => [
                (int)(($this->width - $wOverlay) / 2),
                (int)(($this->height - $hOverlay) / 2),
            ],
            'top-left' => [
                10,
                10
            ],
            'top-right' => [
                $this->width - $wOverlay - 10,
                10
            ],
            'bottom-left' => [
                10,
                $this->height - $hOverlay - 10
            ],
            'bottom-right' => [
                $this->width - $wOverlay - 10,
                $this->height - $hOverlay - 10
            ],
            default => [
                $this->width - $wOverlay - 10,
                $this->height - $hOverlay - 10
            ]
        };
    }

    public function getDominantColor(int $paletteSize = 8): string
    {
        // Imagick (best quality)
        if ($this->isUsingImagick()) {
            $tmp = clone $this->imagick;

            // we reduce the image for efficiency
            $tmp->resizeImage(64, 64, \Imagick::FILTER_BOX, 1, true);

            // color reduction
            $tmp->quantizeImage($paletteSize, \Imagick::COLORSPACE_RGB, 0, false, false);

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

            $tmp->clear();
            $tmp->destroy();

            if ($dominant) {
                return sprintf('#%02x%02x%02x', $dominant['r'], $dominant['g'], $dominant['b']);
            }

            return '#000000';
        }

        // GD fallback
        $sampleSize = 64;
        $tmp = imagecreatetruecolor($sampleSize, $sampleSize);

        imagecopyresampled(
            $tmp,
            $this->gd,
            0,
            0,
            0,
            0,
            $sampleSize,
            $sampleSize,
            $this->width,
            $this->height
        );

        $colorMap = [];

        for ($x = 0; $x < $sampleSize; $x++) {
            for ($y = 0; $y < $sampleSize; $y++) {
                $rgb = imagecolorat($tmp, $x, $y);

                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // slight color rounding (quantization)
                $r = (int)(round($r / 32) * 32);
                $g = (int)(round($g / 32) * 32);
                $b = (int)(round($b / 32) * 32);

                $key = ($r << 16) | ($g << 8) | $b;

                if (!isset($colorMap[$key])) {
                    $colorMap[$key] = 0;
                }

                $colorMap[$key]++;
            }
        }

        $tmp = null;

        arsort($colorMap);
        $key = array_key_first($colorMap);

        $r = ($key >> 16) & 0xFF;
        $g = ($key >> 8) & 0xFF;
        $b = $key & 0xFF;

        return \sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
