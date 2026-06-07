<?php

declare(strict_types=1);

namespace Atom;

use Atom\Cache\ApcuManager;
use Atom\Account\Account;
use Atom\Cache\SysVManager;
use Atom\Component\Scheme\SchemeGenerate;
use Atom\Config\Config;
use Atom\DataBase\Database;
use Atom\Exception\NotFoundException;
use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;
use Atom\Log\T4LOG;
use Atom\Component\Mail\MailerProxy;
use Atom\HttpFoundation\Header;
use Atom\HttpFoundation\BrowserDetector;
use Atom\HttpFoundation\ConnectionInformation;
use Atom\Component\Cache\CacheWrapper;
use Atom\FileSytem\Path;
use Atom\Detect\BotDetector;
use Atom\Images\ImageTransformer;
use Atom\Dom\Dom;
use Atom\FileSytem\ResourcesPath;
use Atom\Session\Session;
use Atom\Shrink\Shrink;
use Atom\FileSytem\FileSystem;
use Atom\Head\Head;
use Atom\Head\HeadGenerate;
use Atom\DateTime\DateTime;
use Atom\FileSytem\WebResourcesPath;
use Atom\Generate\Manifest\WebAppManifest;
use Atom\Log\LogEvent;
use Atom\Security\ConnectionSaving;
use Atom\Security\SafetyDataStructureVariable;
use Atom\Security\ServerDataVariable;
use Exception;

/**
 * Class Atom
 * @package Atom
 * @author Timonix <timonix@timonix.pl>
 * @version 2.0
 */
class Atom
{
    public const EVENT_BEFORE_REQUEST = 'beforeRequest';
    public const EVENT_AFTER_REQUEST = 'afterRequest';

    protected array $eventListeners = [];

    public static array $about;

    public static Atom $app;
    public static string $ROOT_DIR;
    public static string $ROOT_URI;
    public string $userClass;
    public string $layout = 'main';
    public Router $router;
    public Request $request;
    public Response $response;
    public ?Controller $controller = null;
    public Database $db;
    public Session $session;
    public View $view;
    public ?UserModel $user;
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
    public string $scheme;
    public string $headTag;
    public WebAppManifest $webManifest;
    public DateTime $datetime;
    public SafetyDataStructureVariable $safetyDataStructureVariable;
    public int $connectionIpId;
    public ServerDataVariable $serverDataVariable;
    public array $currentServerData;
    public LogEvent $logEvent;

