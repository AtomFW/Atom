<?php

declare(strict_types=1);

namespace Atom;

use Atom\Async\Async;
use Atom\Cache\MemoryCacheManager;
use Atom\Component\Cache\CacheWrapper;
use Atom\Component\Scheme\SchemeGenerate;
use Atom\Config\Config;
use Atom\Config\DotConfig;
use Atom\DataBase\Database;
use Atom\DateTime\DateTime;
use Atom\Detect\BotDetector;
use Atom\Dom\Dom;
use Atom\FileSytem\FileSystem;
use Atom\FileSytem\ResourcesPath;
use Atom\FileSytem\WebResourcesPath;
use Atom\Generate\Manifest\WebAppManifest;
use Atom\Head\Head;
use Atom\Head\HeadGenerate;
use Atom\HttpFoundation\BrowserDetector;
use Atom\HttpFoundation\ConnectionInformation;
use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;
use Atom\Log\LogEvent;
use Atom\Log\T4LOG;
use Atom\Modules\ErrorDebugger\ErrorDebugger;
use Atom\Modules\Modul;
use Atom\Modules\UserAuth\Account;
use Atom\Modules\UserAuth\UserModel;
use Atom\Security\ConnectionSaving;
use Atom\Security\SafetyDataStructureVariable;
use Atom\Security\ServerDataVariable;
use Atom\Session\Session;
use Atom\Shrink\Shrink;
use Atom\Support\CompositeRuntimeProbe;
use Atom\Support\DataRegistry;
use Atom\Support\RuntimeProbeInterface;
use RuntimeException;
use Throwable;

/**
 * Core application kernel for the Atom framework (PHP 8.6+ / Swoole & Microservice stateless ready).
 */
class Atom
{
    public const string EVENT_BEFORE_REQUEST = 'beforeRequest';
    public const string EVENT_AFTER_REQUEST = 'afterRequest';

    /** @var array<string, list<callable>> */
    protected array $eventListeners = [];

    /** @var array<string, mixed> */
    public static array $about = [];

    public static Atom $app;
    public static string $ROOT_DIR = '';
    public static string $ROOT_URI = '';

    public private(set) bool $isMicroservice = false;
    public private(set) bool $isNoDb = false;

    /** @var class-string<UserModel>|string */
    public string $userClass = '';
    public string $layout = 'main';
    public FileSystem $filesystem;
    public Router $router;
    public Request $request;
    public Response $response;
    public ?Controller $controller = null;
    public ?Database $db = null;
    public Session $session;
    public View $view;
    public ?UserModel $user = null;
    public T4LOG $log;
    public Config $config;
    public BrowserDetector $browserDetector;
    public ConnectionInformation $connectionInformation;
    public MemoryCacheManager $ramCache;
    private CacheWrapper $cacheAtom;
    public BotDetector $botDetector;
    public Dom $dom;
    public ?Account $account = null;
    public ?Shrink $shrink = null;
    public ResourcesPath $resourcesPath;
    public FileSystem $fileSystem;
    public Head $head;
    public WebResourcesPath $webResourcesPath;
    public string $scheme = '';
    public string $headTag = '';
    public WebAppManifest $webManifest;
    public DateTime $datetime;
    public ?SafetyDataStructureVariable $safetyDataStructureVariable = null;
    public int $connectionIpId = 0;
    public ?ServerDataVariable $serverDataVariable = null;

    /** @var array<string, mixed> */
    public array $currentServerData = [];
    public ?LogEvent $logEvent = null;
    public Modul $modul;
    public DataRegistry $data;
    public Async $async;
    public ?RuntimeProbeInterface $probe = null;
    public private(set) int $bootDurationNs = 0;

