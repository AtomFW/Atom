<?php

declare(strict_types=1);

namespace Atom\Modules\UserAuth;

use Atom\Atom;
use Atom\Modules\Contract\AtomModuleInterface;
use Atom\Terminal\Terminal;
use Override;
use SensitiveParameter;

/**
 * Internal Atom authentication & user login module (`Atom\Modules\UserAuth`).
 *
 * Decouples user session hydration, `Account` management, password hashing, and `users` SQL
 * migrations (`ua0001`–`ua0004`) from the Atom kernel. Automatically operates in stateless,
 * zero-database mode whenever `noDB` or `microservice` mode is active.
 */
final class UserAuth implements AtomModuleInterface
{
    public const string VERSION = '1.0.0';

    private readonly Account $account;

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
        $this->account = new Account();
    }

    #[Override]
    public function getName(): string
    {
        return 'UserAuth';
    }

    #[Override]
    public function getVersion(): string
    {
        return self::VERSION;
    }

    #[Override]
    public function dependencies(): array
    {
        return [];
    }

    public function account(): Account
    {
        return $this->account;
    }

    #[Override]
    public function boot(Atom $app): void
    {
        $app->account = $this->account;
        $app->user = null;

        $authConfig = $app->config->get('auth');
        $configuredModel = $this->options['userModel']
            ?? ($authConfig['driver']['provider']['model'] ?? '');

        if (\is_string($configuredModel) && $configuredModel !== '') {
            /** @var class-string<UserModel> $configuredModel */
            $app->userClass = $configuredModel;
        }

        // In noDB or microservice mode, Atom must remain 100% DB-stateless and never query `users`
        if (!$app->isDatabaseEnabled()) {
            return;
        }

        $this->hydrateSessionUser($app);
    }

    /**
     * Hydrates `$app->user` from the active session strictly when database usage is enabled.
     */
    public function hydrateSessionUser(Atom $app): void
    {
        if (!$app->isDatabaseEnabled() || !isset($app->session) || $app->userClass === '') {
            return;
        }

        $userId = $app->session->get('user');
        if ($userId === false || $userId === null || $userId === '') {
            return;
        }

        $userClass = $app->userClass;
        if (!\is_subclass_of($userClass, UserModel::class)) {
            return;
        }

        $key = $userClass::primaryKey();
        $foundUser = $userClass::findOne([$key => $userId]);
        $app->user = $foundUser instanceof UserModel ? $foundUser : null;
    }

    /**
     * Hashes a plaintext password using `config/auth.php` Argon2id / Bcrypt policy.
     *
     * @param array<string, mixed> $hashingConfig
     */
    public function hashPassword(
        #[SensitiveParameter]
        string $plainPassword,
        array $hashingConfig = [],
    ): string {
        $driver = \strtolower((string) ($hashingConfig['driver'] ?? 'argon2id'));

        if ($driver === 'argon2id' && \defined('PASSWORD_ARGON2ID')) {
            return \password_hash($plainPassword, \PASSWORD_ARGON2ID, [
                'memory_cost' => \clamp((int) ($hashingConfig['memoryCost'] ?? 65536), 8192, 4_194_304),
                'time_cost' => \clamp((int) ($hashingConfig['timeCost'] ?? 4), 1, 64),
            ]);
        }

        return \password_hash($plainPassword, \PASSWORD_BCRYPT, [
            'cost' => \clamp((int) ($hashingConfig['bcryptCost'] ?? 12), 4, 31),
        ]);
    }

    /**
     * Verifies a plaintext password against a stored password hash.
     */
    public function verifyPassword(
        #[SensitiveParameter]
        string $plainPassword,
        #[SensitiveParameter]
        string $passwordHash,
    ): bool {
        if ($passwordHash === '') {
            return false;
        }

        return \password_verify($plainPassword, $passwordHash);
    }

    #[Override]
    public function registerCommands(Terminal $terminal): Terminal
    {
        return $terminal->withCommand(
            'auth:status',
            function (array $args, Terminal $term): int {
                $userModel = \is_string($this->options['userModel'] ?? null) && $this->options['userModel'] !== ''
                    ? (string) $this->options['userModel']
                    : 'App\\Models\\User (via config/auth.php)';
                $hashDriver = \is_array($this->options['hashing'] ?? null)
                    ? (string) ($this->options['hashing']['driver'] ?? 'argon2id')
                    : 'argon2id';

                $term->table(
                    ['Property', 'Value'],
                    [
                        ['Module', $this->getName() . ' v' . $this->getVersion()],
                        ['User Model', $userModel],
                        ['Hash Driver', $hashDriver],
                        ['SQL Migrations', 'ua0001..ua0004 (Atom/Modules/UserAuth/migration)'],
                    ],
                );
                return 0;
            },
            'Display UserAuth module status, user model, and migration summary',
        );
    }
}
