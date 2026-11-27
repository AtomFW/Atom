<?php

declare(strict_types=1);

namespace Tests\Atom\Modules;

use Atom\Atom;
use Atom\Cache\InMemoryRedisAdapter;
use Atom\Log\T4LOG;
use Atom\Modules\ModuleMigrationRunner;
use Atom\Modules\OAuth\OAuth;
use Atom\Modules\OAuth\OAuthPkce;
use Atom\Modules\UserAuth\Account;
use Atom\Modules\UserAuth\UserModel;
use Atom\Session\Session;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

final class OAuthDummyUser extends UserModel
{
    public int $id = 0;
    public string $email = '';

    #[\Override]
    public static function tableName(): string
    {
        return 'users';
    }

    #[\Override]
    public function getDisplayName(): string
    {
        return 'OAuth User #' . $this->id;
    }
}

final class OAuthTest extends TestCase
{
    private string $tempDir;
    private Atom $app;
    private ?Atom $previousApp = null;

    protected function setUp(): void
    {
        $this->tempDir = \dirname(__DIR__, 3) . '/runtime/temp/oauth_test_' . \uniqid();
        \mkdir($this->tempDir . '/logs', 0777, true);

        if (isset(Atom::$app)) {
            $this->previousApp = Atom::$app;
        }

        /** @var Atom $app */
        $app = (new ReflectionClass(Atom::class))->newInstanceWithoutConstructor();
        $isNoDbProp = (new ReflectionClass(Atom::class))->getProperty('isNoDb');
        $isNoDbProp->setValue($app, true);

        $app->user = null;
        $app->account = new Account();
        $app->session = new Session(['sessionName' => 'ATOM_OAUTH_TEST', 'secure' => false]);
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
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                $p = $file->getPathname();
                $file->isDir() ? \rmdir($p) : \unlink($p);
            }
            \rmdir($this->tempDir);
        }
    }

    public function testOAuthPkceAuthorizationCodeFlowRefreshRotationAndRedisCompatibility(): void
    {
        $redis = new InMemoryRedisAdapter(true);
        $oauth = new OAuth([
            'default' => 'redis',
            'choices' => ['redis' => ['driver' => 'redis', 'on' => true]],
            'pkce' => ['required' => true, 'allowPlainMethod' => false],
            'tokens' => ['accessTtlSeconds' => 3600, 'refreshTtlSeconds' => 86400, 'rotateRefreshTokens' => true],
            'providers' => [
                'github' => [
                    'on' => true,
                    'clientId' => 'gh_client_123',
                    'authorizeUrl' => 'https://github.com/login/oauth/authorize',
                    'redirectUri' => 'https://localhost/oauth/github/callback',
                    'scopes' => ['read:user', 'user:email'],
                ],
            ],
        ], $redis, $this->app->account);

        self::assertSame(['UserAuth'], $oauth->dependencies());
        self::assertTrue($oauth->store()->isRedisActive());

        // External provider authorization URL + CSRF state verification in Redis
        $authReq = $oauth->createProviderAuthorizationRequest('github');
        self::assertStringContainsString('https://github.com/login/oauth/authorize?', $authReq['url']);
        self::assertStringContainsString('code_challenge_method=S256', $authReq['url']);

        $statePayload = $oauth->consumeCallbackState($authReq['state']);
        self::assertSame('github', $statePayload['provider']);

        // Second attempt to consume the same state must fail (single-use atomic GETDEL)
        try {
            $oauth->consumeCallbackState($authReq['state']);
            self::fail('Expected replayed OAuth state to be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('state', $e->getMessage());
        }

        // Authorization Code + PKCE S256 token issuance
        $verifier = OAuthPkce::generateCodeVerifier(32);
        $challenge = OAuthPkce::computeS256Challenge($verifier);
        $code = $oauth->issueAuthorizationCode(501, 'spa-client', 'https://app.local/cb', $challenge, 'S256', ['profile', 'mcp']);

        $pair = $oauth->exchangeAuthorizationCode($code, 'spa-client', 'https://app.local/cb', $verifier);
        self::assertSame(501, $pair->userId);
        self::assertNotNull($oauth->verifyAccessToken($pair->accessToken, ['mcp']));
        self::assertNull($oauth->verifyAccessToken($pair->accessToken, ['admin_root']));

        // Refresh token rotation invalidates old refresh token immediately
        $rotatedPair = $oauth->refreshTokenPair($pair->refreshToken);
        self::assertNotSame($pair->accessToken, $rotatedPair->accessToken);
        try {
            $oauth->refreshTokenPair($pair->refreshToken);
            self::fail('Rotated refresh token must not be reusable.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('refresh token', $e->getMessage());
        }

        // Revocation
        self::assertTrue($oauth->revokeToken($rotatedPair->accessToken));
        self::assertNull($oauth->verifyAccessToken($rotatedPair->accessToken));
    }

    public function testOAuthIntegratesDirectlyWithUserAuthAccountAndBearerHydration(): void
    {
        $redis = new InMemoryRedisAdapter(true);
        $oauth = new OAuth([], $redis, $this->app->account);

        $user = new OAuthDummyUser();
        $user->id = 77;
        $user->email = 'dev@timonix.pl';

        $tokens = $oauth->loginUser($user, 'web-app', ['profile', 'email'], $this->app);
        self::assertFalse(Account::isGuest($this->app));
        self::assertSame($user, $this->app->user);
        self::assertSame(77, $this->app->session->get('user'));

        // Log out session, then re-hydrate user purely from OAuth Bearer token header!
        $this->app->account->logout($this->app);
        self::assertTrue(Account::isGuest($this->app));

        $claims = $oauth->authenticateFromHeader(
            'Bearer ' . $tokens->accessToken,
            $this->app,
            static function (int|string $id): OAuthDummyUser {
                $u = new OAuthDummyUser();
                $u->id = (int) $id;
                return $u;
            },
        );

        self::assertIsArray($claims);
        self::assertFalse(Account::isGuest($this->app));
        self::assertSame(77, $this->app->user?->id);
    }

    public function testOAuthSqlMigrationsUpAndDown(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $runner = new ModuleMigrationRunner($pdo);

        $applied = $runner->migrateUp('OAuth');
        self::assertSame([
            'OAuth:oa0001_create_oauth_clients',
            'OAuth:oa0002_create_oauth_identities',
            'OAuth:oa0003_create_oauth_tokens',
        ], $applied);

        $rolledBack = $runner->migrateDown('OAuth', 3);
        self::assertCount(3, $rolledBack);
    }
}
