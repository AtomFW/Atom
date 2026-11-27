<?php

declare(strict_types=1);

namespace Atom\Modules\McpServer;

use Atom\Atom;
use Atom\Config\DotConfig;
use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;
use Atom\Modules\AtomCluster\AtomCluster;
use Atom\Modules\Contract\AtomModuleInterface;
use Atom\Modules\OAuth\OAuth;
use Atom\Modules\PerformanceProfiler\PerformanceProfiler;
use Atom\Modules\TrafficInspector\TrafficInspector;
use Atom\Support\DataRegistry;
use Atom\Terminal\Terminal;
use InvalidArgumentException;
use Override;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Official Model Context Protocol (`MCP`) JSON-RPC 2.0 Server module (`Atom\Modules\McpServer`).
 *
 * Allows authenticated AI agents to inspect, manage, and execute authorized operations inside Atom
 * over HTTP (`POST /_atom/mcp`) or CLI STDIO (`php atom.php mcp:serve`), gated by explicit settings
 * in `config/mcp.php` (`MCP_SERVER_ON`).
 */
final class McpServer implements AtomModuleInterface
{
    public const string VERSION = '1.0.0';

    private const int JSONRPC_PARSE_ERROR = -32700;
    private const int JSONRPC_INVALID_REQUEST = -32600;
    private const int JSONRPC_METHOD_NOT_FOUND = -32601;
    private const int JSONRPC_INVALID_PARAMS = -32602;
    private const int JSONRPC_SERVER_DISABLED = -32001;
    private const int JSONRPC_UNAUTHORIZED = -32002;
    private const int JSONRPC_FORBIDDEN = -32003;

    /** @var array<string, mixed> */
    private array $config;

    private bool $enabled;
    private string $serverName;
    private string $serverVersion;
    private string $protocolVersion;

    private bool $authRequired;
    private string $apiKey;
    private string $authHeader;
    private bool $allowOAuthBearer;

    /** @var list<string> */
    private array $allowedIps;

    private bool $allowRead;
    private bool $allowWriteActions;
    private bool $allowTerminalCommands;
    private bool $allowClassInvocation;

    /** @var list<string> */
    private array $allowedClassPrefixes;

    /** @var list<string> */
    private array $blockedClasses;

    /** @var list<string> */
    private array $redactKeys;

    private ?Atom $app = null;
    private ?DataRegistry $customDataRegistry = null;
    private ?AtomCluster $customCluster = null;
    private ?OAuth $customOAuth = null;
    private ?Terminal $customTerminal = null;
    private ?PerformanceProfiler $customProfiler = null;
    private ?TrafficInspector $customTrafficInspector = null;

    /**
     * @var array<string, array{
     *     name: string,
     *     description: string,
     *     inputSchema: array<string, mixed>,
     *     handler: callable(array<string, mixed>): mixed,
     *     requiresWrite: bool
     * }>
     */
    private array $tools = [];

    /**
     * @var array<string, array{
     *     uri: string,
     *     name: string,
     *     description: string,
     *     mimeType: string,
     *     reader: callable(): mixed
     * }>
     */
    private array $resources = [];

