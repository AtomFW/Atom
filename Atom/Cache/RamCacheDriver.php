<?php

declare(strict_types=1);

namespace Atom\Cache;

/**
 * Supported in-RAM cache backend drivers for `MemoryCacheManager`.
 */
enum RamCacheDriver: string
{
    case Auto = 'auto';
    case Apcu = 'apcu';
    case Sysv = 'sysv';
    case Memory = 'memory';
}
