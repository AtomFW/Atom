<?php

declare(strict_types=1);

namespace Atom\Async;

/**
 * Execution backend driver for `Atom\Async\Async`.
 */
enum AsyncDriver: string
{
    case Auto = 'auto';
    case Swoole = 'swoole';
    case Fiber = 'fiber';
}
