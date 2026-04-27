<?php

namespace App\models;

use Atom\Atom;
use Atom\Model;
use Atom\DataBase\Attr\Cast;

class LoginForm extends Model
{
    public string $email = '';
    public string $password = '';

    public static function attributes(): array
    {
        return ['id', 'userUuid', 'isOnline', 'username', 'email', 'firstName', 'lastName', 'country', 'language', 'accountTypeId', 'roleId', 'statusId', 'avatarUri', 'registrationIpId', 'lastLoginIpId', 'createdAt', 'updatedAt', 'lastActiveAt', 'lastLoginAt', 'password' => 'password_hash'];
    }

    public function rules()
    {
        return [
            'email' => [self::RULE_REQUIRED],
            'password' => [self::RULE_REQUIRED],
        ];
    }

    public function labels()
    {
        return [
            'email' => 'Your Email address',
            'password' => 'Password'
        ];
    }

    public function login()
    {
        $user = User::findOne(['email' => $this->email]);

        if (!$user) {
            $this->addError('email', 'User does not exist with this email address');
            return false;
        }

        if (!password_verify($this->password, $user->password)) {
            $this->addError('password', 'Password is incorrect');
            return false;
        }

        return Atom::$app->account->login($user);
    }
}
