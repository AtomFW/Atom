<?php

declare(strict_types=1);

namespace Tests\Atom\Kernel;

use Atom\Atom;
use Atom\Config\Config;
use Atom\DataBase\DbModel;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

final class SampleNoDbEntity extends DbModel
{
    public int $id = 1;

    #[\Override]
    public static function tableName(): string
    {
        return 'sample_entities';
    }
}

final class StatelessAndOptionsTest extends TestCase
{
    private string $sandboxRoot;
    private ?Atom $previousApp = null;

    protected function setUp(): void
    {
        $this->sandboxRoot = \dirname(__DIR__, 3) . '/runtime/temp/kernel_test_' . \uniqid();
        \mkdir($this->sandboxRoot . '/config', 0777, true);
        \mkdir($this->sandboxRoot . '/resources/css', 0777, true);
        \mkdir($this->sandboxRoot . '/resources/js', 0777, true);
        \mkdir($this->sandboxRoot . '/storage/assets/css', 0777, true);
        \mkdir($this->sandboxRoot . '/storage/assets/js', 0777, true);
        \mkdir($this->sandboxRoot . '/runtime/log', 0777, true);
        \mkdir($this->sandboxRoot . '/runtime/cache', 0777, true);

        \file_put_contents($this->sandboxRoot . '/resources/css/site.css', "body {\n    margin: 0;\n}\n");
        \file_put_contents($this->sandboxRoot . '/resources/js/site.js', "function ping() {\n    return 1;\n}\n");

        if (isset(Atom::$app)) {
            $this->previousApp = Atom::$app;
        }
    }

