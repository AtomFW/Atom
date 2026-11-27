<?php

declare(strict_types=1);

namespace Atom\Modules\TrafficInspector;

use Atom\Atom;
use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;
use Atom\Modules\Contract\AtomModuleInterface;
use Atom\Modules\UserAuth\UserModel;
use Atom\Support\RuntimeProbeInterface;
use Atom\Terminal\Terminal;
use Override;
use Random\Engine\Secure;
use Random\Randomizer;
use Throwable;

/**
 * Official `TrafficInspector` module (`Atom\Modules\TrafficInspector`) providing deep visual
 * forensics of incoming HTTP requests (where from, headers, params, payload, and *why* / purpose)
 * and outgoing HTTP responses (what went out, *why*, *from where* `file:line`, and every traversed file).
 *
 * Completely dormant (`Atom::$probe === null`, zero overhead) on production when `TRAFFIC_INSPECTOR_ON=false`.
 */
final class TrafficInspector implements AtomModuleInterface, RuntimeProbeInterface
{
    public const string VERSION = '1.0.0';

    /** @var array<int, string> */
    private const array HTTP_PHRASES = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found (Redirect)',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        413 => 'Payload Too Large',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    /** @var array<string, mixed> */
    private array $config;

    private bool $enabled;
    private int $maxTraces;
    private bool $trackIncludedFiles;
    private int $maxBodyPreviewBytes;
    private bool $exposeHttpDashboard;
    private string $dashboardPath;

    /** @var list<string> */
    private array $redactKeys;

    private TrafficInspectorRenderer $renderer;
    private Randomizer $randomizer;
    private ?Atom $app = null;

    /** @var array<string, TrafficTrace> Bounded FIFO buffer */
    private array $traces = [];

    private bool $bypassCurrentRequest = false;
    private int $activeStartNs = 0;
    private string $activeTraceId = '';
    private string $activeTimestampUtc = '';

    /** @var array<string, string> Map of `file => true` at request start */
    private array $baselineIncludedFiles = [];

    /** @var list<array{file: string, role: string, line: ?int}> */
    private array $activeTraversedFiles = [];

    /** @var array<string, true> */
    private array $seenTraversedKeys = [];

    /** @var array<string, array{name: string, category: string, file: ?string, line: ?int, startNs: int}> */
    private array $openStages = [];

    /** @var list<array{name: string, category: string, file: ?string, line: ?int, durationMs: float, error: ?string}> */
    private array $closedStages = [];

    private int $stageCounter = 0;

    /** @var array<string, mixed> */
    private array $activeRouteInfo = [];

