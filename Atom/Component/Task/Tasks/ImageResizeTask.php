<?php

declare(strict_types=1);

namespace Atom\Component\Task\Tasks;

use Atom\Component\Task\TaskInterface;

/**
 * Immutable image resize task DTO.
 */
final readonly class ImageResizeTask implements TaskInterface
{
    public function __construct(
        public string $sourcePath,
        public string $destPath,
        public int $width,
        public int $height,
        public int $quality = 90,
    ) {}
}
