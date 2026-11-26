<?php

declare(strict_types=1);

namespace Atom;

use Atom\Account\Account;
use Atom\Component\Cache\CacheWrapper;
use Atom\Component\Scheme\SchemeGenerate;
use Atom\Config\Config;
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
use Atom\Modules\Modul;
use Atom\Security\ConnectionSaving;
use Atom\Security\SafetyDataStructureVariable;
use Atom\Security\ServerDataVariable;
use Atom\Session\Session;
use Atom\Shrink\Shrink;
use RuntimeException;
use Throwable;

/**
 * Core application kernel for the Atom framework (PHP 8.6+ / Swoole-ready).
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

    /** @var class-string<UserModel> */
    public string $userClass;
    public string $layout = 'main';
    public FileSystem $filesystem;
    public Router $router;
    public Request $request;
    public Response $response;
    public ?Controller $controller = null;
    public Database $db;
    public Session $session;
    public View $view;
    public ?UserModel $user = null;
    public T4LOG $log;
    public Config $config;
    public BrowserDetector $browserDetector;
    public ConnectionInformation $connectionInformation;
    private CacheWrapper $cacheAtom;
    public BotDetector $botDetector;
    public Dom $dom;
    public Account $account;
    public Shrink $shrink;
    public ResourcesPath $resourcesPath;
    public FileSystem $fileSystem;
    public Head $head;
    public WebResourcesPath $webResourcesPath;
    public string $scheme = '';
    public string $headTag = '';
    public WebAppManifest $webManifest;
    public DateTime $datetime;
    public SafetyDataStructureVariable $safetyDataStructureVariable;
    public int $connectionIpId = 0;
    public ServerDataVariable $serverDataVariable;

    /** @var array<string, mixed> */
    public array $currentServerData = [];
    public LogEvent $logEvent;
    public Modul $modul;

    public function __construct(string $rootDir)
    {
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
        $authConfig = $this->config->get('auth');
        $dbConfig = $this->config->get('database');

        $this->log = new T4LOG(['logRootDir' => $loggerConfig['driver']['path']]);
        self::$ROOT_URI = (string) ($appConfig['uri'] ?? '');

        $this->cacheAtom = new CacheWrapper();
        $this->cacheAtom->initFilesystemAdapter(
            ($cacheConfig['prefix'] ?? 'atom_') . 'atom',
            1000,
            $cacheConfig['driver']['path'] ?? (self::$ROOT_DIR . 'runtime/cache')
        );
        $this->cacheAtom->setLogger($this->log);

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
        $this->userClass = $authConfig['driver']['provider']['model'];

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
        $this->db = new Database($this->datetime, [...$dbConfig['driver'], 'uri' => self::$ROOT_URI]);

        $serverAddr = (string) ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
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
                $this->db,
                $this->log,
                $this->datetime,
                $this->safetyDataStructureVariable,
                empty($sessionUser),
                $sessionUser ? (int) $sessionUser : 1,
                $this->connectionIpId,
                (int) $this->currentServerData['id'],
            );
        }

        if (!empty($appConfig['dom']['on'])) {
            $this->dom = new Dom();
        }

        $this->account = new Account();
        $this->resourcesPath = new ResourcesPath(self::$ROOT_DIR, $appConfig['shrink']);
        $this->webResourcesPath = new WebResourcesPath($appConfig['uri'], $appConfig['shrink']);
        $resPaths = $this->resourcesPath->all();
        $webResPaths = $this->webResourcesPath->all();

        if (!empty($appConfig['shrink']['on'])) {
            $shrinkOptions = [
                ...$resPaths,
                ...$appConfig['shrink'],
            ];

            $this->shrink = new Shrink($this->log, $shrinkOptions);
            $this->shrink->autoScanCssDir($resPaths['resourcesCssDirPath']);
            $this->shrink->autoScanJsDir($resPaths['resourcesJsDirPath']);
            $this->shrink->save();
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
            if (!empty($appConfig['webManifest']['autoSave']) && !\file_exists($resPaths['webManifest'])) {
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

        $this->modul = new Modul($this->config->get('modul'), $this->filesystem, $this->log);
        $this->view = new View();

        $userId = $this->session->get('user');
        if ($userId) {
            $userClass = $this->userClass;
            $key = $userClass::primaryKey();
            $foundUser = $userClass::findOne([$key => $userId]);
            $this->user = $foundUser instanceof UserModel ? $foundUser : null;
        }
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

        $this->triggerEvent(self::EVENT_BEFORE_REQUEST);
        try {
            $output = $this->router->resolve($this->request, $this->response);
            return \is_string($output) ? $output : (string) ($output ?? '');
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            if ($code >= 400 && $code <= 599) {
                $this->response->statusCode($code);
            } else {
                $this->response->statusCode(500);
            }

            return $this->router->renderView('_error', [
                'exception' => $e,
            ]);
        } finally {
            $this->controller = null;
            $this->triggerEvent(self::EVENT_AFTER_REQUEST);
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
}
