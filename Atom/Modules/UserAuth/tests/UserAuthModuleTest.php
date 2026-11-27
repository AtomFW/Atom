<?php

declare(strict_types=1);

namespace Atom\Modules\UserAuth\Tests;

use Atom\Modules\ModuleMigrationRunner;
use Atom\Modules\UserAuth\UserAuth;
use Atom\Terminal\Terminal;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserAuthModuleTest extends TestCase
{
    public function testModuleContractAndTerminalCommandRegistration(): void
    {
        $module = new UserAuth();
        self::assertSame('UserAuth', $module->getName());
        self::assertSame(UserAuth::VERSION, $module->getVersion());
        self::assertSame([], $module->dependencies());

        $terminal = $module->registerCommands(new Terminal(colorsEnabled: false));
        self::assertContains('auth:status', $terminal->getRegisteredCommands());
        self::assertSame(0, $terminal->run(['atom.php', 'auth:status']));
        self::assertStringContainsString('UserAuth', $terminal->getOutputBuffer());

        $runner = new ModuleMigrationRunner(new PDO('sqlite::memory:'));
        $migrations = $runner->discoverModuleMigrations('UserAuth');
        self::assertArrayHasKey('ua0001_create_users', $migrations);
        self::assertArrayHasKey('ua0004_create_password_resets_token', $migrations);
    }
}
