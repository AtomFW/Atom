<?php

declare(strict_types=1);

namespace Atom\Account;

use Atom\Atom;
use Atom\UserModel;

/**
 * Stateless helper for managing user authentication state across session/request context.
 */
final class Account
{
    /**
     * Checks if the current request user is a guest.
     */
    public static function isGuest(): bool
    {
        return !isset(Atom::$app) || Atom::$app->user === null;
    }

    /**
     * Authenticates a user and regenerates the session ID when configured to prevent session fixation.
     */
    public function login(UserModel $user): bool
    {
        Atom::$app->user = $user;
        $className = $user::class;
        $primaryKey = $className::primaryKey();
        $value = $user->{$primaryKey};

        if (isset(Atom::$app->config)) {
            $sessionCfg = Atom::$app->config->get('session');
            if (!empty($sessionCfg['newSessionWhenLogin'])) {
                Atom::$app->session->newSession();
            }
        }

        Atom::$app->session->set('user', $value);

        return true;
    }

    /**
     * Logs the user out and clears user state from the session.
     */
    public function logout(): void
    {
        Atom::$app->user = null;
        if (isset(Atom::$app->session)) {
            Atom::$app->session->remove('user');
        }
    }

    /**
     * Magic getter shortcut for account status (`isGuest`, `logout`).
     */
    public function __get(string $name): ?bool
    {
        if ($name === 'isGuest') {
            return self::isGuest();
        }

        if ($name === 'logout') {
            $this->logout();
            return true;
        }

        return null;
    }
}
