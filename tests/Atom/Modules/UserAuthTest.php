<?php

declare(strict_types=1);

namespace Tests\Atom\Modules;

use Atom\Atom;
use Atom\Controller;
use Atom\Exception\ForbiddenException;
use Atom\Log\T4LOG;
use Atom\Modules\ModuleMigrationRunner;
use Atom\Modules\UserAuth\Account;
use Atom\Modules\UserAuth\Middlewares\AuthMiddleware;
use Atom\Modules\UserAuth\UserAuth;
use Atom\Modules\UserAuth\UserModel;
use Atom\Modules\UserAuth\UserSession;
use Atom\Session\Session;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Time\Duration;

final class TestAuthUser extends UserModel
{
    public int $id = 0;

    #[\Override]
    public static function tableName(): string
    {
        return 'users';
    }

    #[\Override]
    public function getDisplayName(): string
    {
        return 'Test Auth User';
    }
}

final class UserAuthTest extends TestCase
{
    private string $tempDir;
    private Account $account;
    private TestAuthUser $testUser;
    private Atom $app;
    private ?Atom $previousApp = null;

    protected function setUp(): void
    {
        $this->tempDir = \dirname(__DIR__, 3) . '/runtime/temp/user_auth_test_' . \uniqid();
        \mkdir($this->tempDir . '/logs', 0777, true);

        if (isset(Atom::$app)) {
            $this->previousApp = Atom::$app;
        }

        $this->account = new Account();
        $this->testUser = new TestAuthUser();

        /** @var Atom $app */
        $app = (new ReflectionClass(Atom::class))->newInstanceWithoutConstructor();
        $app->user = null;
        $app->account = $this->account;
        $app->session = new Session([
            'sessionName' => 'ATOM_AUTH_TEST',
            'lifetime' => 3600,
            'secure' => false,
            'httpOnly' => true,
            'sameSite' => 'Lax',
        ]);
        $app->log = new T4LOG(['logRootDir' => $this->tempDir . '/logs/']);

        $this->app = $app;
        Atom::$app = $app;
    }

    protected function tearDown(): void
    {
        if ($this->previousApp instanceof Atom) {
            Atom::$app = $this->previousApp;
        }

        if (\is_dir($this->tempDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $p = $file->getPathname();
                $file->isDir() ? \rmdir($p) : \unlink($p);
            }
            \rmdir($this->tempDir);
        }
    }

    public function testAccountGuestLoginAndLogoutState(): void
    {
        self::assertTrue(Account::isGuest());
        self::assertTrue($this->account->__get('isGuest'));

        $this->testUser->id = 42;
        self::assertTrue($this->account->login($this->testUser));
        self::assertFalse(Account::isGuest());
        self::assertSame($this->testUser, Atom::$app->user);
        self::assertSame(42, Atom::$app->session->get('user'));

        $this->account->logout();
        self::assertTrue(Account::isGuest());
        self::assertNull(Atom::$app->user);
    }

    public function testAccountMagicGetShortcuts(): void
    {
        $this->testUser->id = 77;
        $this->account->login($this->testUser);

        self::assertFalse($this->account->__get('isGuest'));
        self::assertTrue($this->account->__get('logout'));
        self::assertNull(Atom::$app->user);
        self::assertNull($this->account->__get('unknown_property'));
    }

    public function testNoDbModeLogsAuthenticationToT4LogWithoutTouchingDatabase(): void
    {
        $ref = new ReflectionClass(Atom::class);
        $isNoDbProp = $ref->getProperty('isNoDb');
        $isNoDbProp->setValue($this->app, true);

        $this->testUser->id = 99;
        $this->account->login($this->testUser, $this->app);
        $this->account->logout($this->app);

        $logFiles = \glob($this->tempDir . '/logs/*.log') ?: [];
        self::assertNotEmpty($logFiles);

        $logContents = (string) \file_get_contents($logFiles[0]);
        self::assertStringContainsString('User authenticated (noDB/stateless mode).', $logContents);
        self::assertStringContainsString('User logged out (noDB/stateless mode).', $logContents);
    }

    public function testAuthMiddlewareBlocksGuestAndAllowsAuthenticatedUser(): void
    {
        $controller = new class extends Controller {};
        $controller->action = 'profile';
        $this->app->controller = $controller;

        $middleware = new AuthMiddleware(['profile']);

        $this->testUser->id = 12;
        $this->account->login($this->testUser);
        $middleware->execute();
        self::assertFalse(Account::isGuest());

        $this->account->logout();
        $this->expectException(ForbiddenException::class);
        $middleware->execute();
    }

    public function testUserSessionExpirationWithPhp86Duration(): void
    {
        $session = new UserSession(
            userId: 5,
            sessionId: 'sess_123',
            ipAddress: '127.0.0.1',
            userAgent: 'AtomTest/1.0',
            issuedAt: 1_000,
            ttl: Duration::fromSeconds(60),
        );

        self::assertFalse($session->isExpired(1_050));
        self::assertTrue($session->isExpired(1_061));
    }

    public function testUserAuthModulePasswordHashingAndPrefixedSqlDiscovery(): void
    {
        $module = new UserAuth(['hashing' => ['driver' => 'bcrypt', 'bcryptCost' => 4]]);
        self::assertSame('UserAuth', $module->getName());
        self::assertSame(UserAuth::VERSION, $module->getVersion());

        $hash = $module->hashPassword('StrongSecret#2026', ['driver' => 'bcrypt', 'bcryptCost' => 4]);
        self::assertTrue($module->verifyPassword('StrongSecret#2026', $hash));
        self::assertFalse($module->verifyPassword('WrongPassword', $hash));

        $pdo = new PDO('sqlite::memory:');
        $runner = new ModuleMigrationRunner($pdo);
        $discovered = $runner->discoverModuleMigrations('UserAuth');

        self::assertSame([
            'ua0001_create_users',
            'ua0002_keys_users',
            'ua0003_insert_users',
            'ua0004_create_password_resets_token',
        ], \array_keys($discovered));

        foreach ($discovered as $spec) {
            self::assertNotEmpty($spec['upSql']);
            self::assertNotEmpty($spec['downSql']);
        }
    }
}
