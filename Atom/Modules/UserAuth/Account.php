<?php

declare(strict_types=1);

namespace Atom\Modules\UserAuth;

use Atom\Atom;

/**
 * Stateless helper for managing user authentication state across session/request context.
 * In `noDB` or `microservice` mode, authentication events are written to standard `T4LOG`
 * files and never touch the database.
 */
final class Account
{
    /**
     * Checks if the current request user is a guest.
     */
    public static function isGuest(?Atom $app = null): bool
    {
        $targetApp = $app ?? (isset(Atom::$app) ? Atom::$app : null);

        return $targetApp === null || $targetApp->user === null;
    }

    /**
     * Authenticates a user, regenerates session ID when configured, and logs via `T4LOG` in `noDB`/microservice mode.
     */
    public function login(UserModel $user, ?Atom $app = null): bool
    {
        $targetApp = $app ?? Atom::$app;
        $targetApp->user = $user;

        $className = $user::class;
        $primaryKey = $className::primaryKey();
        $value = $user->{$primaryKey};

        if (isset($targetApp->config) && isset($targetApp->session)) {
            $sessionCfg = $targetApp->config->get('session');
            if (!empty($sessionCfg['newSessionWhenLogin'])) {
                $targetApp->session->newSession();
            }
        }

        if (isset($targetApp->session)) {
            $targetApp->session->set('user', $value);
        }

        if ($targetApp->isNoDbMode() && isset($targetApp->log)) {
            $targetApp->log->info('User authenticated (noDB/stateless mode).', [
                'user_id' => $value,
                'model' => $className,
            ]);
        }

        return true;
    }

    /**
     * Logs the user out, clears session state, and records logout in `T4LOG` when in `noDB`/microservice mode.
     */
    public function logout(?Atom $app = null): void
    {
        $targetApp = $app ?? (isset(Atom::$app) ? Atom::$app : null);
        if ($targetApp === null) {
            return;
        }

        $previousUserId = null;
        if ($targetApp->user instanceof UserModel) {
            $pk = ($targetApp->user::class)::primaryKey();
            $previousUserId = $targetApp->user->{$pk} ?? null;
        }

        $targetApp->user = null;

        if (isset($targetApp->session)) {
            $targetApp->session->remove('user');
        }

        if ($targetApp->isNoDbMode() && isset($targetApp->log)) {
            $targetApp->log->info('User logged out (noDB/stateless mode).', [
                'user_id' => $previousUserId,
            ]);
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
