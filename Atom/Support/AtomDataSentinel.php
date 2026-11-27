<?php

declare(strict_types=1);

namespace Atom\Support;

/**
 * Internal sentinel distinguishing `atom_data('key')` reads from `atom_data('key', null)` writes.
 */
enum AtomDataSentinel
{
    case Unset;
}