    protected function tearDown(): void
    {
        if ($this->previousApp instanceof Atom) {
            Atom::$app = $this->previousApp;
        }

        // Reset static Config::$from between sandbox runs
        $ref = new ReflectionClass(Config::class);
        $fromProp = $ref->getProperty('from');
        $fromProp->setValue(null, []);

        unset($_ENV['APP_MICROSERVICE'], $_ENV['APP_NO_DB'], $_ENV['DB_NO_DB']);

        if (\is_dir($this->sandboxRoot)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->sandboxRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $p = $file->getPathname();
                $file->isDir() ? \rmdir($p) : \unlink($p);
            }
            \rmdir($this->sandboxRoot);
        }
    }

    /**
     * @param array<string, mixed> $appOverrides
     */
    private function writeSandboxConfigs(array $appOverrides = [], string $extraEnv = ''): void
    {
        $baseApp = [
            'name' => 'AtomMicro',
            'env' => 'test',
            'debug' => true,
            'uri' => 'http://localhost',
            'timezone' => 'UTC',
            'toTimezone' => 'UTC',
            'datetimeFormat' => 'Y-m-d H:i',
            'dateFormat' => 'Y-m-d',
            'timeFormat' => 'H:i',
            'locale' => 'pl',
            'fallbackLocale' => 'en',
            'atomRole' => 'website',
            'noDB' => false,
            'browserDetector' => false,
            'connectionInformation' => ['on' => false, 'extension' => false],
            'botDetection' => ['on' => false, 'extension' => false],
            'dom' => ['on' => false],
            'shrink' => [
                'on' => true,
                'autoMinify' => false,
                'singleFile' => false,
                'autoMapResources' => true,
                'onlyRootDir' => false,
                'rootCssDirName' => 'global',
                'rootJsDirName' => 'global',
                'cssSingleFileName' => 'main',
                'jsSingleFileName' => 'main',
            ],
            'head' => ['on' => false, 'description' => 'Test'],
            'scheme' => ['on' => false, 'automaticallyFreeMemory' => true],
            'webManifest' => ['on' => false, 'autoSave' => false, 'colorTheme' => '#000', 'backgroundColor' => '#000'],
            'connectionSaving' => ['on' => true],
            'actionEventSaving' => ['on' => true],
            'autoMigrate' => ['on' => false, 'core' => true, 'modules' => true, 'app' => true],
            'stateless' => [
                'on' => true,
                'microservice' => [
                    'on' => false,
                    'serviceName' => 'orders-service',
                    'instanceId' => 'replica-1',
                ],
            ],
        ];

        $mergedApp = \array_replace_recursive($baseApp, $appOverrides);

        $configs = [
            'app' => $mergedApp,
            'database' => [
                'noDB' => (bool) ($mergedApp['noDB'] ?? false),
                'default' => 'mysql',
                'choices' => ['mysql' => ['driver' => 'mysql', 'dsn' => 'mysql:host=invalid', 'prefix' => '']],
                'migrations' => ['autoMigrate' => false, 'table' => 'migrations'],
            ],
            'cache' => [
                'prefix' => 'test_',
                'default' => 'file',
                'choices' => ['file' => ['driver' => 'file', 'path' => $this->sandboxRoot . '/runtime/cache']],
            ],
            'session' => [
                'default' => 'file',
                'newSessionWhenLogin' => true,
                'choices' => ['file' => ['driver' => 'file', 'sessionName' => 'ATOM_TEST', 'lifetime' => 3600]],
            ],
            'mail' => ['default' => 'smtp', 'choices' => ['smtp' => ['driver' => 'smtp']]],
            'auth' => [
                'default' => ['provider' => 'user'],
                'provider' => ['user' => ['driver' => 'native', 'model' => 'App\\Models\\User']],
            ],
            'filesystem' => ['default' => 'local', 'choices' => ['local' => ['driver' => 'local']]],
            'modul' => [
                'enabled' => [
                    'String' => ['on' => true],
                    'UserAuth' => ['on' => true],
                ],
                'required' => ['String'],
            ],
            'logger' => [
                'default' => 'log',
                'choices' => ['log' => ['driver' => 'log', 'path' => $this->sandboxRoot . '/runtime/log/']],
            ],
            'cluster' => [
                'on' => false,
                'default' => 'memory',
                'choices' => ['memory' => ['driver' => 'memory']],
            ],
            'oauth' => [
                'on' => false,
                'default' => 'memory',
                'choices' => ['memory' => ['driver' => 'memory']],
            ],
            'exchange' => [
                'on' => false,
                'default' => 'memory',
                'choices' => ['memory' => ['driver' => 'memory']],
            ],
            'mcp' => [
                'on' => false,
                'default' => 'http',
                'choices' => ['http' => ['driver' => 'http', 'path' => '/_atom/mcp']],
            ],
            'profiler' => [
                'on' => false,
                'default' => 'visual',
                'choices' => ['visual' => ['driver' => 'visual']],
            ],
            'inspector' => [
                'on' => false,
                'default' => 'memory',
                'choices' => ['memory' => ['driver' => 'memory']],
            ],
            'async' => [
                'on' => true,
                'default' => 'auto',
                'choices' => ['auto' => ['driver' => 'auto']],
            ],
        ];

        foreach ($configs as $name => $data) {
            $exported = \var_export($data, true);
            \file_put_contents($this->sandboxRoot . "/config/{$name}.php", "<?php\ndeclare(strict_types=1);\nreturn {$exported};\n");
        }

        \file_put_contents($this->sandboxRoot . '/.env', "APP_ENV=test\n{$extraEnv}");
    }

    public function testMicroserviceModeBootsWithoutDatabaseAndLogsConnectionsAndEventsToFiles(): void
    {
        $this->writeSandboxConfigs(
            ['stateless' => ['microservice' => ['on' => true]]],
            "APP_MICROSERVICE=true\n",
        );

        $app = new Atom($this->sandboxRoot);

        self::assertTrue($app->isMicroserviceMode());
        self::assertTrue($app->isNoDbMode());
        self::assertFalse($app->isDatabaseEnabled());
        self::assertNull($app->db);
        self::assertSame('microservice', $app->currentServerData['mode']);
        self::assertNotNull($app->logEvent);
        self::assertTrue($app->logEvent->isNoDb());

        // Event logging writes to standard T4LOG instead of DB
        self::assertSame(0, $app->logEvent->add('user', 1, 34, 39, 'Stateless microservice action'));

        $logFiles = \glob($this->sandboxRoot . '/runtime/log/*.log') ?: [];
        self::assertNotEmpty($logFiles);
        $logBody = (string) \file_get_contents($logFiles[0]);
        self::assertStringContainsString('Client connection recorded (noDB/stateless mode).', $logBody);
        self::assertStringContainsString('Stateless microservice action', $logBody);

        // Auto-minification did NOT run because shrink.autoMinify defaults to false
        self::assertFileDoesNotExist($this->sandboxRoot . '/storage/assets/css/site.min.css');
        self::assertFileDoesNotExist($this->sandboxRoot . '/storage/assets/js/site.min.js');

        // Database access via DbModel is strictly forbidden in microservice / noDB mode
        $this->expectException(RuntimeException::class);
        SampleNoDbEntity::findOne(['id' => 1]);
    }

    public function testNoDbSettingDisablesDatabaseAndAutoMinifyRunsOnlyWhenEnabled(): void
    {
        $this->writeSandboxConfigs(
            [
                'noDB' => true,
                'shrink' => [
                    'on' => true,
                    'autoMinify' => true,
                ],
            ],
            "APP_NO_DB=true\n",
        );

        $app = new Atom($this->sandboxRoot);

        self::assertFalse($app->isMicroserviceMode());
        self::assertTrue($app->isNoDbMode());
        self::assertFalse($app->isDatabaseEnabled());
        self::assertNull($app->db);

        // Because shrink.autoMinify was explicitly enabled, CSS & JS were minified
        self::assertFileExists($this->sandboxRoot . '/storage/assets/css/site.min.css');
        self::assertFileExists($this->sandboxRoot . '/storage/assets/js/site.min.js');

        $this->expectException(RuntimeException::class);
        $app->getDatabase();
    }

    public function testDefaultConfigDisablesAutoMinifyAndAutoMigrateByDefault(): void
    {
        $appConfig = require \dirname(__DIR__, 3) . '/config/app.php';
        $dbConfig = require \dirname(__DIR__, 3) . '/config/database.php';

        self::assertFalse($appConfig['noDB']);
        self::assertFalse($appConfig['stateless']['microservice']['on']);
        self::assertFalse($appConfig['shrink']['autoMinify']);
        self::assertFalse($appConfig['autoMigrate']['on']);
        self::assertFalse($dbConfig['migrations']['autoMigrate']);
        self::assertFalse(Atom::shouldAutoMigrate($appConfig, $dbConfig));
        self::assertTrue(Atom::shouldAutoMigrate(['autoMigrate' => ['on' => true]], []));
        self::assertTrue(Atom::shouldAutoMigrate([], ['migrations' => ['autoMigrate' => true]]));
    }
}
