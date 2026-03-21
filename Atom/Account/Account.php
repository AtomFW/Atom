<?php

namespace Atom\Account;

use Atom\Atom;
use Atom\UserModel;

/**
 * Account class
 *
 * This class is a helper for managing user sessions.
 *
 * @package Atom\Account
 */
final class Account
{
    /**
     * Checks if the user is a guest.
     *
     * @return bool true if the user is a guest, false otherwise
     */
    public static function isGuest(): bool
    {
        return !Atom::$app->user;
    }

    /**
     * Logs a user in.
     *
     * @param UserModel $user The user to log in.
     *
     * @return bool true if the user was successfully logged in, false otherwise.
     */
    public function login(UserModel $user): bool
    {
        Atom::$app->user = $user;
        $className = \get_class($user);
        $primaryKey = $className::primaryKey();
        $value = $user->{$primaryKey};
        Atom::$app->session->set('user', $value);

        return true;
    }

    /**
     * Logs the user out.
     *
     * This method will remove the user's session, effectively logging them out.
     */
    public function logout(): void
    {
        Atom::$app->user = null;
        Atom::$app->session->remove('user');
    }

    /**
     * Magic getter method to provide a shortcut for getting account status.
     *
     * @param string $name The name of the property to get.
     *
     * @return bool|null The value of the property if found, null otherwise.
     *
     * @throws InvalidArgumentException If the property does not exist.
     */
    public function __get(string $name): ?bool
    {
        return match ($name) {
            "isGuest" => Account::isGuest(),
            "logout" => self::logout(),
            default => null
        };
    }
}
