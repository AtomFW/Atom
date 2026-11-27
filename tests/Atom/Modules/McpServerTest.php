<?php

declare(strict_types=1);

namespace Tests\Atom\Modules;

use Atom\Cache\InMemoryRedisAdapter;
use Atom\Config\Config;
use Atom\HttpFoundation\Request;
use Atom\Modules\AtomCluster\AtomCluster;
use Atom\Modules\McpServer\McpServer;
use Atom\Modules\ModuleMigrationRunner;
use Atom\Modules\NameGenerator\Name;
use Atom\Modules\OAuth\OAuth;
use Atom\Support\DataRegistry;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class McpServerTest extends TestCase
{
    protected function setUp(): void
    {
        $ref = new ReflectionClass(Config::class);
        $fromProp = $ref->getProperty('from');
        $fromProp->setValue(null, [
            'app' => [
                'name' => 'AtomMcpApp',
                'security' => ['secretToken' => 'do-not-leak-me'],
            ],
            'database' => [
                'default' => 'mysql',
                'choices' => [
                    'mysql' => [
                        'driver' => 'mysql',
                        'host' => '127.0.0.1',
                        'password' => 'super_secret_db_pwd',
                    ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $ref = new ReflectionClass(Config::class);
        $fromProp = $ref->getProperty('from');
        $fromProp->setValue(null, []);
    }

    public function testMcpServerRejectsCallsWhenDisabledSettingIsFalse(): void
    {
        $mcp = new McpServer(['on' => false]);
        self::assertFalse($mcp->isEnabled());

        $resp = $mcp->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ]);

        self::assertIsArray($resp);
        self::assertSame(-32001, $resp['error']['code']);
        self::assertStringContainsString('disabled', $resp['error']['message']);
    }

    public function testMcpServerToolsResourcesRedactionPermissionsAndStdioTransport(): void
    {
        $redis = new InMemoryRedisAdapter(true);
        $registry = new DataRegistry(['jakaśAplikacjia.core.version' => '5.0.0']);
        $cluster = new AtomCluster([
            'clusterId' => 'mcp-cluster',
            'default' => 'redis',
            'choices' => ['redis' => ['driver' => 'redis', 'on' => true]],
            'node' => ['id' => 'mcp-node-1', 'role' => 'worker', 'endpoint' => 'https://mcp1.local'],
        ], $redis);
        $cluster->heartbeat();

        $oauth = new OAuth([], $redis);
        $agentPair = $oauth->issueTokenPair('agent-bot', 'mcp-client', ['mcp']);

        $mcp = (new McpServer([
            'on' => true,
            'auth' => [
                'on' => true,
                'apiKey' => 'mcp-static-key-999',
                'allowOAuthBearer' => true,
                'allowedIps' => '127.0.0.1',
            ],
            'permissions' => [
                'allowRead' => true,
                'allowWriteActions' => false,
                'allowTerminalCommands' => false,
                'allowClassInvocation' => false,
            ],
        ]))->bindServices($registry, $cluster, $oauth);

        // HTTP Authorization check: API key, OAuth 'mcp' Bearer token, and IP allowlist
        self::assertTrue($mcp->isClientAuthorized(Request::create(server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer mcp-static-key-999',
        ])));
        self::assertTrue($mcp->isClientAuthorized(Request::create(server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $agentPair->accessToken,
        ])));
        self::assertFalse($mcp->isClientAuthorized(Request::create(server: [
            'REMOTE_ADDR' => '192.168.99.99',
            'HTTP_AUTHORIZATION' => 'Bearer mcp-static-key-999',
        ])));

        // Initialize + Tools List
        $init = $mcp->handleMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        self::assertSame('2025-03-26', $init['result']['protocolVersion']);

        // Secret Redaction on `atom.config.get`
        $dbCfgCall = $mcp->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'atom.config.get', 'arguments' => ['path' => 'database.choices.mysql']],
        ]);
        $structured = $dbCfgCall['result']['structuredContent']['value'];
        self::assertSame('127.0.0.1', $structured['host']);
        self::assertSame('[REDACTED]', $structured['password']);

        // Write action must be blocked when allowWriteActions=false
        $blockedWrite = $mcp->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'atom.data.set', 'arguments' => ['path' => 'agent.flag', 'value' => true]],
        ]);
        self::assertSame(-32003, $blockedWrite['error']['code']);

        // Enable write & controlled class invocation, verify execution + denylist protection
        $mcpWritable = (new McpServer([
            'on' => true,
            'permissions' => [
                'allowRead' => true,
                'allowWriteActions' => true,
                'allowTerminalCommands' => true,
                'allowClassInvocation' => true,
            ],
        ]))->bindServices($registry, $cluster, $oauth);

        $setCall = $mcpWritable->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'atom.data.set', 'arguments' => ['path' => 'jakaśAplikacjia.agent.ready', 'value' => 'tak']],
        ]);
        self::assertTrue($setCall['result']['structuredContent']['ok']);
        self::assertSame('tak', $registry->get('jakaśAplikacjia.agent.ready'));

        $invokeCall = $mcpWritable->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atom.class.invoke',
                'arguments' => ['class' => Name::class, 'method' => 'generate', 'args' => [8, 'mcp_']],
            ],
        ]);
        self::assertStringStartsWith('mcp_', (string) $invokeCall['result']['structuredContent']['result']);

        // Blocked sensitive class (`EnvironmentVariables`) must still be forbidden!
        $deniedClass = $mcpWritable->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atom.class.invoke',
                'arguments' => ['class' => 'Atom\\Config\\EnvironmentVariables', 'method' => 'getEnvironmentVariable', 'args' => ['DB_PASSWORD']],
            ],
        ]);
        self::assertSame(-32003, $deniedClass['error']['code']);

        // STDIO Stream transport verification
        $in = \fopen('php://memory', 'r+');
        $out = \fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);

        try {
            \fwrite($in, \json_encode(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'resources/read', 'params' => ['uri' => 'atom://data/registry']]) . "\n");
            \rewind($in);

            $count = $mcpWritable->serveStdio($in, $out, 1);
            self::assertSame(1, $count);

            \rewind($out);
            $stdioReply = \json_decode((string) \stream_get_contents($out), true);
            self::assertIsArray($stdioReply);
            self::assertStringContainsString('5.0.0', $stdioReply['result']['contents'][0]['text']);
        } finally {
            \fclose($in);
            \fclose($out);
        }
    }

    public function testMcpServerSqlMigrationUpAndDown(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $runner = new ModuleMigrationRunner($pdo);

        self::assertSame(['McpServer:ms0001_create_mcp_audit_logs'], $runner->migrateUp('McpServer'));
        self::assertSame(['McpServer:ms0001_create_mcp_audit_logs'], $runner->migrateDown('McpServer', 1));
    }
}
