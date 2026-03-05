<?php

namespace Atom;

use Atom\DataBase\DbModel;

abstract class UserModel extends DbModel
{
    abstract public function getDisplayName(): string;
}
