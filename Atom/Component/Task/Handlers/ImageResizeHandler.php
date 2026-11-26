<?php

declare(strict_types=1);

namespace Atom\Component\Task\Handlers;

use Atom\Component\Task\Tasks\ImageResizeTask;

/**
 * Stateless GD image resize task handler with PHP 8.6 clamp() bounds.
 */
final readonly class ImageResizeHandler
{
    public function __invoke(ImageResizeTask $task): void
    {
        if (!\extension_loaded('gd')) {
            throw new \RuntimeException('GD extension required for image resize.');
        }

        $src = $task->sourcePath;
        $dst = $task->destPath;

        if (!\is_file($src)) {
            throw new \RuntimeException('Cannot read image ' . $src);
        }

        $info = \getimagesize($src);
        if ($info === false) {
            throw new \RuntimeException('Cannot read image ' . $src);
        }

        $mime = $info['mime'];
        $img = match ($mime) {
            'image/jpeg' => \imagecreatefromjpeg($src),
            'image/png' => \imagecreatefrompng($src),
            'image/webp' => \function_exists('imagecreatefromwebp') ? \imagecreatefromwebp($src) : false,
            'image/gif' => \imagecreatefromgif($src),
            default => throw new \RuntimeException('Unsupported image type: ' . $mime),
        };

        if ($img === false) {
            throw new \RuntimeException('Failed to decode source image: ' . $src);
        }

        $width = \clamp($task->width, 1, 16384);
        $height = \clamp($task->height, 1, 16384);
        $quality = \clamp($task->quality, 0, 100);

        $resized = \imagescale($img, $width, $height);
        if ($resized === false) {
            throw new \RuntimeException('Failed to resize image');
        }

        $destDir = \dirname($dst);
        if (!\is_dir($destDir) && !\mkdir($destDir, 0755, true) && !\is_dir($destDir)) {
            throw new \RuntimeException('Failed to create destination directory: ' . $destDir);
        }

        $ext = \strtolower(\pathinfo($dst, \PATHINFO_EXTENSION));
        $saved = match ($ext) {
            'png' => \imagepng($resized, $dst),
            'webp' => \function_exists('imagewebp') ? \imagewebp($resized, $dst, $quality) : \imagejpeg($resized, $dst, $quality),
            'gif' => \imagegif($resized, $dst),
            default => \imagejpeg($resized, $dst, $quality),
        };

        if (!$saved) {
            throw new \RuntimeException('Failed to save resized image to ' . $dst);
        }
    }
}
