<?php

declare(strict_types=1);

namespace Atom\Structure;


/**
 * A helper class to cast values to specific types.
 *
 * @package Atom\Structure
 */
#[\Attribute]
class Cast {
    public function __construct(public string $type) {}
}