    /** @var list<array{statusCode: int, reason: string, file: ?string, line: ?int, function: ?string}> */
    private array $activeStatusTimeline = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->config = $options;
        $this->renderer = new TrafficInspectorRenderer();
        $this->randomizer = new Randomizer(new Secure());
        $this->applyConfiguration($options);
    }

    #[Override]
    public function getName(): string
    {
        return 'TrafficInspector';
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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    #[Override]
    public function boot(Atom $app): void
    {
        $this->app = $app;
        $inspCfg = $app->config->get('inspector');
        if (\is_array($inspCfg)) {
            $this->config = $inspCfg;
            $this->applyConfiguration($inspCfg);
        }

        // Strict zero-overhead production guard
        if (!$this->enabled) {
            return;
        }

        $app->attachProbe($this);

        if ($this->exposeHttpDashboard) {
            $base = \rtrim($this->dashboardPath, '/');

            $app->router->get($base, function (Request $req, Response $res): string {
                $res->setHeader('Content-Type', 'text/html; charset=utf-8');
                $traceId = (string) ($req->getQueryParams()['trace'] ?? '');
                return $this->renderVisualInspector($traceId !== '' ? $traceId : null);
            });

            $app->router->get($base . '/json', function (Request $req, Response $res): string {
                $res->setHeader('Content-Type', 'application/json; charset=utf-8');
                $traceId = (string) ($req->getQueryParams()['trace'] ?? '');
                $trace = $traceId !== '' ? $this->getTrace($traceId) : $this->lastTrace();
                return \json_encode(
                    $trace !== null ? $trace->toArray() : ['empty' => true],
                    \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
                ) ?: '{}';
            });
        }
    }

    #[Override]
    public function onRequestStart(Request $request, Response $response): void
    {
        if (!$this->enabled) {
            return;
        }

        $url = $request->getUrl();
        if (\str_starts_with($url, \rtrim($this->dashboardPath, '/'))) {
            $this->bypassCurrentRequest = true;
            return;
        }

        $this->bypassCurrentRequest = false;
        $this->activeStartNs = \hrtime(true);
        $this->activeTraceId = \bin2hex($this->randomizer->getBytes(8));
        $this->activeTimestampUtc = \gmdate('Y-m-d\TH:i:s\Z');
        $this->activeTraversedFiles = [];
        $this->seenTraversedKeys = [];
        $this->openStages = [];
        $this->closedStages = [];
        $this->stageCounter = 0;
        $this->activeRouteInfo = [];
        $this->activeStatusTimeline = [];

        if ($this->trackIncludedFiles) {
            $this->baselineIncludedFiles = \array_fill_keys(\get_included_files(), '1');
        }
    }

    #[Override]
    public function startSpan(
        string $name,
        string $category = 'core',
        ?string $file = null,
        ?int $line = null,
        array $meta = [],
    ): string {
        if (!$this->enabled || $this->bypassCurrentRequest || $this->stageCounter >= 256) {
            return '';
        }

        $id = 'st_' . (++$this->stageCounter);
        $this->openStages[$id] = [
            'name' => $name,
            'category' => $category,
            'file' => $file,
            'line' => $line,
            'startNs' => \hrtime(true),
        ];

        return $id;
    }

    #[Override]
    public function endSpan(string $spanId, array $meta = [], ?Throwable $error = null): void
    {
        if ($spanId === '' || !isset($this->openStages[$spanId])) {
            return;
        }

        $st = $this->openStages[$spanId];
        unset($this->openStages[$spanId]);

        $durMs = \round(\max(0, \hrtime(true) - $st['startNs']) / 1_000_000, 3);
        $this->closedStages[] = [
            'name' => $st['name'],
            'category' => $st['category'],
            'file' => $st['file'],
            'line' => $st['line'],
            'durationMs' => $durMs,
            'error' => $error !== null ? ($error::class . ': ' . $error->getMessage()) : null,
        ];
    }

    #[Override]
    public function onRouteMatched(
        string $method,
        string $url,
        string $matchedPattern,
        string $handlerSummary,
        ?string $file = null,
        ?int $line = null,
        array $routeMeta = [],
    ): void {
        if (!$this->enabled || $this->bypassCurrentRequest) {
            return;
        }

        $this->activeRouteInfo = [
            'matchedPattern' => $matchedPattern,
            'handlerSummary' => $handlerSummary,
            'handlerFile' => $file,
            'handlerLine' => $line,
            'routeParams' => $routeMeta,
        ];
    }

    #[Override]
    public function recordTraversedFile(string $filePath, string $role = 'code', ?int $line = null): void
    {
        if (!$this->enabled || $this->bypassCurrentRequest || $filePath === '' || \count($this->activeTraversedFiles) >= 256) {
            return;
        }

        $dedupKey = $role . '|' . $filePath . '|' . ($line ?? 0);
        if (isset($this->seenTraversedKeys[$dedupKey])) {
            return;
        }

        $this->seenTraversedKeys[$dedupKey] = true;
        $this->activeTraversedFiles[] = [
            'file' => $filePath,
            'role' => $role,
            'line' => $line,
        ];
    }

    #[Override]
    public function onResponseStatusChanged(
        int $statusCode,
        string $reason,
        ?string $callerFile = null,
        ?int $callerLine = null,
        ?string $callerFunction = null,
    ): void {
        if (!$this->enabled || $this->bypassCurrentRequest || \count($this->activeStatusTimeline) >= 32) {
            return;
        }

        $this->activeStatusTimeline[] = [
            'statusCode' => $statusCode,
            'reason' => $reason,
            'file' => $callerFile,
            'line' => $callerLine,
            'function' => $callerFunction,
        ];
    }

    #[Override]
    public function onRequestEnd(
        Request $request,
        Response $response,
        string $responseBody,
        ?Throwable $uncaughtOrHandled = null,
    ): void {
        if (!$this->enabled || $this->bypassCurrentRequest || $this->activeStartNs === 0) {
            $this->bypassCurrentRequest = false;
            return;
        }

        $durationMs = \round(\max(0, \hrtime(true) - $this->activeStartNs) / 1_000_000, 3);
        $inbound = $this->buildInboundForensics($request);
        $routeAndFiles = $this->buildRouteAndFilesForensics();
        $outbound = $this->buildOutboundForensics($response, $responseBody, $uncaughtOrHandled);

        $trace = new TrafficTrace(
            traceId: $this->activeTraceId,
            timestampUtc: $this->activeTimestampUtc,
            durationMs: $durationMs,
            inbound: $inbound,
            routeAndFiles: $routeAndFiles,
            outbound: $outbound,
        );

        if (\count($this->traces) >= $this->maxTraces) {
            $oldest = \array_key_first($this->traces);
            if ($oldest !== null) {
                unset($this->traces[$oldest]);
            }
        }
        $this->traces[$trace->traceId] = $trace;
        $this->activeStartNs = 0;
    }

    public function lastTrace(): ?TrafficTrace
    {
        if ($this->traces === []) {
            return null;
        }

        return \array_last($this->traces);
    }

    public function getTrace(string $traceId): ?TrafficTrace
    {
        return $this->traces[$traceId] ?? null;
    }

    /**
     * @return list<TrafficTrace>
     */
    public function getTraces(): array
    {
        return \array_values($this->traces);
    }

    public function renderVisualInspector(?string $traceId = null): string
    {
        $trace = $traceId !== null ? $this->getTrace($traceId) : $this->lastTrace();
        if ($trace === null) {
            return '<!DOCTYPE html><html><body style="background:#080c14;color:#e2e8f0;font-family:sans-serif;padding:32px"><h2>Atom Traffic Inspector</h2><p>No HTTP traffic traces captured yet.</p></body></html>';
        }

        return $this->renderer->renderHtml($trace, \array_reverse($this->getTraces()));
    }

    #[Override]
    public function registerCommands(Terminal $terminal): Terminal
    {
        return $terminal->withCommand(
            'traffic:status',
            function (array $args, Terminal $term): int {
                $term->table(
                    ['Property', 'Value'],
                    [
                        ['Module', $this->getName() . ' v' . $this->getVersion()],
                        ['Enabled (TRAFFIC_INSPECTOR_ON)', $this->enabled ? 'YES' : 'NO (Zero Production Overhead)'],
                        ['Captured Traces', (string) \count($this->traces) . ' / ' . $this->maxTraces],
                        ['Visual Dashboard', $this->dashboardPath . ' (+ /json)'],
                        ['SQL Migrations', 'ti0001 (Atom/Modules/TrafficInspector/migration)'],
                    ],
                );
                return 0;
            },
            'Display TrafficInspector status, captured trace buffer, and endpoint routes',
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyConfiguration(array $options): void
    {
        $this->enabled = (bool) ($options['on'] ?? false);

        $driverCfg = \is_array($options['driver'] ?? null)
            ? $options['driver']
            : (\is_array($options['choices']['memory'] ?? null) ? $options['choices']['memory'] : []);
        $this->maxTraces = \clamp((int) ($driverCfg['maxTraces'] ?? 50), 1, 500);

        $cap = \is_array($options['capture'] ?? null) ? $options['capture'] : [];
        $this->trackIncludedFiles = (bool) ($cap['trackIncludedFiles'] ?? true);
        $this->maxBodyPreviewBytes = \clamp((int) ($cap['maxBodyPreviewBytes'] ?? 4096), 256, 131_072);
        $this->exposeHttpDashboard = (bool) ($cap['exposeHttpDashboard'] ?? true);
        $this->dashboardPath = (string) ($cap['dashboardPath'] ?? '/_atom/traffic');

        $redact = $cap['redactKeys'] ?? ['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'cookie', 'session', 'sessid', 'private_key', 'card', 'cvv'];
        $this->redactKeys = \is_array($redact) ? \array_values(\array_map(\strtolower(...), \array_map(\strval(...), $redact))) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInboundForensics(Request $request): array
    {
        $server = $request->getServerParams();
        $rawHeaders = $request->getHeaders();
        $method = \strtoupper($request->getMethod());
        $url = $request->getUrl();

        $clientIp = $request->getClientIp('127.0.0.1');
        $forwardedFor = $rawHeaders['X-Forwarded-For'] ?? $rawHeaders['Cf-Connecting-Ip'] ?? null;
        $proxyChain = $forwardedFor !== null
            ? \array_map(\trim(...), \explode(',', $forwardedFor))
            : [];

        $referer = $rawHeaders['Referer'] ?? null;
        $origin = $rawHeaders['Origin'] ?? null;
        $userAgent = $rawHeaders['User-Agent'] ?? '';
        $secFetchMode = $rawHeaders['Sec-Fetch-Mode'] ?? null;
        $secFetchSite = $rawHeaders['Sec-Fetch-Site'] ?? null;
        $secFetchDest = $rawHeaders['Sec-Fetch-Dest'] ?? null;

        $clientType = $this->classifyClient($url, $userAgent, $rawHeaders, $request->isJson());
        [$intentCode, $intentDescription] = $this->inferRequestPurpose(
            method: $method,
            url: $url,
            isJson: $request->isJson(),
            headers: $rawHeaders,
            secFetchMode: $secFetchMode,
            referer: $referer,
        );

        $actor = 'Guest';
        $targetApp = $this->app ?? (isset(Atom::$app) ? Atom::$app : null);
        if ($targetApp !== null && $targetApp->user instanceof UserModel) {
            $pk = ($targetApp->user::class)::primaryKey();
            $uid = $targetApp->user->{$pk} ?? '?';
            $actor = $targetApp->user->getDisplayName() . ' (ID: ' . (string) $uid . ')';
        }

        $rawBody = $request->getRawBody();
        $rawBodyBytes = \strlen($rawBody);

        return [
            'method' => $method,
            'url' => $url,
            'fullUri' => (string) ($server['REQUEST_URI'] ?? $url),
            'protocol' => (string) ($server['SERVER_PROTOCOL'] ?? 'HTTP/1.1'),
            'scheme' => (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http',
            'host' => $rawHeaders['Host'] ?? (string) ($server['SERVER_NAME'] ?? 'localhost'),
            'clientIp' => $clientIp,
            'proxyChain' => $proxyChain,
            'clientType' => $clientType,
            'actor' => $actor,
            'origin' => $origin,
            'referer' => $referer,
            'userAgent' => $userAgent,
            'fetchMetadata' => [
                'site' => $secFetchSite,
                'mode' => $secFetchMode,
                'dest' => $secFetchDest,
            ],
            'correlationId' => $rawHeaders['X-Correlation-Id'] ?? $rawHeaders['X-Request-Id'] ?? null,
            'intentCode' => $intentCode,
            'intentDescription' => $intentDescription,
            'headers' => $this->redactMap($rawHeaders),
            'routeParams' => $this->redactMap($request->getRouteParams()),
            'queryParams' => $this->redactMap($request->getQueryParams()),
            'bodyParams' => $this->redactMap($request->getBody()),
            'cookies' => $this->redactMap($request->getCookies()),
            'uploadedFiles' => $this->summarizeFiles($request->getFiles()),
            'rawBodyBytes' => $rawBodyBytes,
            'rawBodyPreview' => $this->sanitizeRawPreview($rawBody),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRouteAndFilesForensics(): array
    {
        $newlyIncluded = [];
        if ($this->trackIncludedFiles && $this->baselineIncludedFiles !== []) {
            foreach (\get_included_files() as $incFile) {
                if (!isset($this->baselineIncludedFiles[$incFile]) && \count($newlyIncluded) < 256) {
                    $newlyIncluded[] = [
                        'file' => $incFile,
                        'group' => $this->classifyFileGroup($incFile),
                    ];
                }
            }
        }

        return [
            'matchedPattern' => $this->activeRouteInfo['matchedPattern'] ?? null,
            'handlerSummary' => $this->activeRouteInfo['handlerSummary'] ?? 'Unmatched / Exception',
            'handlerFile' => $this->activeRouteInfo['handlerFile'] ?? null,
            'handlerLine' => $this->activeRouteInfo['handlerLine'] ?? null,
            'routeParams' => $this->activeRouteInfo['routeParams'] ?? [],
            'traversedFiles' => $this->activeTraversedFiles,
            'stages' => $this->closedStages,
            'newlyIncludedFiles' => $newlyIncluded,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOutboundForensics(
        Response $response,
        string $responseBody,
        ?Throwable $exception,
    ): array {
        $status = $response->getStatusCode();
        $phrase = self::HTTP_PHRASES[$status] ?? 'HTTP Status';
        $headers = $response->getHeaders();
        $contentType = $headers['Content-Type'] ?? $this->detectContentType($responseBody);
        $bodyBytes = \strlen($responseBody);

        $lastStatusChange = $this->activeStatusTimeline !== []
            ? \array_last($this->activeStatusTimeline)
            : null;

        $originFile = $lastStatusChange['file'] ?? $response->getStatusOriginFile() ?? ($this->activeRouteInfo['handlerFile'] ?? null);
        $originLine = $lastStatusChange['line'] ?? $response->getStatusOriginLine() ?? ($this->activeRouteInfo['handlerLine'] ?? null);
        $originFn = $lastStatusChange['function'] ?? ($this->activeRouteInfo['handlerSummary'] ?? null);

        $originSummary = $originFn !== null ? $originFn : 'Atom Kernel';
        if ($originFile !== null) {
            $originSummary .= ' (' . $originFile . ($originLine !== null ? ':' . $originLine : '') . ')';
        }

        $whyExplanation = $this->explainOutboundWhy($status, $response, $headers, $lastStatusChange, $exception);

        return [
            'statusCode' => $status,
            'statusPhrase' => $phrase,
            'whyExplanation' => $whyExplanation,
            'originFile' => $originFile,
            'originLine' => $originLine,
            'originFunction' => $originFn,
            'originSummary' => $originSummary,
            'statusTimeline' => $this->activeStatusTimeline,
            'contentType' => $contentType,
            'headers' => $this->redactMap($headers),
            'bodySizeBytes' => $bodyBytes,
            'bodyPreview' => \substr($responseBody, 0, $this->maxBodyPreviewBytes),
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function classifyClient(string $url, string $userAgent, array $headers, bool $isJson): string
    {
        if (\str_starts_with($url, '/_atom/mcp')) {
            return 'MCP AI Agent';
        }
        if (\str_starts_with($url, '/_atom/exchange')) {
            return 'Atom Cluster Peer';
        }
        if (isset($headers['X-Requested-With']) || $isJson) {
            return 'API / Fetch Client';
        }
        $uaLower = \strtolower($userAgent);
        if (\str_contains($uaLower, 'bot') || \str_contains($uaLower, 'spider') || \str_contains($uaLower, 'crawl')) {
            return 'Bot / Crawler';
        }
        if ($userAgent === '') {
            return 'CLI / Programmatic HTTP Client';
        }

        return 'Web Browser';
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: string, 1: string}
     */
    private function inferRequestPurpose(
        string $method,
        string $url,
        bool $isJson,
        array $headers,
        ?string $secFetchMode,
        ?string $referer,
    ): array {
        if ($method === 'OPTIONS') {
            return ['cors_preflight', "Kontrola uprawnień CORS Preflight przed właściwym wywołaniem zasobu {$url}."];
        }
        if (\str_starts_with($url, '/_atom/mcp')) {
            return ['mcp_agent_rpc', 'Zdalne wywołanie narzędzia/zasobu przez agenta AI w protokole Model Context Protocol (JSON-RPC 2.0).'];
        }
        if (\str_starts_with($url, '/_atom/exchange')) {
            return ['atom_cluster_exchange', 'Zaszyfrowana wymiana pakietów binarnych ATMX między serwerami Atom.'];
        }
        if (isset($headers['Authorization']) && \stripos($headers['Authorization'], 'Bearer ') === 0) {
            return ['authenticated_bearer_api', "Autoryzowane żądanie API z tokenem Bearer ({$method} {$url})."];
        }
        if ($isJson) {
            return ['json_api_request', "Żądanie API przesyłające dane JSON metodą {$method} do ścieżki {$url}."];
        }
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH' || $method === 'DELETE') {
            return ['state_mutation_form', "Przesłanie formularza lub zmiana stanu danych metodą {$method} na ścieżce {$url}."];
        }
        if ($secFetchMode === 'navigate' || $method === 'GET') {
            $refNote = $referer !== null ? " (przejście ze strony {$referer})" : ' (wejście bezpośrednie)';
            return ['page_or_resource_read', "Pobranie widoku lub zasobu metodą GET dla {$url}{$refNote}."];
        }

        return ['http_request', "Standardowe zapytanie HTTP {$method} do {$url}."];
    }

    /**
     * @param array<string, string> $headers
     * @param array{statusCode: int, reason: string, file: ?string, line: ?int, function: ?string}|null $lastStatusChange
     */
    private function explainOutboundWhy(
        int $status,
        Response $response,
        array $headers,
        ?array $lastStatusChange,
        ?Throwable $exception,
    ): string {
        if ($exception !== null) {
            return \sprintf(
                'Wystąpił wyjątek %s ("%s") w %s:%d, co spowodowało zwrot statusu HTTP %d.',
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $status,
            );
        }

        if ($status >= 300 && $status < 400 && isset($headers['Location'])) {
            return \sprintf(
                'Przekierowanie HTTP %d do adresu "%s" (%s).',
                $status,
                $headers['Location'],
                $response->getStatusReason(),
            );
        }

        if ($lastStatusChange !== null && $lastStatusChange['reason'] !== '') {
            return \sprintf('Status HTTP %d — %s.', $status, $lastStatusChange['reason']);
        }

        $handler = (string) ($this->activeRouteInfo['handlerSummary'] ?? 'kontroler/widok');
        return \sprintf('Pomyślne obsłużenie trasy przez %s ze statusem HTTP %d.', $handler, $status);
    }

    private function detectContentType(string $body): string
    {
        $trimmed = \ltrim($body);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[') && \json_validate($trimmed)) {
            return 'application/json';
        }
        if (\stripos($trimmed, '<!doctype html') === 0 || \stripos($trimmed, '<html') === 0 || \str_contains($trimmed, '</')) {
            return 'text/html; charset=utf-8';
        }

        return 'text/plain; charset=utf-8';
    }

    private function classifyFileGroup(string $path): string
    {
        $norm = \str_replace('\\', '/', $path);
        if (\str_contains($norm, '/tests/')) {
            return 'Test Suite';
        }
        if (\str_contains($norm, '/Atom/Modules/')) {
            return 'Module';
        }
        if (\str_contains($norm, '/Atom/')) {
            return 'Atom Core';
        }
        if (\str_contains($norm, '/resources/Views/')) {
            return 'View';
        }
        if (\str_contains($norm, '/app/')) {
            return 'Client App';
        }
        if (\str_contains($norm, '/config/')) {
            return 'Config';
        }

        return 'Vendor / PHP';
    }

    /**
     * @param array<int|string, mixed> $input
     * @return array<int|string, mixed>
     */
    private function redactMap(array $input, int $depth = 0): array
    {
        if ($depth > 5) {
            return ['...' => '[MAX_DEPTH]'];
        }

        $out = [];
        foreach ($input as $k => $v) {
            if (\is_string($k) && $this->isSensitiveKey($k)) {
                $out[$k] = '[REDACTED]';
                continue;
            }

            if (\is_array($v)) {
                $out[$k] = $this->redactMap($v, $depth + 1);
            } elseif (\is_string($v) && \strlen($v) > 512) {
                $out[$k] = \substr($v, 0, 512) . '...[truncated]';
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = \strtolower($key);
        return \array_any(
            $this->redactKeys,
            static fn(string $frag): bool => $frag !== '' && \str_contains($lower, $frag),
        );
    }

    private function sanitizeRawPreview(string $rawBody): string
    {
        if ($rawBody === '') {
            return '';
        }

        $slice = \substr($rawBody, 0, $this->maxBodyPreviewBytes);
        if (\json_validate($rawBody)) {
            $decoded = \json_decode($rawBody, true);
            if (\is_array($decoded)) {
                $redacted = $this->redactMap($decoded);
                $reencoded = \json_encode($redacted, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
                return \is_string($reencoded) ? \substr($reencoded, 0, $this->maxBodyPreviewBytes) : $slice;
            }
        }

        return $slice;
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private function summarizeFiles(array $files): array
    {
        $summary = [];
        foreach ($files as $field => $info) {
            if (\is_array($info)) {
                $summary[$field] = [
                    'name' => $info['name'] ?? null,
                    'type' => $info['type'] ?? null,
                    'size' => $info['size'] ?? null,
                    'error' => $info['error'] ?? null,
                ];
            }
        }

        return $summary;
    }
}
