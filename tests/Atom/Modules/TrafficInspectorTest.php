<?php

declare(strict_types=1);

namespace Tests\Atom\Modules;

use Atom\Atom;
use Atom\Config\Config;
use Atom\Controller;
use Atom\Exception\ForbiddenException;
use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;
use Atom\Middlewares\BaseMiddleware;
use Atom\Modules\McpServer\McpServer;
use Atom\Modules\ModuleMigrationRunner;
use Atom\Modules\PerformanceProfiler\PerformanceProfiler;
use Atom\Modules\TrafficInspector\TrafficInspector;
use Atom\Router;
use Atom\Support\DataRegistry;
use Atom\View;
use Override;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

final class DemoBlockingMiddleware extends BaseMiddleware
{
    #[Override]
    public function execute(): void
    {
        throw new ForbiddenException();
    }
}

final class DemoTrafficController extends Controller
{
    public function submitOrder(Request $req, Response $res): string
    {
        return $this->render('order_ok', ['orderRef' => 'ORD-2026']);
    }

    public function guardedRedirect(Request $req, Response $res): string
    {
        $res->redirect('/auth/login', 302);
        return '';
    }

    public function blockedPanel(Request $req, Response $res): string
    {
        return 'never-reached';
    }
}

final class TrafficInspectorTest extends TestCase
{
    private string $tempDir;
    private ?Atom $previousApp = null;

    protected function setUp(): void
    {
        $this->tempDir = \dirname(__DIR__, 3) . '/runtime/temp/traffic_test_' . \uniqid();
        \mkdir($this->tempDir . '/resources/Views/Layouts', 0777, true);

        \file_put_contents(
            $this->tempDir . '/resources/Views/Layouts/main.php',
            '<!doctype html><html><body><main>{{content}}</main></body></html>',
        );
        \file_put_contents(
            $this->tempDir . '/resources/Views/order_ok.php',
            '<h1>Order Confirmed: <?php echo htmlspecialchars((string) $orderRef, ENT_QUOTES); ?></h1>',
        );
        \file_put_contents(
            $this->tempDir . '/resources/Views/_error.php',
            '<h1>Error: <?php echo htmlspecialchars($exception->getMessage(), ENT_QUOTES); ?></h1>',
        );

        if (isset(Atom::$app)) {
            $this->previousApp = Atom::$app;
        }

        $refCfg = new ReflectionClass(Config::class);
        $refCfg->getProperty('from')->setValue(null, [
            'app' => ['debug' => false, 'templateData' => ['on' => true, 'escapeHtml' => true]],
        ]);
    }

