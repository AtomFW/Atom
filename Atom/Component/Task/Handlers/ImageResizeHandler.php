<?php
declare(strict_types=1);

namespace Atom\Component\Task\Handlers;

use Atom\Component\Task\Tasks\ImageResizeTask;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Naive image resize implementation using GD extension.
 */
#[AsMessageHandler]
final class ImageResizeHandler
{
    public function __invoke(ImageResizeTask $task): void
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('GD extension required for image resize.');
        }

        $src = $task->sourcePath;
        $dst = $task->destPath;

        $info = getimagesize($src);
        if ($info === false) {
            throw new \RuntimeException('Cannot read image ' . $src);
        }

        $mime = $info['mime'];
        switch ($mime) {
            case 'image/jpeg':
                $img = imagecreatefromjpeg($src);
                break;
            case 'image/png':
                $img = imagecreatefrompng($src);
                break;
            default:
                throw new \RuntimeException('Unsupported image type: ' . $mime);
        }

        $resized = imagescale($img, $task->width, $task->height);
        if ($resized === false) {
            throw new \RuntimeException('Failed to resize image');
        }

        // Save JPEG
        imagejpeg($resized, $dst, $task->quality);

        $img = null;
        $resized = null;
    }
}