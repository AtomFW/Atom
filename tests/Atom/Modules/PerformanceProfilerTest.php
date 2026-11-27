<?php

declare(strict_types=1);

namespace Tests\Atom\Modules;

use Atom\Atom;
use Atom\Config\Config;
use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;
use Atom\Modules\McpServer\McpServer;
use Atom\Modules\ModuleMigrationRunner;
use Atom\Modules\PerformanceProfiler\PerformanceProfiler;
use Atom\Router;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

final class PerformanceProfilerTest extends TestCase
{
    private string $tempDir;
    private ?Atom $previousApp = null;

    protected function setUp(): void
    {
        $this->tempDir = \dirname(__DIR__, 3) . '/runtime/temp/profiler_test_' . \uniqid();
        \mkdir($this->tempDir, 0777, true);

        if (isset(Atom::$app)) {
            $this->previousApp = Atom::$app;
        }
    }

    protected function tearDown(): void
    {
        $ref = new ReflectionClass(Config::class);
        $fromProp = $ref->getProperty('from');
        $fromProp->setValue(null, []);

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

    public function testDisabledProfilerIncursZeroProductionOverheadAndLeavesProbeNull(): void
    {
        /** @var Atom $app */
        $app = (new ReflectionClass(Atom::class))->newInstanceWithoutConstructor();
        $app->probe = null;
        $app->config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();

        $refCfg = new ReflectionClass(Config::class);
        $refCfg->getProperty('from')->setValue(null, [
            'profiler' => ['on' => false, 'default' => 'visual'],
        ]);

        $profiler = new PerformanceProfiler(['on' => false]);
        $profiler->boot($app);

        self::assertFalse($profiler->isEnabled());
        self::assertNull($app->probe);

        $computed = $profiler->measure('app.fastMath', static fn(): int => 21 * 2);
        self::assertSame(42, $computed);
        self::assertNull($profiler->lastTrace());
    }

    public function testBottleneckDetectionVisualHtmlAgentReportAndTimonixCloudTelemetry(): void
    {
        $ndjsonFile = $this->tempDir . '/timonix_telemetry.ndjson';
        $cloudPackets = [];

        $profiler = new PerformanceProfiler([
            'on' => true,
            'default' => 'timonix_cloud',
            'choices' => [
                'visual' => ['driver' => 'visual', 'exposeHttpDashboard' => true],
                'timonix_cloud' => [
                    'driver' => 'timonix_cloud',
                    'projectKey' => 'tmx_proj_atom86',
                    'writeNdjsonLog' => true,
                    'ndjsonPath' => $ndjsonFile,
                ],
            ],
            'thresholds' => [
                'warningSpanMs' => 2.0,
                'criticalSpanMs' => 8.0,
                'hotspotSharePercent' => 25.0,
                'memorySpikeBytes' => 65536,
                'slowQueryMs' => 1.5,
            ],
        ]);

        $profiler->setTimonixCloudExporter(static function (array $packet) use (&$cloudPackets): void {
            $cloudPackets[] = $packet;
        });

        /** @var Atom $app */
        $app = (new ReflectionClass(Atom::class))->newInstanceWithoutConstructor();
        $app->probe = null;
        $app->attachProbe($profiler);
        $app->request = Request::create(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/checkout']);
        $app->response = new Response();
        $app->router = new Router($app->request, $app->response);
        Atom::$app = $app;

        $app->router->get('/checkout', function () use ($profiler): string {
            // Simulate N+1 database queries (4 identical queries)
            for ($q = 0; $q < 4; $q++) {
                $dbSpan = $profiler->startSpan('db.findOne:order_items', 'database', __FILE__, __LINE__);
                \usleep(600);
                $profiler->endSpan($dbSpan);
            }

            // Simulate heavy Client Application bottleneck + Disk I/O + RAM allocation
            return $profiler->measure('app.checkout.generatePdfInvoice', function () use ($profiler): string {
                $profiler->recordDiskIo(readBytes: 131_072, writeBytes: 262_144);
                $buffer = \str_repeat('ATOM_INVOICE_ROW_', 8000);
                \usleep(10_000);
                return 'OK:' . \strlen($buffer);
            }, 'client_app');
        });

        $body = $app->handle();
        self::assertStringStartsWith('OK:', $body);

        $trace = $profiler->lastTrace();
        self::assertNotNull($trace);
        self::assertSame('/checkout', $trace->url);
        self::assertGreaterThan(0, $trace->totalDurationNs);
        self::assertSame(131_072, $trace->diskReadDeltaBytes);
        self::assertSame(262_144, $trace->diskWriteDeltaBytes);

        // Verify bottleneck detection caught both N+1 SQL and Client App Disk/Time hotspot
        $types = \array_column($trace->bottlenecks, 'type');
        self::assertContains('n_plus_one_query', $types);
        self::assertTrue(
            \in_array('disk_io_bottleneck', $types, true)
            || \in_array('io_wait_or_lock_bottleneck', $types, true)
            || \in_array('cpu_bound_bottleneck', $types, true),
        );

        // 1. Visual HTML Dashboard
        $html = $profiler->renderVisualReport();
        self::assertStringContainsString('Atom Bottleneck Profiler', $html);
        self::assertStringContainsString('app.checkout.generatePdfInvoice', $html);
        self::assertStringContainsString('db.findOne:order_items', $html);
        self::assertStringContainsString('CLIENT APP', $html);

        // 2. AI Agent Diagnostic Export (+ MCP Server integration)
        $agentReport = $profiler->exportForAgent();
        self::assertSame('atom.profiler.agent_diagnostics.v1', $agentReport['reportType']);
        self::assertGreaterThanOrEqual(8.0, $agentReport['summary']['clientAppSelfMs']);
        self::assertContains($agentReport['summary']['primaryCulpritScope'], ['client_app', 'core']);
        self::assertNotEmpty($agentReport['bottlenecks']);
        self::assertArrayHasKey('cpu', $agentReport['resources']);
        self::assertArrayHasKey('ram', $agentReport['resources']);
        self::assertArrayHasKey('disk', $agentReport['resources']);

        $mcp = (new McpServer(['on' => true]))->bindServices(profiler: $profiler);
        $mcpReply = $mcp->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 77,
            'method' => 'tools/call',
            'params' => ['name' => 'atom.profiler.analyze', 'arguments' => []],
        ]);
        self::assertSame('atom.profiler.agent_diagnostics.v1', $mcpReply['result']['structuredContent']['reportType']);

        // 3. Timonix Cloud Raw Telemetry Mode (compact schema + live callback + NDJSON file)
        self::assertCount(1, $cloudPackets);
        $cloud = $cloudPackets[0];
        self::assertSame('timonix.cloud.telemetry.v1', $cloud['schema']);
        self::assertSame('tmx_proj_atom86', $cloud['project']);
        self::assertSame(['GET', '/checkout', 200], $cloud['http']);
        self::assertNotEmpty($cloud['spans']);
        self::assertSame(14, \count($cloud['spans'][0]));
        self::assertFileExists($ndjsonFile);
    }

    public function testPerformanceProfilerSqlMigrationUpAndDown(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $runner = new ModuleMigrationRunner($pdo);

        self::assertSame(['PerformanceProfiler:pp0001_create_profiler_traces'], $runner->migrateUp('PerformanceProfiler'));
        self::assertSame(['PerformanceProfiler:pp0001_create_profiler_traces'], $runner->migrateDown('PerformanceProfiler', 1));
    }
}