    /**
     * @var array<string, array{
     *     name: string,
     *     description: string,
     *     arguments: list<array<string, mixed>>,
     *     builder: callable(array<string, mixed>): list<array{role: string, content: array{type: string, text: string}}>
     * }>
     */
    private array $prompts = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        #[SensitiveParameter]
        array $options = [],
    ) {
        $this->config = $options;
        $this->applyConfiguration($options);
        $this->registerBuiltinCapabilities();
    }

    #[Override]
    public function getName(): string
    {
        return 'McpServer';
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

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function bindServices(
        ?DataRegistry $dataRegistry = null,
        ?AtomCluster $cluster = null,
        ?OAuth $oauth = null,
        ?Terminal $terminal = null,
        ?PerformanceProfiler $profiler = null,
        ?TrafficInspector $trafficInspector = null,
    ): self {
        if ($dataRegistry !== null) {
            $this->customDataRegistry = $dataRegistry;
        }
        if ($cluster !== null) {
            $this->customCluster = $cluster;
        }
        if ($oauth !== null) {
            $this->customOAuth = $oauth;
        }
        if ($terminal !== null) {
            $this->customTerminal = $terminal;
        }
        if ($profiler !== null) {
            $this->customProfiler = $profiler;
        }
        if ($trafficInspector !== null) {
            $this->customTrafficInspector = $trafficInspector;
        }

        return $this;
    }

    #[Override]
    public function boot(Atom $app): void
    {
        $this->app = $app;
        $mcpCfg = $app->config->get('mcp');
        if (\is_array($mcpCfg)) {
            $this->config = $mcpCfg;
            $this->applyConfiguration($mcpCfg);
        }

        if (!$this->enabled) {
            return;
        }

        $httpCfg = \is_array($this->config['choices']['http'] ?? null)
            ? $this->config['choices']['http']
            : (\is_array($this->config['driver'] ?? null) ? $this->config['driver'] : []);
        $httpPath = (string) ($httpCfg['path'] ?? '/_atom/mcp');
        $maxPayloadBytes = \clamp((int) ($httpCfg['maxPayloadBytes'] ?? 1_048_576), 1024, 16_777_216);

        $app->router->post($httpPath, function (Request $req, Response $res) use ($maxPayloadBytes): string {
            $res->setHeader('Content-Type', 'application/json; charset=utf-8');

            if (!$this->isClientAuthorized($req)) {
                $res->statusCode(401);
                return \json_encode(
                    $this->errorEnvelope(null, self::JSONRPC_UNAUTHORIZED, 'Unauthorized MCP agent request.'),
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
                ) ?: '{}';
            }

            $rawBody = $req->getRawBody();
            if (\strlen($rawBody) > $maxPayloadBytes) {
                $res->statusCode(413);
                return \json_encode(
                    $this->errorEnvelope(null, self::JSONRPC_INVALID_REQUEST, 'MCP payload exceeds configured maxPayloadBytes.'),
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
                ) ?: '{}';
            }

            $reply = $this->handleRawJsonRpc($rawBody);
            if ($reply === null) {
                $res->statusCode(202);
                return '';
            }

            return $reply;
        });
    }

    /**
     * Registers a custom MCP tool callable by AI agents.
     *
     * @param array<string, mixed> $inputSchema
     * @param callable(array<string, mixed>): mixed $handler
     */
    public function registerTool(
        string $name,
        string $description,
        array $inputSchema,
        callable $handler,
        bool $requiresWrite = false,
    ): self {
        if (\count($this->tools) >= 256 && !isset($this->tools[$name])) {
            $oldest = \array_key_first($this->tools);
            unset($this->tools[$oldest]);
        }

        $this->tools[$name] = [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $inputSchema,
            'handler' => $handler,
            'requiresWrite' => $requiresWrite,
        ];

        return $this;
    }

    /**
     * Registers a custom MCP resource URI (`atom://...`).
     *
     * @param callable(): mixed $reader
     */
    public function registerResource(
        string $uri,
        string $name,
        string $description,
        callable $reader,
        string $mimeType = 'application/json',
    ): self {
        if (\count($this->resources) >= 256 && !isset($this->resources[$uri])) {
            $oldest = \array_key_first($this->resources);
            unset($this->resources[$oldest]);
        }

        $this->resources[$uri] = [
            'uri' => $uri,
            'name' => $name,
            'description' => $description,
            'mimeType' => $mimeType,
            'reader' => $reader,
        ];

        return $this;
    }

    /**
     * Registers a custom MCP prompt template.
     *
     * @param list<array<string, mixed>> $arguments
     * @param callable(array<string, mixed>): list<array{role: string, content: array{type: string, text: string}}> $builder
     */
    public function registerPrompt(
        string $name,
        string $description,
        array $arguments,
        callable $builder,
    ): self {
        $this->prompts[$name] = [
            'name' => $name,
            'description' => $description,
            'arguments' => $arguments,
            'builder' => $builder,
        ];

        return $this;
    }

    /**
     * Verifies agent IP allowlist and Bearer API key / OAuth token on HTTP requests.
     */
    public function isClientAuthorized(Request $request): bool
    {
        if ($this->allowedIps !== [] && !\in_array('*', $this->allowedIps, true)) {
            $clientIp = $request->getClientIp('127.0.0.1');
            if (!\in_array($clientIp, $this->allowedIps, true)) {
                return false;
            }
        }

        if (!$this->authRequired) {
            return true;
        }

        $header = $request->getHeader($this->authHeader) ?? '';
        $token = \stripos(\trim($header), 'Bearer ') === 0
            ? \trim(\substr(\trim($header), 7))
            : \trim($header);

        if ($token === '') {
            return false;
        }

        if ($this->apiKey !== '' && \hash_equals($this->apiKey, $token)) {
            return true;
        }

        if ($this->allowOAuthBearer) {
            $oauth = $this->resolveOAuth();
            if ($oauth !== null && $oauth->verifyAccessToken($token, ['mcp']) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Processes a raw JSON-RPC 2.0 string (single message or batch) and returns the JSON response
     * (`null` for pure notifications).
     */
    public function handleRawJsonRpc(string $rawJson): ?string
    {
        if (\trim($rawJson) === '' || !\json_validate($rawJson)) {
            return \json_encode(
                $this->errorEnvelope(null, self::JSONRPC_PARSE_ERROR, 'Invalid JSON payload.'),
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            ) ?: null;
        }

        $decoded = \json_decode($rawJson, true);
        if (!\is_array($decoded) || $decoded === []) {
            return \json_encode(
                $this->errorEnvelope(null, self::JSONRPC_INVALID_REQUEST, 'Invalid JSON-RPC 2.0 request structure.'),
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            ) ?: null;
        }

        if (\array_is_list($decoded)) {
            $maxBatch = \clamp((int) ($this->config['choices']['http']['maxBatchSize'] ?? 32), 1, 128);
            if (\count($decoded) > $maxBatch) {
                return \json_encode(
                    $this->errorEnvelope(null, self::JSONRPC_INVALID_REQUEST, 'JSON-RPC batch size exceeds limit.'),
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
                ) ?: null;
            }

            $responses = [];
            foreach ($decoded as $item) {
                if (!\is_array($item)) {
                    $responses[] = $this->errorEnvelope(null, self::JSONRPC_INVALID_REQUEST, 'Malformed batch entry.');
                    continue;
                }
                $res = $this->handleMessage($item);
                if ($res !== null) {
                    $responses[] = $res;
                }
            }

            if ($responses === []) {
                return null;
            }

            return \json_encode($responses, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: null;
        }

        /** @var array<string, mixed> $decoded */
        $singleResponse = $this->handleMessage($decoded);
        if ($singleResponse === null) {
            return null;
        }

        return \json_encode($singleResponse, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: null;
    }

    /**
     * Handles a decoded JSON-RPC 2.0 request/notification associative array.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    public function handleMessage(array $message): ?array
    {
        $isNotification = !\array_key_exists('id', $message);
        $id = $message['id'] ?? null;

        if (($message['jsonrpc'] ?? '') !== '2.0' || !isset($message['method']) || !\is_string($message['method'])) {
            return $this->errorEnvelope($id, self::JSONRPC_INVALID_REQUEST, 'Missing jsonrpc="2.0" or string method.');
        }

        if (!$this->enabled) {
            return $isNotification
                ? null
                : $this->errorEnvelope($id, self::JSONRPC_SERVER_DISABLED, 'Atom MCP Server is disabled in settings (MCP_SERVER_ON=false).');
        }

        $method = $message['method'];
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($method === 'notifications/initialized' || \str_starts_with($method, 'notifications/')) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => $this->protocolVersion,
                    'capabilities' => [
                        'tools' => ['listChanged' => false],
                        'resources' => ['subscribe' => false, 'listChanged' => false],
                        'prompts' => ['listChanged' => false],
                    ],
                    'serverInfo' => [
                        'name' => $this->serverName,
                        'version' => $this->serverVersion,
                    ],
                ],
                'ping' => new \stdClass(),
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolsCall($params),
                'resources/list' => $this->handleResourcesList(),
                'resources/read' => $this->handleResourcesRead($params),
                'prompts/list' => $this->handlePromptsList(),
                'prompts/get' => $this->handlePromptsGet($params),
                default => null,
            };

            if ($result === null) {
                return $isNotification
                    ? null
                    : $this->errorEnvelope($id, self::JSONRPC_METHOD_NOT_FOUND, "MCP method not found: '{$method}'.");
            }

            if ($isNotification) {
                return null;
            }

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ];
        } catch (PermissionDeniedMcpException $e) {
            return $isNotification
                ? null
                : $this->errorEnvelope($id, self::JSONRPC_FORBIDDEN, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return $isNotification
                ? null
                : $this->errorEnvelope($id, self::JSONRPC_INVALID_PARAMS, $e->getMessage());
        } catch (Throwable $e) {
            return $isNotification
                ? null
                : $this->errorEnvelope($id, -32603, $e->getMessage());
        }
    }

    /**
     * Serves newline-delimited JSON-RPC 2.0 MCP messages over STDIO streams (`php atom.php mcp:serve`).
     *
     * @param resource $inputStream
     * @param resource $outputStream
     */
    public function serveStdio(mixed $inputStream, mixed $outputStream, int $maxMessages = 0): int
    {
        if (!$this->enabled) {
            throw new RuntimeException('Atom MCP Server is disabled in settings (MCP_SERVER_ON=false).');
        }

        if (!\is_resource($inputStream) || !\is_resource($outputStream)) {
            throw new InvalidArgumentException('Valid input and output streams are required for MCP STDIO transport.');
        }

        $maxLine = \clamp((int) ($this->config['choices']['stdio']['maxLineBytes'] ?? 1_048_576), 1024, 16_777_216);
        $processed = 0;

        while (!\feof($inputStream)) {
            $line = \fgets($inputStream, $maxLine);
            if ($line === false) {
                break;
            }

            $trimmed = \trim($line);
            if ($trimmed === '') {
                continue;
            }

            $reply = $this->handleRawJsonRpc($trimmed);
            if ($reply !== null) {
                \fwrite($outputStream, $reply . "\n");
                \fflush($outputStream);
            }

            $processed++;
            if ($maxMessages > 0 && $processed >= $maxMessages) {
                break;
            }
        }

        return $processed;
    }

    #[Override]
    public function registerCommands(Terminal $terminal): Terminal
    {
        return $terminal
            ->withCommand(
                'mcp:status',
                function (array $args, Terminal $term): int {
                    $term->table(
                        ['Property', 'Value'],
                        [
                            ['Server', $this->serverName . ' v' . $this->serverVersion],
                            ['Enabled (MCP_SERVER_ON)', $this->enabled ? 'YES' : 'NO (Disabled)'],
                            ['Protocol Version', $this->protocolVersion],
                            ['Registered Tools', (string) \count($this->tools)],
                            ['Registered Resources', (string) \count($this->resources)],
                            ['Allow Write Actions', $this->allowWriteActions ? 'Yes' : 'No'],
                            ['Allow Terminal / Classes', ($this->allowTerminalCommands ? 'Terminal ' : '') . ($this->allowClassInvocation ? 'Classes' : 'Read-Only')],
                        ],
                    );
                    return 0;
                },
                'Display Atom MCP Server activation status, permissions, and registered agent tools',
            )
            ->withCommand(
                'mcp:serve',
                function (array $args, Terminal $term): int {
                    if (!$this->enabled) {
                        $term->error('MCP Server is disabled. Enable MCP_SERVER_ON=true in .env or config/mcp.php.');
                        return 1;
                    }
                    if (!\defined('STDIN') || !\defined('STDOUT')) {
                        $term->error('STDIO streams are unavailable.');
                        return 1;
                    }
                    $this->serveStdio(\STDIN, \STDOUT);
                    return 0;
                },
                'Start the Atom MCP Server over STDIO JSON-RPC 2.0 for AI agents',
            );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyConfiguration(array $options): void
    {
        $this->enabled = (bool) ($options['on'] ?? false);

        $info = \is_array($options['serverInfo'] ?? null) ? $options['serverInfo'] : [];
        $this->serverName = (string) ($info['name'] ?? 'Atom MCP Server');
        $this->serverVersion = (string) ($info['version'] ?? self::VERSION);
        $this->protocolVersion = (string) ($info['protocolVersion'] ?? '2025-03-26');

        $auth = \is_array($options['auth'] ?? null) ? $options['auth'] : [];
        $this->authRequired = (bool) ($auth['on'] ?? true);
        $this->apiKey = (string) ($auth['apiKey'] ?? '');
        $this->authHeader = (string) ($auth['header'] ?? 'Authorization');
        $this->allowOAuthBearer = (bool) ($auth['allowOAuthBearer'] ?? true);

        $ipsRaw = $auth['allowedIps'] ?? '127.0.0.1,::1';
        $this->allowedIps = \is_array($ipsRaw)
            ? \array_values(\array_map(\trim(...), \array_map(\strval(...), $ipsRaw)))
            : \array_values(\array_filter(\array_map(\trim(...), \explode(',', (string) $ipsRaw)), static fn(string $s): bool => $s !== ''));

        $perms = \is_array($options['permissions'] ?? null) ? $options['permissions'] : [];
        $this->allowRead = (bool) ($perms['allowRead'] ?? true);
        $this->allowWriteActions = (bool) ($perms['allowWriteActions'] ?? false);
        $this->allowTerminalCommands = (bool) ($perms['allowTerminalCommands'] ?? false);
        $this->allowClassInvocation = (bool) ($perms['allowClassInvocation'] ?? false);

        $prefixes = $perms['allowedClassPrefixes'] ?? ['Atom\\', 'App\\'];
        $this->allowedClassPrefixes = \is_array($prefixes) ? \array_values(\array_map(\strval(...), $prefixes)) : ['Atom\\'];

        $blocked = $perms['blockedClasses'] ?? ['Atom\\Config\\LoadEnvironmentVariables', 'Atom\\Config\\EnvironmentVariables', 'PDO'];
        $this->blockedClasses = \is_array($blocked) ? \array_values(\array_map(\strval(...), $blocked)) : [];

        $redact = $options['redactKeys'] ?? ['password', 'secret', 'key', 'token', 'dsn', 'private', 'authorization'];
        $this->redactKeys = \is_array($redact) ? \array_values(\array_map(\strtolower(...), \array_map(\strval(...), $redact))) : [];
    }

    private function registerBuiltinCapabilities(): void
    {
        $this->registerTool(
            name: 'atom.status',
            description: 'Inspect Atom framework version, PHP 8.6 runtime, memory usage, ZTS status, and loaded modules.',
            inputSchema: [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
            handler: function (): array {
                return [
                    'framework' => DotConfig::get('atom.core.name', 'Atom'),
                    'version' => DotConfig::get('atom.core.version', '2.1.0'),
                    'php' => \PHP_VERSION,
                    'zts' => \PHP_ZTS === 1,
                    'memoryUsageMb' => \round(\memory_get_usage(true) / 1_048_576, 2),
                    'microserviceMode' => $this->app?->isMicroserviceMode() ?? false,
                    'loadedModules' => $this->app !== null ? \array_keys($this->app->modul->getLoadedModules()) : [],
                ];
            },
            requiresWrite: false,
        );

        $this->registerTool(
            name: 'atom.config.get',
            description: 'Read an Atom configuration value by dot-notation path with automatic secret redaction.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Dot-separated config key (e.g. atom.core.version)'],
                ],
                'required' => ['path'],
            ],
            handler: function (array $args): array {
                $path = (string) ($args['path'] ?? '');
                if ($path === '') {
                    throw new InvalidArgumentException("Parameter 'path' is required.");
                }
                if ($this->isRedactedKey($path)) {
                    return ['path' => $path, 'value' => '[REDACTED]'];
                }
                $val = DotConfig::get($path);
                return [
                    'path' => $path,
                    'value' => $this->redactRecursive($val),
                ];
            },
            requiresWrite: false,
        );

        $this->registerTool(
            name: 'atom.data.get',
            description: 'Read a dot-notation value from the Atom application DataRegistry.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string'],
                ],
                'required' => ['path'],
            ],
            handler: function (array $args): array {
                $path = (string) ($args['path'] ?? '');
                $bag = $this->resolveDataRegistry();
                return [
                    'path' => $path,
                    'exists' => $bag->has($path),
                    'value' => $bag->get($path),
                ];
            },
            requiresWrite: false,
        );

        $this->registerTool(
            name: 'atom.data.set',
            description: 'Assign a dot-notation key/value inside the Atom application DataRegistry (updates {{html}} bindings).',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string'],
                    'value' => ['description' => 'Any JSON-compatible value'],
                ],
                'required' => ['path', 'value'],
            ],
            handler: function (array $args): array {
                $path = (string) ($args['path'] ?? '');
                if ($path === '') {
                    throw new InvalidArgumentException("Parameter 'path' is required.");
                }
                $bag = $this->resolveDataRegistry();
                $bag->set($path, $args['value'] ?? null);
                return ['ok' => true, 'path' => $path, 'value' => $bag->get($path)];
            },
            requiresWrite: true,
        );

        $this->registerTool(
            name: 'atom.cluster.status',
            description: 'Inspect AtomCluster nodes, load balancer scores, Redis routing state, and Supervisor health.',
            inputSchema: [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
            handler: function (): array {
                $cluster = $this->resolveCluster();
                if ($cluster === null) {
                    return ['clusterEnabled' => false];
                }
                $nodes = $cluster->getNodes();
                return [
                    'clusterEnabled' => true,
                    'clusterId' => $cluster->getClusterId(),
                    'localNodeId' => $cluster->getLocalNodeId(),
                    'redisRoutingActive' => $cluster->isRedisRoutingActive(),
                    'supervisor' => $cluster->supervisor()->inspectCluster(\array_values($nodes)),
                    'nodes' => \array_map(static fn($n): array => $n->toArray(), $nodes),
                ];
            },
            requiresWrite: false,
        );

        $this->registerTool(
            name: 'atom.cluster.sync',
            description: 'Synchronize custom state across all AtomCluster nodes in real time.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string'],
                    'value' => ['description' => 'State payload to synchronize'],
                ],
                'required' => ['key', 'value'],
            ],
            handler: function (array $args): array {
                $cluster = $this->resolveCluster();
                if ($cluster === null) {
                    throw new RuntimeException('AtomCluster module is not active.');
                }
                $key = (string) ($args['key'] ?? '');
                $cluster->syncState($key, $args['value'] ?? null, $this->resolveDataRegistry());
                return ['ok' => true, 'key' => $key, 'viaRedis' => $cluster->isRedisRoutingActive()];
            },
            requiresWrite: true,
        );

        $this->registerTool(
            name: 'atom.terminal.run',
            description: 'Execute a registered Atom Terminal CLI command when permitted.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string'],
                    'args' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['command'],
            ],
            handler: function (array $args): array {
                if (!$this->allowTerminalCommands) {
                    throw new PermissionDeniedMcpException('Terminal command execution by MCP agents is disabled in config/mcp.php.');
                }
                $command = \trim((string) ($args['command'] ?? ''));
                $cmdArgs = \is_array($args['args'] ?? null)
                    ? \array_values(\array_map(\strval(...), $args['args']))
                    : [];

                $term = ($this->customTerminal ?? new Terminal(colorsEnabled: false))->withColors(false);
                $term->clearOutputBuffer();
                $exitCode = $term->run(['atom.php', $command, ...$cmdArgs]);

                return [
                    'exitCode' => $exitCode,
                    'output' => $term->getOutputBuffer(),
                    'returnValue' => $term->getLastReturnValue(),
                ];
            },
            requiresWrite: true,
        );

        $this->registerTool(
            name: 'atom.class.invoke',
            description: 'Invoke a public method on a whitelisted Atom\\* or App\\* class when permitted.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'class' => ['type' => 'string'],
                    'method' => ['type' => 'string'],
                    'args' => ['type' => 'array'],
                ],
                'required' => ['class', 'method'],
            ],
            handler: function (array $args): array {
                if (!$this->allowClassInvocation) {
                    throw new PermissionDeniedMcpException('Direct class invocation by MCP agents is disabled in config/mcp.php.');
                }

                $class = \ltrim(\trim((string) ($args['class'] ?? '')), '\\');
                $method = \trim((string) ($args['method'] ?? ''));
                $callArgs = \is_array($args['args'] ?? null) ? \array_values($args['args']) : [];

                if ($this->isClassBlocked($class)) {
                    throw new PermissionDeniedMcpException("Class '{$class}' is not permitted for MCP agent invocation.");
                }

                $term = $this->customTerminal ?? new Terminal(colorsEnabled: false);
                $result = $term->invokeAtomClass($class, $method, $callArgs);

                return [
                    'class' => $class,
                    'method' => $method,
                    'result' => $result,
                ];
            },
            requiresWrite: true,
        );

        $this->registerResource(
            uri: 'atom://about',
            name: 'Atom Framework Metadata',
            description: 'Core Atom framework version, PHP 8.6 target, and author details.',
            reader: static fn(): mixed => DotConfig::get('atom.core', []),
        );

        $this->registerResource(
            uri: 'atom://data/registry',
            name: 'Application DataRegistry',
            description: 'Current hierarchical dot-notation DataRegistry snapshot.',
            reader: fn(): array => $this->resolveDataRegistry()->toArray(),
        );

        $this->registerTool(
            name: 'atom.profiler.analyze',
            description: 'Retrieve structured CPU, RAM, Disk, SQL, and Bottleneck diagnostics from PerformanceProfiler for AI agent analysis.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'traceId' => ['type' => 'string', 'description' => 'Optional trace ID (defaults to latest profiled trace)'],
                ],
            ],
            handler: function (array $args): array {
                $profiler = $this->resolveProfiler();
                if ($profiler === null) {
                    return ['profilerEnabled' => false];
                }
                $tid = isset($args['traceId']) && \is_string($args['traceId']) && $args['traceId'] !== '' ? $args['traceId'] : null;
                return $profiler->exportForAgent($tid);
            },
            requiresWrite: false,
        );

        $this->registerTool(
            name: 'atom.traffic.inspect',
            description: 'Inspect inbound request origin, intent, sanitized headers/params, traversed PHP files, and outbound response reason from TrafficInspector.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'traceId' => ['type' => 'string', 'description' => 'Optional trace ID (defaults to latest request trace)'],
                ],
            ],
            handler: function (array $args): array {
                $inspector = $this->resolveTrafficInspector();
                if ($inspector === null) {
                    return ['trafficInspectorEnabled' => false];
                }
                $tid = isset($args['traceId']) && \is_string($args['traceId']) && $args['traceId'] !== '' ? $args['traceId'] : null;
                $trace = $tid !== null ? $inspector->getTrace($tid) : $inspector->lastTrace();
                return $trace !== null ? $trace->toArray() : ['empty' => true];
            },
            requiresWrite: false,
        );

        $this->registerResource(
            uri: 'atom://profiler/latest',
            name: 'Latest PerformanceProfiler Agent Report',
            description: 'Latest bottleneck, CPU, RAM, Disk, and span waterfall report.',
            reader: fn(): array => $this->resolveProfiler()?->exportForAgent() ?? ['profilerEnabled' => false],
        );

        $this->registerResource(
            uri: 'atom://traffic/latest',
            name: 'Latest TrafficInspector Trace',
            description: 'Latest HTTP request/response origin, traversed file trail, and response explanation.',
            reader: fn(): array => $this->resolveTrafficInspector()?->lastTrace()?->toArray() ?? ['trafficInspectorEnabled' => false],
        );

        $this->registerTool(
            name: 'atom.async.status',
            description: 'Inspect Atom Async Fiber, Io\\Poll\\Context, and Swoole coroutine runtime capabilities.',
            inputSchema: [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
            handler: fn(): array => ($this->app !== null && isset($this->app->async) ? $this->app->async : new \Atom\Async\Async())->diagnostics(),
            requiresWrite: false,
        );

        $this->registerPrompt(
            name: 'atom.diagnose',
            description: 'Guided diagnostic prompt for inspecting Atom runtime, cluster health, and module state.',
            arguments: [
                ['name' => 'focus', 'description' => 'Subsystem to inspect (cluster, config, oauth, exchange)', 'required' => false],
            ],
            builder: static function (array $args): array {
                $focus = (string) ($args['focus'] ?? 'general');
                return [
                    [
                        'role' => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => "Inspect Atom subsystem '{$focus}' using atom.status and atom.cluster.status tools and summarize health.",
                        ],
                    ],
                ];
            },
        );
    }

    /**
     * @return array{tools: list<array<string, mixed>>}
     */
    private function handleToolsList(): array
    {
        if (!$this->allowRead) {
            throw new PermissionDeniedMcpException('Read access is disabled for MCP agents.');
        }

        $list = [];
        foreach ($this->tools as $tool) {
            $list[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
        }

        return ['tools' => $list];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolsCall(array $params): array
    {
        $toolName = (string) ($params['name'] ?? '');
        if ($toolName === '' || !isset($this->tools[$toolName])) {
            throw new InvalidArgumentException("Unknown MCP tool: '{$toolName}'.");
        }

        $tool = $this->tools[$toolName];
        if ($tool['requiresWrite'] && !$this->allowWriteActions) {
            throw new PermissionDeniedMcpException(
                "MCP tool '{$toolName}' requires allowWriteActions=true in config/mcp.php."
            );
        }
        if (!$tool['requiresWrite'] && !$this->allowRead) {
            throw new PermissionDeniedMcpException('MCP read permission is disabled.');
        }

        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $output = ($tool['handler'])($arguments);
        $text = \is_string($output)
            ? $output
            : (\json_encode($output, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '');

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
            'structuredContent' => \is_array($output) ? $output : ['value' => $output],
            'isError' => false,
        ];
    }

    /**
     * @return array{resources: list<array<string, string>>}
     */
    private function handleResourcesList(): array
    {
        if (!$this->allowRead) {
            throw new PermissionDeniedMcpException('MCP read permission is disabled.');
        }

        $items = [];
        foreach ($this->resources as $r) {
            $items[] = [
                'uri' => $r['uri'],
                'name' => $r['name'],
                'description' => $r['description'],
                'mimeType' => $r['mimeType'],
            ];
        }

        return ['resources' => $items];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleResourcesRead(array $params): array
    {
        if (!$this->allowRead) {
            throw new PermissionDeniedMcpException('MCP read permission is disabled.');
        }

        $uri = (string) ($params['uri'] ?? '');
        if ($uri === '' || !isset($this->resources[$uri])) {
            throw new InvalidArgumentException("Unknown MCP resource URI: '{$uri}'.");
        }

        $res = $this->resources[$uri];
        $data = ($res['reader'])();
        $text = \is_string($data)
            ? $data
            : (\json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}');

        return [
            'contents' => [
                [
                    'uri' => $res['uri'],
                    'mimeType' => $res['mimeType'],
                    'text' => $text,
                ],
            ],
        ];
    }

    /**
     * @return array{prompts: list<array<string, mixed>>}
     */
    private function handlePromptsList(): array
    {
        $items = [];
        foreach ($this->prompts as $p) {
            $items[] = [
                'name' => $p['name'],
                'description' => $p['description'],
                'arguments' => $p['arguments'],
            ];
        }

        return ['prompts' => $items];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handlePromptsGet(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        if ($name === '' || !isset($this->prompts[$name])) {
            throw new InvalidArgumentException("Unknown MCP prompt: '{$name}'.");
        }

        $prompt = $this->prompts[$name];
        $args = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        return [
            'description' => $prompt['description'],
            'messages' => ($prompt['builder'])($args),
        ];
    }

    private function resolveDataRegistry(): DataRegistry
    {
        if ($this->customDataRegistry !== null) {
            return $this->customDataRegistry;
        }
        if ($this->app !== null && isset($this->app->data)) {
            return $this->app->data;
        }
        if (isset(Atom::$app) && isset(Atom::$app->data)) {
            return Atom::$app->data;
        }

        $this->customDataRegistry = new DataRegistry();
        return $this->customDataRegistry;
    }

    private function resolveCluster(): ?AtomCluster
    {
        if ($this->customCluster !== null) {
            return $this->customCluster;
        }
        if ($this->app !== null) {
            $mod = $this->app->modul->get('AtomCluster');
            if ($mod instanceof AtomCluster) {
                return $mod;
            }
        }

        return null;
    }

    private function resolveOAuth(): ?OAuth
    {
        if ($this->customOAuth !== null) {
            return $this->customOAuth;
        }
        if ($this->app !== null) {
            $mod = $this->app->modul->get('OAuth');
            if ($mod instanceof OAuth) {
                return $mod;
            }
        }

        return null;
    }

    private function resolveProfiler(): ?PerformanceProfiler
    {
        if ($this->customProfiler !== null) {
            return $this->customProfiler;
        }
        if ($this->app !== null) {
            $mod = $this->app->modul->get('PerformanceProfiler');
            if ($mod instanceof PerformanceProfiler) {
                return $mod;
            }
        }

        return null;
    }

    private function resolveTrafficInspector(): ?TrafficInspector
    {
        if ($this->customTrafficInspector !== null) {
            return $this->customTrafficInspector;
        }
        if ($this->app !== null) {
            $mod = $this->app->modul->get('TrafficInspector');
            if ($mod instanceof TrafficInspector) {
                return $mod;
            }
        }

        return null;
    }

    private function isClassBlocked(string $class): bool
    {
        foreach ($this->blockedClasses as $blocked) {
            if (\strcasecmp($class, \ltrim($blocked, '\\')) === 0) {
                return true;
            }
        }

        $prefixAllowed = \array_any(
            $this->allowedClassPrefixes,
            static fn(string $prefix): bool => \str_starts_with($class, \ltrim($prefix, '\\')),
        );

        return !$prefixAllowed;
    }

    private function isRedactedKey(string $key): bool
    {
        $lower = \strtolower($key);
        return \array_any(
            $this->redactKeys,
            static fn(string $needle): bool => $needle !== '' && \str_contains($lower, $needle),
        );
    }

    private function redactRecursive(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $k => $v) {
            if (\is_string($k) && $this->isRedactedKey($k)) {
                $sanitized[$k] = '[REDACTED]';
                continue;
            }
            $sanitized[$k] = $this->redactRecursive($v);
        }

        return $sanitized;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorEnvelope(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