    public function __construct(string $rootDir)
    {
        $bootStartNs = \hrtime(true);
        $resolvedRoot = $rootDir !== '' ? \realpath($rootDir) : false;
        if ($resolvedRoot === false) {
            throw new RuntimeException('Invalid base path configuration specified.');
        }

        self::$ROOT_DIR = $resolvedRoot . \DIRECTORY_SEPARATOR;
        /** @var array<string, mixed> $about */
        $about = require __DIR__ . '/AboutAtom.php';
        self::$about = $about;

        $this->filesystem = new FileSystem();
        $this->fileSystem = $this->filesystem;
        $this->config = new Config(self::$ROOT_DIR, true);

        $appConfig = $this->config->get('app');
        $loggerConfig = $this->config->get('logger');
        $cacheConfig = $this->config->get('cache');

        $this->isMicroservice = self::resolveMicroserviceMode($appConfig);
        $dbConfig = $this->isMicroservice || self::resolveFlag($appConfig['noDB'] ?? false)
            ? []
            : $this->config->get('database');
        $this->isNoDb = $this->isMicroservice
            || self::resolveFlag($appConfig['noDB'] ?? false)
            || self::resolveFlag($dbConfig['noDB'] ?? false);

        $this->log = new T4LOG(['logRootDir' => $loggerConfig['driver']['path']]);
        self::$ROOT_URI = (string) ($appConfig['uri'] ?? '');

        $this->cacheAtom = new CacheWrapper();
        $this->cacheAtom->initFilesystemAdapter(
            ($cacheConfig['prefix'] ?? 'atom_') . 'atom',
            1000,
            $cacheConfig['driver']['path'] ?? (self::$ROOT_DIR . 'runtime/cache')
        );
        $this->cacheAtom->setLogger($this->log);
        $this->ramCache = MemoryCacheManager::fromConfig($cacheConfig);

        $this->datetime = new DateTime();
        $this->datetime::setGlobalDefine((object) [
            'date' => $appConfig['dateFormat'],
            'time' => $appConfig['timeFormat'],
            'locale' => $appConfig['locale'],
            'datetime' => $appConfig['datetimeFormat'],
            'timezone' => $appConfig['toTimezone'],
        ]);
        $this->datetime::setDefaultLocale();
        $this->datetime->setDefaultTimezone();
        $this->datetime::setMacro();

        self::$app = $this;
        $this->user = null;
        $this->account = null;

        if (!empty($appConfig['browserDetector'])) {
            $this->browserDetector = new BrowserDetector();
        }

        if (!empty($appConfig['connectionInformation']['on'])) {
            $this->connectionInformation = new ConnectionInformation(
                cache: $this->cacheAtom,
                logger: $this->log,
                extends: (bool) ($appConfig['connectionInformation']['extension'] ?? false),
            );
        }

        if (!empty($appConfig['botDetection']['on'])) {
            $this->botDetector = new BotDetector(
                logger: $this->log,
                isBotFile: self::$ROOT_DIR . 'Atom/Lib/Is_bot/is_bot.php',
                extends: (bool) ($appConfig['botDetection']['extension'] ?? false),
            );
        }

        $this->request = new Request();
        $this->response = new Response();
        $this->router = new Router($this->request, $this->response);
        $this->modul = new Modul($this->config->get('modul'), $this->filesystem, $this->log);

        $serverAddr = (string) ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');

        if ($this->isDatabaseEnabled()) {
            $this->db = new Database($this->datetime, [...$dbConfig['driver'], 'uri' => self::$ROOT_URI]);

            if (self::shouldAutoMigrate($appConfig, $dbConfig)) {
                $this->runAutomaticSqlMigrations($appConfig, $dbConfig);
            }

            $this->serverDataVariable = new ServerDataVariable($this->db, $this->cacheAtom, $this->datetime);
            $this->currentServerData = $this->serverDataVariable->get($serverAddr);
            $this->serverDataVariable->touchLastActiveAt($serverAddr);

            $this->safetyDataStructureVariable = new SafetyDataStructureVariable($this->db, $this->cacheAtom, 3600);

            if (\count($this->currentServerData) === 0) {
                $this->log->critical('No server data found; verify .env and database server configuration.');
                throw new RuntimeException('No data about the server.');
            }

            if (!empty($appConfig['connectionSaving']['on'])) {
                ConnectionSaving::changeDatabase($this->db);
                ConnectionSaving::changeDateTime($this->datetime);
                ConnectionSaving::changeServerId((int) $this->currentServerData['id']);
                $this->connectionIpId = ConnectionSaving::add(
                    isset($this->browserDetector) ? $this->browserDetector->toArray() : [],
                    isset($this->connectionInformation) ? $this->connectionInformation->toArray() : [],
                    isset($this->botDetector) ? $this->botDetector->toArray() : [],
                );
            }

            $this->session = new Session(config: $this->config->get('session'));

            if (!empty($appConfig['actionEventSaving']['on'])) {
                if ($this->connectionIpId <= 0) {
                    $this->log->critical('connectionSaving must be enabled when actionEventSaving is active.');
                    throw new RuntimeException('connectionSaving must be on');
                }

                $sessionUser = $this->session->get('user');
                $this->logEvent = new LogEvent(
                    database: $this->db,
                    log: $this->log,
                    datetime: $this->datetime,
                    cache: $this->safetyDataStructureVariable,
                    account: empty($sessionUser),
                    idUser: $sessionUser ? (int) $sessionUser : 1,
                    connectionIpId: $this->connectionIpId,
                    serverId: (int) $this->currentServerData['id'],
                );
            }
        } else {
            // Stateless microservice / noDB mode: zero database connections, log connections & events via T4LOG
            $this->db = null;
            $microserviceCfg = \is_array($appConfig['stateless']['microservice'] ?? null)
                ? $appConfig['stateless']['microservice']
                : [];

            $this->currentServerData = [
                'id' => 0,
                'ip' => $serverAddr,
                'hostname' => (string) (\gethostname() ?: 'localhost'),
                'serviceName' => (string) ($microserviceCfg['serviceName'] ?? ($appConfig['name'] ?? 'Atom')),
                'instanceId' => (string) ($microserviceCfg['instanceId'] ?? 'stateless'),
                'mode' => $this->isMicroservice ? 'microservice' : 'noDB',
            ];

            if (!empty($appConfig['connectionSaving']['on'])) {
                ConnectionSaving::changeDatabase(null);
                ConnectionSaving::logOnly(
                    $this->log,
                    isset($this->browserDetector) ? $this->browserDetector->toArray() : [],
                    isset($this->connectionInformation) ? $this->connectionInformation->toArray() : [],
                    isset($this->botDetector) ? $this->botDetector->toArray() : [],
                    $this->datetime,
                );
            }

            $this->session = new Session(config: $this->config->get('session'));

            if (!empty($appConfig['actionEventSaving']['on'])) {
                $sessionUser = $this->session->get('user');
                $this->logEvent = new LogEvent(
                    database: null,
                    log: $this->log,
                    datetime: $this->datetime,
                    cache: null,
                    account: empty($sessionUser),
                    idUser: $sessionUser ? (int) $sessionUser : 0,
                    connectionIpId: 0,
                    serverId: 0,
                    noDb: true,
                );
            }
        }

        if (!empty($appConfig['dom']['on'])) {
            $this->dom = new Dom();
        }

        $shrinkConfig = \is_array($appConfig['shrink'] ?? null) ? $appConfig['shrink'] : [];
        $this->resourcesPath = new ResourcesPath(self::$ROOT_DIR, $shrinkConfig);
        $this->webResourcesPath = new WebResourcesPath($appConfig['uri'], $shrinkConfig);
        $resPaths = $this->resourcesPath->all();
        $webResPaths = $this->webResourcesPath->all();

        $autoMinify = self::resolveFlag($shrinkConfig['autoMinify'] ?? false);
        if (!empty($shrinkConfig['on']) || $autoMinify) {
            $shrinkOptions = [
                ...$resPaths,
                ...$shrinkConfig,
            ];

            $this->shrink = new Shrink($this->log, $shrinkOptions);

            // Automatic CSS/JS minification runs ONLY when `shrink.autoMinify` is explicitly enabled (disabled by default)
            if ($autoMinify) {
                $this->shrink->autoScanCssDir($resPaths['resourcesCssDirPath']);
                $this->shrink->autoScanJsDir($resPaths['resourcesJsDirPath']);
                $this->shrink->save();
            }
        }

        if (!empty($appConfig['webManifest']['on'])) {
            $this->webManifest = new WebAppManifest([
                'name' => $appConfig['name'],
                'short_name' => $appConfig['name'],
                'start_url' => $appConfig['uri'],
                'theme_color' => $appConfig['webManifest']['colorTheme'],
                'background_color' => $appConfig['webManifest']['backgroundColor'],
                'description' => $appConfig['head']['description'],
                'lang' => $appConfig['locale'],
            ]);
            if (!$this->isMicroservice && !empty($appConfig['webManifest']['autoSave']) && !\file_exists($resPaths['webManifest'])) {
                $this->webManifest->saveToFile($resPaths['webManifest']);
            }
        }

        if (!empty($appConfig['scheme']['on'])) {
            $this->scheme = SchemeGenerate::autoGenerate(
                $appConfig['scheme'],
                (object) [
                    'title' => $appConfig['name'],
                    'uri' => $appConfig['uri'],
                    'lang' => $appConfig['locale'],
                    'image' => $webResPaths['resources'],
                ]
            );
        }

        if (!empty($appConfig['head']['on'])) {
            $this->head = new Head();
            if (!empty($appConfig['head']['autoGenerate'])) {
                $this->headTag = HeadGenerate::autoGenerate(
                    $this->head,
                    $appConfig['head'],
                    (object) [
                        'title' => $appConfig['name'],
                        'description' => $appConfig['head']['description'],
                        'uri' => $appConfig['uri'],
                        'canonical' => $this->request->getUrl(),
                        'image' => $webResPaths['resources'],
                        'manifest' => $webResPaths['webManifest'],
                        'script' => [
                            'scheme' => $this->scheme,
                        ],
                    ]
                );
            }
        }

        if ($this->scheme !== '' && !empty($appConfig['scheme']['automaticallyFreeMemory'])) {
            $this->scheme = '';
        }

        $asyncCfg = Config::isLoaded('async') ? $this->config->get('async') : [];
        $this->async = Async::fromConfig(\is_array($asyncCfg) ? $asyncCfg : []);

        $templateCfg = \is_array($appConfig['templateData'] ?? null) ? $appConfig['templateData'] : [];
        $this->data = new DataRegistry();
        $this->view = new View(
            autoInterpolate: (bool) ($templateCfg['on'] ?? true),
            escapeInterpolatedHtml: (bool) ($templateCfg['escapeHtml'] ?? true),
        );
        $this->modul->boot($this);
        $this->bootDurationNs = \hrtime(true) - $bootStartNs;
    }

