<?php

declare(strict_types=1);

namespace Atom;

use Atom\DataBase\DbModel;

/**
 * Abstract base model for authenticated user entities.
 */
abstract class UserModel extends DbModel
{
    abstract public function getDisplayName(): string;
}
