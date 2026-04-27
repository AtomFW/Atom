<?php

namespace App\models;

use Atom\Atom;
use Atom\DataBase\DbModel;
use Atom\UserModel;

use Atom\Structure\Cast;

class User extends UserModel
{
    public int $id = 0;
    public int $isOnline = 1;
    public string $username = '';
    public string $email = '';
    public string $firstName = '';
    public string $lastName = '';
    public string $country = 'PL';
    public string $language = 'pl';
    public int $accountTypeId = 14;
    public int $roleId = 65;
    public int $statusId = 65;
    public int $avatarUri = 65;
    public int $registrationIpId = 1;
    public int $lastLoginIpId = 1;
    public string $createdAt = '';
    #[Cast('datetime')]
    public string $updatedAt = '';
    #[Cast('datetime')]
    public string $lastActiveAt = '';
    #[Cast('datetime')]
    public string $lastLoginAt = '';
    public string $password = '';
    public string $passwordConfirm = '';
    #[Cast('datetime')]
    public string $passwordHash = '';
    // #[Cast('sql_bin_to_uuid', 'sql_uuid_to_bin')]
    #[Cast('sql_bin_to_uuid', 'ignore')]
    public string $userUuid = '';
    public ?string $usernameView = '';
    public ?string $emailTwo = '';
    public ?string $gender = '';
    public ?string $phone = '';
    public ?string $phoneVerified = '';
    public ?string $twoFactorEnabled = '';
    public ?string $dateOfBirth = '';
    public ?string $activeSessions = '';
    #[Cast('datetime')]
    public ?string $metadata = '';
    // public string $created_at = "";

    public static function tableName(): string
    {
        return 'users';
    }

    public static function attributesTypes(): array
    {
        return [
            'id' => ["int", "ignore"],
            'createdAt' => ["datetime", "ignore"],
            'updatedAt' => ["datetime", "ignore"],
            'lastActiveAt' => ["datetime"],
            'lastLoginAt' => ["datetime"],
            'userUuid' => ['sql_bin_to_uuid', 'ignore'], // ['sql_bin_to_uuid', 'sql_uuid_to_bin']
        ];
    }

    public static function attributes(): array
    {
        return ['id', 'userUuid', 'isOnline', 'username', 'email', 'firstName', 'lastName', 'country', 'language', 'accountTypeId', 'roleId', 'statusId', 'avatarUri', 'registrationIpId', 'lastLoginIpId', 'createdAt', 'updatedAt', 'lastActiveAt', 'lastLoginAt', 'password' => 'password_hash'];
    }

    public function labels(): array
    {
        return [
            'username' => 'Nick name',
            'firstname' => 'First name',
            'lastname' => 'Last name',
            'email' => 'Email',
            'password' => 'Password',
            'passwordConfirm' => 'Password Confirm'
        ];
    }

    public function rules()
    {
        return [
            'username' => [self::RULE_REQUIRED, [self::RULE_MIN, 'min' => 8], [
                self::RULE_UNIQUE, 'class' => self::class
            ]],
            'firstName' => [self::RULE_REQUIRED],
            'lastName' => [self::RULE_REQUIRED],
            'email' => [self::RULE_REQUIRED, self::RULE_EMAIL, [
                self::RULE_UNIQUE, 'class' => self::class
            ]],
            'password' => [self::RULE_REQUIRED, [self::RULE_MIN, 'min' => 8]],
            'passwordConfirm' => [[self::RULE_MATCH, 'match' => 'password']],
        ];
    }

    public function save(): bool
    {
        $this->password = password_hash($this->password, PASSWORD_DEFAULT);

        $this->createdAt = Atom::$app->datetime->now()->toSQL();
        $this->updatedAt = $this->createdAt;
        $this->lastActiveAt = $this->createdAt;
        $this->lastLoginAt = $this->createdAt;

        return parent::save();
    }

    public function getDisplayName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }
}