    /**
     * Attaches a diagnostic probe (`PerformanceProfiler`, `TrafficInspector`, or custom probe).
     * When no probes are attached (`$this->probe === null`), production incurs zero overhead.
     */
    public function attachProbe(RuntimeProbeInterface $probe): void
    {
        $this->probe = CompositeRuntimeProbe::attach($this->probe, $probe);
    }

    /**
     * Developer-friendly dot-notation configuration shortcut on the Atom instance (`$app->dotConfig('atom.core.version')`).
     */
    public function dotConfig(string $path, mixed $default = null): mixed
    {
        return DotConfig::get($path, $default);
    }

    public function isMicroserviceMode(): bool
    {
        return $this->isMicroservice;
    }

    public function isNoDbMode(): bool
    {
        return $this->isNoDb;
    }

    public function isDatabaseEnabled(): bool
    {
        return !$this->isNoDb;
    }

    public function getDatabase(): Database
    {
        if ($this->db === null || $this->isNoDb) {
            throw new RuntimeException('Database access is disabled because Atom is running in stateless noDB / microservice mode.');
        }

        return $this->db;
    }

    /**
     * Stateless request handler returning the rendered response body (Swoole / FrankenPHP worker friendly).
     */
    public function handle(?Request $request = null, ?Response $response = null): string
    {
        if ($request !== null) {
            $this->request = $request;
        }
        if ($response !== null) {
            $this->response = $response;
        }

        $probe = $this->probe;
        $rootSpanId = '';
        $caughtException = null;
        $finalOutput = '';

        if ($probe !== null) {
            $probe->onRequestStart($this->request, $this->response);
            $probe->recordTraversedFile(__FILE__, 'kernel', __LINE__);
            $rootSpanId = $probe->startSpan('kernel.handle', 'core', __FILE__, __LINE__, [
                'method' => $this->request->getMethod(),
                'url' => $this->request->getUrl(),
            ]);
        }

        $this->triggerEvent(self::EVENT_BEFORE_REQUEST);
        try {
            $output = $this->router->resolve($this->request, $this->response);
            $finalOutput = \is_string($output) ? $output : (string) ($output ?? '');
            return $finalOutput;
        } catch (Throwable $e) {
            $caughtException = $e;
            $code = (int) $e->getCode();
            $resolvedStatus = ($code >= 400 && $code <= 599) ? $code : 500;
            $this->response->statusCode($resolvedStatus, $e::class . ': ' . $e->getMessage());

            if ($probe !== null) {
                $probe->recordTraversedFile($e->getFile(), 'exception', $e->getLine());
                $probe->onResponseStatusChanged(
                    $resolvedStatus,
                    'Exception ' . $e::class . ': ' . $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    null,
                );
            }

            $appCfg = $this->config->get('app');
            $isDebug = \is_array($appCfg) && !empty($appCfg['debug']);
            $debugger = $this->modul->get('ErrorDebugger');

            if ($isDebug && $debugger instanceof ErrorDebugger) {
                $finalOutput = $debugger->renderThrowable($e, $this->request);
                return $finalOutput;
            }

            $finalOutput = $this->router->renderView('_error', [
                'exception' => $e,
            ]);
            return $finalOutput;
        } finally {
            $this->controller = null;
            $this->triggerEvent(self::EVENT_AFTER_REQUEST);

            if ($probe !== null) {
                if ($rootSpanId !== '') {
                    $probe->endSpan($rootSpanId, ['status' => $this->response->getStatusCode()], $caughtException);
                }
                $probe->onRequestEnd($this->request, $this->response, $finalOutput, $caughtException);
            }
        }
    }

