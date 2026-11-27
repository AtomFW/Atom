<?php

declare(strict_types=1);

namespace Atom\Modules\OAuth;

use Atom\Atom;
use Atom\Cache\Contract\RedisAdapterInterface;
use Atom\Cache\InMemoryRedisAdapter;
use Atom\Cache\RespRedisAdapter;
use Atom\Modules\Contract\AtomModuleInterface;
use Atom\Modules\UserAuth\Account;
use Atom\Modules\UserAuth\UserModel;
use Atom\Terminal\Terminal;
use InvalidArgumentException;
use Override;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;
use SensitiveParameter;
use Time\Duration;
use Uri\Rfc3986\Uri;

/**
 * Official `OAuth` 2.0 + PKCE authorization module (`Atom\Modules\OAuth`) seamlessly integrated
 * with `Atom\Modules\UserAuth` (`Account`, `UserModel`) and distributed Redis token/state storage.
 */
final class OAuth implements AtomModuleInterface
{
    public const string VERSION = '1.0.0';

    /** @var array<string, mixed> */
    private array $config;

    private OAuthStore $store;
    private Account $account;
    private Randomizer $randomizer;
    private Duration $stateTtl;
    private Duration $authCodeTtl;
    private Duration $accessTtl;
    private Duration $refreshTtl;
    private bool $pkceRequired;
    private bool $allowPlainPkce;
    private bool $rotateRefreshTokens;
    private bool $syncSession;
    private bool $hydrateBearerOnRequest;
    private string $authHeaderName;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        #[SensitiveParameter]
        array $options = [],
        ?RedisAdapterInterface $redisAdapter = null,
        ?Account $account = null,
    ) {
        $this->config = $options;
        $this->account = $account ?? new Account();
        $this->randomizer = new Randomizer(new Secure());

        $pkceCfg = \is_array($options['pkce'] ?? null) ? $options['pkce'] : [];
        $this->pkceRequired = (bool) ($pkceCfg['required'] ?? true);
        $this->allowPlainPkce = (bool) ($pkceCfg['allowPlainMethod'] ?? false);
        $this->stateTtl = Duration::fromSeconds(\clamp((int) ($pkceCfg['stateTtlSeconds'] ?? 600), 30, 7200));
        $this->authCodeTtl = Duration::fromSeconds(\clamp((int) ($pkceCfg['authCodeTtlSeconds'] ?? 300), 30, 3600));

        $tokCfg = \is_array($options['tokens'] ?? null) ? $options['tokens'] : [];
        $this->accessTtl = Duration::fromSeconds(\clamp((int) ($tokCfg['accessTtlSeconds'] ?? 3600), 60, 86_400 * 30));
        $this->refreshTtl = Duration::fromSeconds(\clamp((int) ($tokCfg['refreshTtlSeconds'] ?? 2_592_000), 300, 86_400 * 365));
        $this->rotateRefreshTokens = (bool) ($tokCfg['rotateRefreshTokens'] ?? true);

        $uaCfg = \is_array($options['userAuth'] ?? null) ? $options['userAuth'] : [];
        $this->syncSession = (bool) ($uaCfg['syncSession'] ?? true);
        $this->hydrateBearerOnRequest = (bool) ($uaCfg['hydrateBearerOnRequest'] ?? true);
        $this->authHeaderName = (string) ($uaCfg['authorizationHeader'] ?? 'Authorization');

        $redisChoice = \is_array($options['choices']['redis'] ?? null)
            ? $options['choices']['redis']
            : (\is_array($options['driver'] ?? null) && ($options['driver']['driver'] ?? '') === 'redis' ? $options['driver'] : []);
        $redisOn = (bool) ($redisChoice['on'] ?? (($options['default'] ?? 'redis') === 'redis'));

        if ($redisAdapter !== null) {
            $resolvedAdapter = $redisAdapter;
        } elseif (($options['default'] ?? '') === 'memory') {
            $resolvedAdapter = new InMemoryRedisAdapter(true);
            $redisOn = true;
        } elseif ($redisOn && $redisChoice !== []) {
            $resolvedAdapter = new RespRedisAdapter($redisChoice);
        } else {
            $resolvedAdapter = null;
        }

        $this->store = new OAuthStore($resolvedAdapter, $redisOn);
    }

    #[Override]
    public function getName(): string
    {
        return 'OAuth';
    }

    #[Override]
    public function getVersion(): string
    {
        return self::VERSION;
    }

    #[Override]
    public function dependencies(): array
    {
        return ['UserAuth'];
    }

    public function store(): OAuthStore
    {
        return $this->store;
    }

    public function setRedisAdapter(?RedisAdapterInterface $adapter, bool $enabled = true): void
    {
        $this->store->setRedisAdapter($adapter, $enabled);
    }

    #[Override]
    public function boot(Atom $app): void
    {
        $oauthCfg = $app->config->get('oauth');
        if (!\is_array($oauthCfg) || empty($oauthCfg['on'])) {
            return;
        }

        $reconfigured = new self($oauthCfg, null, $app->account ?? $this->account);
        if (!$this->store->isRedisActive() && $reconfigured->store->isRedisActive()) {
            $this->store = $reconfigured->store;
        }
        $this->config = $oauthCfg;
        $this->pkceRequired = $reconfigured->pkceRequired;
        $this->allowPlainPkce = $reconfigured->allowPlainPkce;
        $this->stateTtl = $reconfigured->stateTtl;
        $this->authCodeTtl = $reconfigured->authCodeTtl;
        $this->accessTtl = $reconfigured->accessTtl;
        $this->refreshTtl = $reconfigured->refreshTtl;
        $this->rotateRefreshTokens = $reconfigured->rotateRefreshTokens;
        $this->syncSession = $reconfigured->syncSession;
        $this->hydrateBearerOnRequest = $reconfigured->hydrateBearerOnRequest;
        $this->authHeaderName = $reconfigured->authHeaderName;

        if ($app->account instanceof Account) {
            $this->account = $app->account;
        }

        if ($this->hydrateBearerOnRequest) {
            $app->on(Atom::EVENT_BEFORE_REQUEST, function () use ($app): void {
                if ($app->user !== null) {
                    return;
                }
                $header = $app->request->getHeader($this->authHeaderName);
                if ($header !== null && $header !== '') {
                    $this->authenticateFromHeader($header, $app);
                }
            });
        }
    }

    /**
     * Builds an RFC 7636 PKCE (`S256`) external OAuth2 provider authorization URL and persists
     * the anti-CSRF `state` + `codeVerifier` in Redis / `OAuthStore`.
     *
     * @param list<string> $extraScopes
     * @return array{url: string, state: string, codeVerifier: string, codeChallenge: string}
     */
    public function createProviderAuthorizationRequest(
        string $providerName,
        ?string $redirectUriOverride = null,
        array $extraScopes = [],
    ): array {
        $providers = \is_array($this->config['providers'] ?? null) ? $this->config['providers'] : [];
        $provider = $providers[$providerName] ?? null;

        if (!\is_array($provider) || empty($provider['authorizeUrl']) || empty($provider['clientId'])) {
            throw new InvalidArgumentException("OAuth provider '{$providerName}' is not configured.");
        }

        $state = \bin2hex($this->randomizer->getBytes(20));
        $verifier = OAuthPkce::generateCodeVerifier(32);
        $challenge = OAuthPkce::computeS256Challenge($verifier);

        $configuredScopes = \is_array($provider['scopes'] ?? null) ? $provider['scopes'] : [];
        $scopes = \array_values(\array_unique(\array_map(\strval(...), [...$configuredScopes, ...$extraScopes])));
        $redirectUri = $redirectUriOverride ?? (string) ($provider['redirectUri'] ?? '');

        $this->store->put('state', $state, [
            'provider' => $providerName,
            'codeVerifier' => $verifier,
            'redirectUri' => $redirectUri,
            'scopes' => $scopes,
            'issuedAt' => \time(),
        ], $this->stateTtl);

        $baseUri = new Uri((string) $provider['authorizeUrl']);
        $queryString = \http_build_query([
            'response_type' => 'code',
            'client_id' => (string) $provider['clientId'],
            'redirect_uri' => $redirectUri,
            'scope' => \implode(' ', $scopes),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', \PHP_QUERY_RFC3986);

        $separator = $baseUri->getRawQuery() !== null && $baseUri->getRawQuery() !== '' ? '&' : '?';
        $fullUrl = $baseUri->toRawString() . $separator . $queryString;

        return [
            'url' => $fullUrl,
            'state' => $state,
            'codeVerifier' => $verifier,
            'codeChallenge' => $challenge,
        ];
    }

    /**
     * Atomically verifies and consumes an OAuth callback `state` parameter (CSRF & replay protection).
     *
     * @return array<string, mixed>
     */
    public function consumeCallbackState(#[SensitiveParameter] string $state): array
    {
        if (\trim($state) === '') {
            throw new RuntimeException('Missing OAuth callback state parameter.');
        }

        $stored = $this->store->consume('state', $state);
        if ($stored === null) {
            throw new RuntimeException('Invalid, expired, or already-used OAuth state parameter.');
        }

        return $stored;
    }

    /**
     * Authenticates an existing `UserModel` via `UserAuth`'s `Account::login()` AND issues
     * a Redis-backed OAuth2 Access + Refresh token pair.
     *
     * @param list<string> $scopes
     */
    public function loginUser(
        UserModel $user,
        string $clientId = 'atom-first-party',
        array $scopes = ['profile', 'email'],
        ?Atom $app = null,
    ): OAuthTokenPair {
        $targetApp = $app ?? (isset(Atom::$app) ? Atom::$app : null);
        if ($this->syncSession && $targetApp !== null) {
            $this->account->login($user, $targetApp);
        }

        $pk = ($user::class)::primaryKey();
        /** @var int|string $userId */
        $userId = $user->{$pk};

        return $this->issueTokenPair($userId, $clientId, $scopes);
    }

    /**
     * Issues a single-use OAuth 2.0 Authorization Code bound to a PKCE challenge.
     *
     * @param list<string> $scopes
     */
    public function issueAuthorizationCode(
        int|string $userId,
        string $clientId,
        string $redirectUri,
        string $codeChallenge = '',
        string $codeChallengeMethod = 'S256',
        array $scopes = ['profile'],
    ): string {
        if ($this->pkceRequired && \trim($codeChallenge) === '') {
            throw new InvalidArgumentException('PKCE code_challenge is mandatory for OAuth authorization codes.');
        }

        $code = 'aco_' . \bin2hex($this->randomizer->getBytes(24));
        $this->store->put('code', $code, [
            'userId' => $userId,
            'clientId' => $clientId,
            'redirectUri' => $redirectUri,
            'codeChallenge' => $codeChallenge,
            'codeChallengeMethod' => $codeChallengeMethod,
            'scopes' => $scopes,
            'issuedAt' => \time(),
        ], $this->authCodeTtl);

        return $code;
    }

    /**
     * Exchanges a single-use Authorization Code + PKCE `code_verifier` for an Access + Refresh token pair.
     */
    public function exchangeAuthorizationCode(
        #[SensitiveParameter]
        string $code,
        string $clientId,
        string $redirectUri,
        #[SensitiveParameter]
        string $codeVerifier = '',
    ): OAuthTokenPair {
        $codeData = $this->store->consume('code', $code);
        if ($codeData === null) {
            throw new RuntimeException('Invalid, expired, or already redeemed OAuth authorization code.');
        }

        if (!\hash_equals((string) ($codeData['clientId'] ?? ''), $clientId)) {
            throw new RuntimeException('OAuth client_id mismatch during code exchange.');
        }

        if (!\hash_equals((string) ($codeData['redirectUri'] ?? ''), $redirectUri)) {
            throw new RuntimeException('OAuth redirect_uri mismatch during code exchange.');
        }

        $storedChallenge = (string) ($codeData['codeChallenge'] ?? '');
        $challengeMethod = (string) ($codeData['codeChallengeMethod'] ?? 'S256');

        if ($this->pkceRequired || $storedChallenge !== '') {
            if (!OAuthPkce::verifyChallenge($codeVerifier, $storedChallenge, $challengeMethod, $this->allowPlainPkce)) {
                throw new RuntimeException('OAuth PKCE code_verifier verification failed.');
            }
        }

        /** @var int|string $userId */
        $userId = $codeData['userId'];
        /** @var list<string> $scopes */
        $scopes = \is_array($codeData['scopes'] ?? null)
            ? \array_values(\array_map(\strval(...), $codeData['scopes']))
            : ['profile'];

        return $this->issueTokenPair($userId, $clientId, $scopes);
    }

    /**
     * Issues a new Access Token + Refresh Token pair stored in Redis / `OAuthStore`.
     *
     * @param list<string> $scopes
     */
    public function issueTokenPair(
        int|string $userId,
        string $clientId = 'atom-first-party',
        array $scopes = ['profile'],
    ): OAuthTokenPair {
        $now = \time();
        $accessToken = 'aat_' . \bin2hex($this->randomizer->getBytes(32));
        $refreshToken = 'art_' . \bin2hex($this->randomizer->getBytes(32));

        $this->store->put('access', $accessToken, [
            'userId' => $userId,
            'clientId' => $clientId,
            'scopes' => $scopes,
            'issuedAt' => $now,
            'expiresAt' => $now + $this->accessTtl->seconds,
        ], $this->accessTtl);

        $this->store->put('refresh', $refreshToken, [
            'userId' => $userId,
            'clientId' => $clientId,
            'scopes' => $scopes,
            'accessTokenHash' => \hash('sha256', $accessToken),
            'issuedAt' => $now,
            'expiresAt' => $now + $this->refreshTtl->seconds,
        ], $this->refreshTtl);

        return new OAuthTokenPair(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            userId: $userId,
            clientId: $clientId,
            accessTtl: $this->accessTtl,
            refreshTtl: $this->refreshTtl,
            issuedAt: $now,
            scopes: $scopes,
        );
    }

    /**
     * Rotates a Refresh Token and returns a fresh `OAuthTokenPair`.
     */
    public function refreshTokenPair(#[SensitiveParameter] string $refreshToken): OAuthTokenPair
    {
        $record = $this->rotateRefreshTokens
            ? $this->store->consume('refresh', $refreshToken)
            : $this->store->get('refresh', $refreshToken);

        if ($record === null) {
            throw new RuntimeException('Invalid, expired, or revoked OAuth refresh token.');
        }

        /** @var int|string $userId */
        $userId = $record['userId'];
        $clientId = (string) ($record['clientId'] ?? 'atom-first-party');
        /** @var list<string> $scopes */
        $scopes = \is_array($record['scopes'] ?? null)
            ? \array_values(\array_map(\strval(...), $record['scopes']))
            : ['profile'];

        return $this->issueTokenPair($userId, $clientId, $scopes);
    }

    /**
     * Introspects and validates an OAuth Bearer access token, optionally checking required scopes.
     *
     * @param list<string> $requiredScopes
     * @return array<string, mixed>|null
     */
    public function verifyAccessToken(#[SensitiveParameter] string $accessToken, array $requiredScopes = []): ?array
    {
        if (\trim($accessToken) === '') {
            return null;
        }

        $record = $this->store->get('access', $accessToken);
        if ($record === null) {
            return null;
        }

        if (isset($record['expiresAt']) && (int) $record['expiresAt'] < \time()) {
            $this->store->delete('access', $accessToken);
            return null;
        }

        $granted = \is_array($record['scopes'] ?? null) ? $record['scopes'] : [];
        foreach ($requiredScopes as $reqScope) {
            if (!\in_array($reqScope, $granted, true) && !\in_array('*', $granted, true)) {
                return null;
            }
        }

        return $record;
    }

    /**
     * Revokes an access or refresh token immediately across the cluster / Redis store.
     */
    public function revokeToken(#[SensitiveParameter] string $token): bool
    {
        $revokedAccess = $this->store->delete('access', $token);
        $revokedRefresh = $this->store->delete('refresh', $token);

        return $revokedAccess || $revokedRefresh;
    }

    /**
     * Extracts a Bearer token from an `Authorization` header, verifies it, and hydrates `$app->user`
     * compatibly with `UserAuth` (`UserModel` / `Account`).
     *
     * @param callable(int|string): ?UserModel|null $customUserResolver
     * @return array<string, mixed>|null
     */
    public function authenticateFromHeader(
        #[SensitiveParameter]
        string $authorizationHeader,
        ?Atom $app = null,
        ?callable $customUserResolver = null,
    ): ?array {
        $trimmed = \trim($authorizationHeader);
        if (\stripos($trimmed, 'Bearer ') !== 0) {
            return null;
        }

        $rawToken = \trim(\substr($trimmed, 7));
        $claims = $this->verifyAccessToken($rawToken);
        if ($claims === null) {
            return null;
        }

        $targetApp = $app ?? (isset(Atom::$app) ? Atom::$app : null);
        if ($targetApp === null) {
            return $claims;
        }

        /** @var int|string $userId */
        $userId = $claims['userId'];
        $resolvedUser = null;

        if ($customUserResolver !== null) {
            $resolvedUser = $customUserResolver($userId);
        } elseif ($targetApp->isDatabaseEnabled() && $targetApp->userClass !== '' && \is_subclass_of($targetApp->userClass, UserModel::class)) {
            $userClass = $targetApp->userClass;
            $pk = $userClass::primaryKey();
            $found = $userClass::findOne([$pk => $userId]);
            if ($found instanceof UserModel) {
                $resolvedUser = $found;
            }
        }

        if ($resolvedUser instanceof UserModel) {
            $targetApp->user = $resolvedUser;
            if ($this->syncSession && isset($targetApp->session)) {
                $targetApp->session->set('user', $userId);
            }
        }

        return $claims;
    }

    #[Override]
    public function registerCommands(Terminal $terminal): Terminal
    {
        return $terminal->withCommand(
            'oauth:status',
            function (array $args, Terminal $term): int {
                $providers = \is_array($this->config['providers'] ?? null) ? \array_keys($this->config['providers']) : ['github', 'google', 'discord'];
                $term->table(
                    ['Property', 'Value'],
                    [
                        ['Module', $this->getName() . ' v' . $this->getVersion()],
                        ['Token Store', $this->store->isRedisActive() ? 'Redis (Active)' : 'Bounded Memory Fallback'],
                        ['PKCE S256 Required', $this->pkceRequired ? 'Yes' : 'No'],
                        ['Refresh Rotation', $this->rotateRefreshTokens ? 'Enabled' : 'Disabled'],
                        ['Providers', \implode(', ', $providers)],
                        ['SQL Migrations', 'oa0001..oa0003 (Atom/Modules/OAuth/migration)'],
                    ],
                );
                return 0;
            },
            'Display OAuth 2.0 + PKCE module status, Redis store state, and configured providers',
        );
    }
}
