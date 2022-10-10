<?php

namespace Atom\core;

use Atom\core\DataBase\Database;
use Atom\core\exception\NotFoundException;
use Atom\core\HttpFoundation\Request;
use Atom\core\HttpFoundation\Response;
use Atom\core\Log\T4LOG;
use Atom\core\Report\Report;

class Atom
{
    const EVENT_BEFORE_REQUEST = 'beforeRequest';
    const EVENT_AFTER_REQUEST = 'afterRequest';

    protected array $eventListeners = [];

    public static Atom $app;
    public static string $ROOT_DIR;
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
    protected Report $report;
    public AtomReport $atomReport;
    public UserReport $userReport;

    public function __construct($rootDir, $config)
    {
        $this->user = null;
        $this->userClass = $config['userClass'];
        self::$ROOT_DIR = $rootDir;
        self::$app = $this;
        $this->log = new T4LOG(["logRootDir" => array_key_exists("logPath", $config) ? $config["logPath"] : self::$ROOT_DIR . DIRECTORY_SEPARATOR . "log" . DIRECTORY_SEPARATOR]); // temporary
        $this->report = new Report(execute: true, looger: $this->log);
        $this->atomReport = new AtomReport(report: $this->report);
        $this->userReport = new UserReport(report: $this->report);
        $this->request = new Request();
        $this->response = new Response();
        $this->router = new Router($this->request, $this->response, $this->log);
        $this->db = new Database($config['db']);
        $this->session = new Session();
        $this->view = new View();

        $userId = Atom::$app->session->get('user');
        if ($userId) {
            $key = $this->userClass::primaryKey();
            $this->user = $this->userClass::findOne([$key => $userId]);
        }
    }

    public static function isGuest()
    {
        return !self::$app->user;
    }

    public function login(UserModel $user)
    {
        $this->user = $user;
        $className = get_class($user);
        $primaryKey = $className::primaryKey();
        $value = $user->{$primaryKey};
        Atom::$app->session->set('user', $value);

        return true;
    }

    public function logout()
    {
        $this->user = null;
        self::$app->session->remove('user');
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
            call_user_func($callback);
        }
    }

    public function on($eventName, $callback)
    {
        $this->eventListeners[$eventName][] = $callback;
    }
}