    public function __construct(string $rootDir)
    {
        self::$ROOT_DIR = realpath($rootDir);
        
        if (!self::$ROOT_DIR) {
            die ("invalid base path configuration specified!!!!");
        }

        self::$ROOT_DIR .= DIRECTORY_SEPARATOR;

        self::$about = require_once __DIR__ . '/AboutAtom.php';
        $this->config = new Config(self::$ROOT_DIR, true);
        $this->log = new T4LOG(["logRootDir" => $this->config->get('logger')['driver']['path']]);
        Atom::$ROOT_URI = $this->config->get('app')["uri"];
        $this->cacheAtom = new CacheWrapper();
        $this->cacheAtom->initFilesystemAdapter(
            $this->config->get('cache')['prefix'] . "atom",
            1000,
            $this->config->get('cache')['driver']['path']
        );

        $this->datetime = new DateTime();
        $this->datetime::setGlobalDefine((object)[
            'date' => $this->config->get('app')['dateFormat'],
            'time' => $this->config->get('app')['timeFormat'],
            'locale' => $this->config->get('app')['locale'],
            'datetime' => $this->config->get('app')['datetimeFormat'],
            'timezone' => $this->config->get('app')['toTimezone']
        ]);
        $this->datetime::setDefaultLocale();
        $this->datetime->setDefaultTimezone();
        $this->datetime::setMacro();

        $this->cacheAtom->setLogger($this->log);
        self::$app = $this;
        $this->fileSystem = new FileSystem();
        $this->user = null;
        $this->userClass = $this->config->get('auth')['driver']['provider']['model'];

        if ($this->config->get('app')["browserDetector"]) {
            $this->browserDetector = new BrowserDetector();
        }
        if ($this->config->get('app')["connectionInformation"]['on']) {
            $this->connectionInformation = new ConnectionInformation(
                extends: $this->config->get('app')['connectionInformation']['extension'],
                cache: $this->cacheAtom,
                logger: $this->log
            );
        }
        if ($this->config->get('app')["botDetection"]['on']) {
            $this->botDetector = new BotDetector(
                logger: $this->log,
                isBotFile: self::$ROOT_DIR . "/Atom/Lib/Is_bot/is_bot.php",
                extends: $this->config->get('app')['botDetection']['extension']
            );
        }

        $this->request = new Request();
        $this->response = new Response();
        $this->router = new Router($this->request, $this->response);
        $this->db = new Database($this->datetime, [...$this->config->get('database')['driver'], 'uri' => Atom::$ROOT_URI]);

        $this->serverDataVariable = new ServerDataVariable($this->db, $this->cacheAtom, $this->datetime);
        $this->currentServerData = $this->serverDataVariable->get($_SERVER['SERVER_ADDR']);
        $this->serverDataVariable->touchLastActiveAt($_SERVER['SERVER_ADDR']); // touch last active at

        $this->safetyDataStructureVariable = new SafetyDataStructureVariable($this->db, $this->cacheAtom, 3600);

        if ($this->config->get('app')["connectionSaving"]['on']) {
            ConnectionSaving::changeDatabase($this->db);
            ConnectionSaving::changeDateTime($this->datetime);
            ConnectionSaving::changeServerId($this->currentServerData["id"]);
            $this->connectionIpId = ConnectionSaving::add($this->browserDetector->toArray(), $this->connectionInformation->toArray(), $this->botDetector->toArray());
        }

        $this->session = new Session(config: $this->config->get('session'));
        if ($this->config->get('app')["actionEventSaving"]['on']) {
            if (!isset($this->connectionIpId)) {
                $this->log->critical("connectionSaving must be on");
                throw new Exception("connectionSaving must be on");
            }

            $this->logEvent = new LogEvent(
                $this->db,
                $this->log,
                $this->datetime,
                $this->safetyDataStructureVariable,
                !$this->user,
                Atom::$app->session->get('user') ? Atom::$app->session->get('user') : 1,
                $this->connectionIpId,
                $this->currentServerData["id"]
            );
        }


        if ($this->config->get('app')["dom"]['on']) {
            $this->dom = new Dom();
        }
        $this->account = new Account();
        $this->resourcesPath = new ResourcesPath(self::$ROOT_DIR, $this->config->get('app')["shrink"]);
        $this->webResourcesPath = new WebResourcesPath($this->config->get('app')["uri"], $this->config->get('app')["shrink"]);
        if ($this->config->get('app')["shrink"]['on']) {
            $optinsShrink = [
                ...$this->resourcesPath->all(),
                ...$this->config->get('app')["shrink"]
            ];

            $this->shrink = new Shrink($this->log, $optinsShrink);
            $this->shrink->autoScanCssDir($this->resourcesPath->resourcesCssDirPath);
            $this->shrink->autoScanJsDir($this->resourcesPath->resourcesJsDirPath);
            $this->shrink->save();
        }

        if ($this->config->get('app')['webManifest']['on']) {
            $this->webManifest = new WebAppManifest([
                'name' => $this->config->get('app')['name'],
                'short_name' => $this->config->get('app')['name'],
                'start_url' => $this->config->get('app')['uri'],
                'theme_color' => $this->config->get('app')['webManifest']['colorTheme'],
                'background_color' => $this->config->get('app')['webManifest']['backgroundColor'],
                'description' => $this->config->get('app')['head']['description'],
                'lang' => $this->config->get('app')['locale'],
            ]);
            if ($this->config->get('app')['webManifest']['autoSave'] && !file_exists($this->resourcesPath->webManifest)) {
                $this->webManifest->saveToFile($this->resourcesPath->webManifest);
            }
        }

        if ($this->config->get('app')['scheme']['on']) {
            $this->scheme = SchemeGenerate::autoGenerate(
                $this->config->get('app')['scheme'],
                (object)[
                    'title' => $this->config->get('app')['name'],
                    'uri' => $this->config->get('app')['uri'],
                    'lang' => $this->config->get('app')['locale'],
                    'image' => $this->webResourcesPath->resources
                ]
            );

        }

        if ($this->config->get('app')['head']['on']) {
            $this->head = new Head();
            if ($this->config->get('app')['head']['autoGenerate']) {
                $this->headTag = HeadGenerate::autoGenerate(
                    $this->head,
                    $this->config->get('app')["head"],
                    (object)[
                        'title' => $this->config->get('app')['name'],
                        'description' => $this->config->get('app')['head']['description'],
                        'uri' => $this->config->get('app')['uri'],
                        'canonical' => $this->request->getUrl(),
                        'image' => $this->webResourcesPath->resources,
                        'manifest' => $this->webResourcesPath->webManifest,
                        'script' => [
                            'scheme' => $this->scheme
                        ]
                    ]
                );
            }
        }

        if (($this->scheme !== '') && $this->config->get('app')['scheme']['automaticallyFreeMemory']) {
            $this->scheme = '';
        }

        $this->view = new View();

        $userId = Atom::$app->session->get('user');

        if ($userId) {
            $key = $this->userClass::primaryKey();
            $this->user = $this->userClass::findOne([$key => $userId]);
        }

    }

    public function run()
    {
        $this->triggerEvent(self::EVENT_BEFORE_REQUEST);
        try {
            echo $this->router->resolve();
        } catch (\Exception $e) {
            echo $this->router->renderView('_error', [
                'exception' => $e,
            ]);
        }
    }

    public function triggerEvent($eventName)
    {
        $callbacks = $this->eventListeners[$eventName] ?? [];
        foreach ($callbacks as $callback) {
            \call_user_func($callback);
        }
    }

    public function on($eventName, $callback)
    {
        $this->eventListeners[$eventName][] = $callback;
    }
}
