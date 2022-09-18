<?php

namespace Atom\core;

use Atom\core\DataBase\DbModel;

abstract class UserModel extends DbModel
{
    abstract public function getDisplayName(): string;
}