    public function run(): void
    {
        echo $this->handle();
    }

    public function triggerEvent(string $eventName): void
    {
        $callbacks = $this->eventListeners[$eventName] ?? [];
        foreach ($callbacks as $callback) {
            $callback();
        }
    }

    public function on(string $eventName, callable $callback): void
    {
        $this->eventListeners[$eventName][] = $callback;
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    public static function resolveMicroserviceMode(array $appConfig): bool
    {
        if (($appConfig['atomRole'] ?? '') === 'microservice') {
            return true;
        }

        if (\is_array($appConfig['stateless'] ?? null) && \is_array($appConfig['stateless']['microservice'] ?? null)) {
            if (self::resolveFlag($appConfig['stateless']['microservice']['on'] ?? false)) {
                return true;
            }
        }

        return self::resolveFlag($appConfig['microservice'] ?? false);
    }

    public static function resolveFlag(mixed $raw): bool
    {
        if (\is_bool($raw)) {
            return $raw;
        }

        if (\is_array($raw)) {
            return !empty($raw['on']);
        }

        return (bool) $raw;
    }

    /**
     * @param array<string, mixed> $appConfig
     * @param array<string, mixed> $dbConfig
     */
    public static function shouldAutoMigrate(array $appConfig, array $dbConfig = []): bool
    {
        if (self::resolveFlag($appConfig['autoMigrate'] ?? false)) {
            return true;
        }

        if (\is_array($dbConfig['migrations'] ?? null) && self::resolveFlag($dbConfig['migrations']['autoMigrate'] ?? false)) {
            return true;
        }

        return false;
    }

    /**
     * Executes pending core, enabled-module, and application SQL migrations when `autoMigrate` is enabled.
     *
     * @param array<string, mixed> $appConfig
     * @param array<string, mixed> $dbConfig
     */
    private function runAutomaticSqlMigrations(array $appConfig, array $dbConfig): void
    {
        if ($this->db === null || !$this->isDatabaseEnabled()) {
            return;
        }

        $autoCfg = \is_array($appConfig['autoMigrate'] ?? null) ? $appConfig['autoMigrate'] : [];
        $runCore = (bool) ($autoCfg['core'] ?? true);
        $runModules = (bool) ($autoCfg['modules'] ?? true);
        $runApp = (bool) ($autoCfg['app'] ?? true);

        $migrations = $this->db->getMigrations();
        $appliedCoreApp = $migrations->applyMigrations(includeCore: $runCore, includeApp: $runApp);

        $appliedModules = [];
        if ($runModules) {
            $prefix = (string) ($dbConfig['driver']['prefix'] ?? '');
            $runner = $this->modul->migrations($this->db->pdo, $prefix);
            foreach (\array_keys($this->modul->getLoadedModules()) as $moduleName) {
                $appliedModules = [...$appliedModules, ...$runner->migrateUp($moduleName)];
            }
        }

        if ($appliedCoreApp !== [] || $appliedModules !== []) {
            $this->log->info('Automatic SQL migrations executed on startup.', [
                'core_and_app' => $appliedCoreApp,
                'modules' => $appliedModules,
            ]);
        }
    }
}