    protected function tearDown(): void
    {
        $refCfg = new ReflectionClass(Config::class);
        $refCfg->getProperty('from')->setValue(null, []);

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

    public function testTrafficInspectorTracesOriginIntentRedactedPayloadTraversedFilesAndResponseReasons(): void
    {
        $inspector = new TrafficInspector(['on' => true]);
        $profiler = new PerformanceProfiler(['on' => true]);

        /** @var Atom $app */
        $app = (new ReflectionClass(Atom::class))->newInstanceWithoutConstructor();
        $app->layout = 'main';
        $app->probe = null;
        $app->data = new DataRegistry();
        $app->view = new View();
        $app->config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        $app->modul = (new ReflectionClass(\Atom\Modules\Modul::class))->newInstanceWithoutConstructor();

        // Attach BOTH probes via CompositeRuntimeProbe to verify seamless side-by-side operation
        $app->attachProbe($inspector);
        $app->attachProbe($profiler);

        Atom::$ROOT_DIR = $this->tempDir . \DIRECTORY_SEPARATOR;
        Atom::$app = $app;

        $req1 = Request::create(
            query: ['utm_source' => 'newsletter'],
            post: ['product_id' => '99', 'card_cvv' => '777', 'password' => 'topSecret!'],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/orders/submit?utm_source=newsletter',
                'REMOTE_ADDR' => '203.0.113.42',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.10, 203.0.113.42',
                'HTTP_REFERER' => 'https://shop.timonix.pl/cart',
                'HTTP_ORIGIN' => 'https://shop.timonix.pl',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/136.0',
                'HTTP_AUTHORIZATION' => 'Bearer secret-jwt-token',
                'HTTPS' => 'on',
            ],
            cookies: ['ATOM-SESSID' => 'sensitive_session_cookie'],
        );
        $res1 = new Response();
        $app->request = $req1;
        $app->response = $res1;
        $app->router = new Router($req1, $res1);

        $app->router->post('/orders/submit', [DemoTrafficController::class, 'submitOrder']);
        $app->router->get('/account/Billing', [DemoTrafficController::class, 'guardedRedirect']);

        $blockedCtrl = new DemoTrafficController();
        $blockedCtrl->registerMiddleware(new DemoBlockingMiddleware());
        $app->router->get('/admin/secret', [$blockedCtrl, 'blockedPanel']);

        // Case 1: 200 OK Controller -> View -> Layout cycle
        $htmlOut = $app->handle($req1, $res1);
        self::assertStringContainsString('Order Confirmed: ORD-2026', $htmlOut);

        $trace1 = $inspector->lastTrace();
        self::assertNotNull($trace1);
        self::assertSame('203.0.113.42', $trace1->inbound['clientIp']);
        self::assertSame(['198.51.100.10', '203.0.113.42'], $trace1->inbound['proxyChain']);
        self::assertSame('https://shop.timonix.pl/cart', $trace1->inbound['referer']);
        self::assertSame('authenticated_bearer_api', $trace1->inbound['intentCode']);

        // Sensitive secrets in headers, POST body, and cookies MUST be redacted
        self::assertSame('[REDACTED]', $trace1->inbound['headers']['Authorization']);
        self::assertSame('[REDACTED]', $trace1->inbound['bodyParams']['password']);
        self::assertSame('[REDACTED]', $trace1->inbound['bodyParams']['card_cvv']);
        self::assertSame('99', $trace1->inbound['bodyParams']['product_id']);
        self::assertSame('[REDACTED]', $trace1->inbound['cookies']['ATOM-SESSID']);

        // Traversed files must record Kernel -> Router -> Controller -> View -> Layout
        $roles = \array_column($trace1->routeAndFiles['traversedFiles'], 'role');
        self::assertContains('kernel', $roles);
        self::assertContains('router', $roles);
        self::assertContains('controller', $roles);
        self::assertContains('view', $roles);
        self::assertContains('layout', $roles);

        self::assertSame(200, $trace1->outbound['statusCode']);
        self::assertStringContainsString('DemoTrafficController::submitOrder', (string) $trace1->outbound['whyExplanation']);

        // Case 2: 302 Redirect response records exact redirect target, why, and caller line
        $req2 = Request::create(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/Billing']);
        $res2 = new Response();
        $app->handle($req2, $res2);

        $trace2 = $inspector->lastTrace();
        self::assertNotNull($trace2);
        self::assertSame(302, $trace2->outbound['statusCode']);
        self::assertSame('/auth/login', $trace2->outbound['headers']['Location']);
        self::assertStringContainsString('/auth/login', (string) $trace2->outbound['whyExplanation']);
        self::assertStringContainsString('TrafficInspectorTest.php', (string) $trace2->outbound['originSummary']);

        // Case 3: 403 Blocked by Middleware records middleware file + exception explanation
        $req3 = Request::create(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/secret']);
        $res3 = new Response();
        $app->handle($req3, $res3);

        $trace3 = $inspector->lastTrace();
        self::assertNotNull($trace3);
        self::assertSame(403, $trace3->outbound['statusCode']);
        self::assertStringContainsString('ForbiddenException', (string) $trace3->outbound['whyExplanation']);
        $roles3 = \array_column($trace3->routeAndFiles['traversedFiles'], 'role');
        self::assertContains('middleware', $roles3);
        self::assertContains('exception', $roles3);

        // Visual HTML & MCP inspection check
        $visualHtml = $inspector->renderVisualInspector($trace1->traceId);
        self::assertStringContainsString('Atom Traffic Inspector', $visualHtml);
        self::assertStringContainsString('DemoTrafficController::submitOrder', $visualHtml);
        self::assertStringNotContainsString('topSecret!', $visualHtml);

        $mcp = (new McpServer(['on' => true]))->bindServices(trafficInspector: $inspector);
        $mcpTraffic = $mcp->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 88,
            'method' => 'tools/call',
            'params' => ['name' => 'atom.traffic.inspect', 'arguments' => ['traceId' => $trace2->traceId]],
        ]);
        self::assertSame(302, $mcpTraffic['result']['structuredContent']['outbound']['statusCode']);
    }

    public function testTrafficInspectorSqlMigrationUpAndDown(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $runner = new ModuleMigrationRunner($pdo);

        self::assertSame(['TrafficInspector:ti0001_create_traffic_traces'], $runner->migrateUp('TrafficInspector'));
        self::assertSame(['TrafficInspector:ti0001_create_traffic_traces'], $runner->migrateDown('TrafficInspector', 1));
    }
}
