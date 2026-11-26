<?php

declare(strict_types=1);

namespace App\Models;

use Atom\Atom;
use Atom\Model;

final class LoginForm extends Model
{
    public string $email = '';
    public string $password = '';

    /**
     * @return array<int|string, string>
     */
    public static function attributes(): array
    {
        return [
            'id',
            'userUuid',
            'isOnline',
            'username',
            'email',
            'firstName',
            'lastName',
            'country',
            'language',
            'accountTypeId',
            'roleId',
            'statusId',
            'avatarUri',
            'registrationIpId',
            'lastLoginIpId',
            'createdAt',
            'updatedAt',
            'lastActiveAt',
            'lastLoginAt',
            'password' => 'password_hash',
        ];
    }

    #[\Override]
    public function rules(): array
    {
        return [
            'email' => [self::RULE_REQUIRED, self::RULE_EMAIL],
            'password' => [self::RULE_REQUIRED],
        ];
    }

    #[\Override]
    public function labels(): array
    {
        return [
            'email' => 'Your Email address',
            'password' => 'Password',
        ];
    }

    public function login(): bool
    {
        $user = User::findOne(['email' => $this->email]);

        if (!$user instanceof User) {
            $this->addError('email', 'User does not exist with this email address');
            return false;
        }

        if (!\password_verify($this->password, $user->password)) {
            $this->addError('password', 'Password is incorrect');
            return false;
        }

        return Atom::$app->account->login($user);
    }
}
