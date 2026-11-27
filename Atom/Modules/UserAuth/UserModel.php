<?php

declare(strict_types=1);

namespace Atom\Modules\UserAuth;

use Atom\DataBase\DbModel;

/**
 * Abstract base model for authenticated user entities managed by the `UserAuth` module.
 */
abstract class UserModel extends DbModel
{
    abstract public function getDisplayName(): string;
}
