<?php
declare(strict_types=1);

namespace Atom\Component\Task\Tasks;

use Atom\Component\Task\TaskInterface;

/**
 * Task that instructs worker to resize an image file.
 */
final class ImageResizeTask implements TaskInterface
{
    public function __construct(
        public readonly string $sourcePath,
        public readonly string $destPath,
        public readonly int $width,
        public readonly int $height,
        public readonly int $quality = 90,
    ) {}
}