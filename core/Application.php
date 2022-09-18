<?php
namespace Atom\core;

use Atom\core\DataBase\Database;
use Atom\core\DataBase\Migrations;
use Atom\core\HttpFoundation\Request;
use Atom\core\HttpFoundation\Response;
use Exception;

class Application
{

    const INSTALL_MOD_CONTROLLERS = 'controllers';
    const INSTALL_MOD_MIGRATIONS = 'migrations';
    const INSTALL_MOD_MODELS = 'models';
    const INSTALL_MOD_PUBLIC = 'public';
    const INSTALL_MOD_RUNTIME = 'runtime';
    const INSTALL_MOD_VIEWS = 'views';

    public static Application $app;
    public static string $ROOT_DIR;
    public Router $router;
    public Request $request;
    public Response $response;
    public Database $db;
    public Session $session;
    public View $view;

    public function __construct($rootDir, $config)
    {
        self::$ROOT_DIR = $rootDir;
        self::$app = $this;
        $this->request = new Request();
        $this->response = new Response();
        $this->router = new Router($this->request, $this->response);
        $this->db = new Database($config['db']);
        $this->session = new Session();
        $this->view = new View();

    }

    public function install($path)
    {
        $counter = 0;
        foreach ($path as $key => $value) {
            if (file_exists($value)) {
                $counter++;
            }
        }

        if ($counter !== 0) {
            return "Atom Aplication is exists";
        }

        return $this->exec($path);
    }

    private function exec(array $path)
    {
        foreach ($path as $key => $value) {
            copy($value, $this->ROOT_DIR);
        }

        return "New Atom Aplication is created!";
    }

    public function newAplication (array $exec = []) {

        $dataSRC = [];

        if (count($exec) !== 0) {
        foreach ($exec as $key => $value) {
            if ($value === Application::INSTALL_MOD_CONTROLLERS) {
                array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_CONTROLLERS));
            }
            if ($value === Application::INSTALL_MOD_MIGRATIONS) {
                array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_MIGRATIONS));
            }
            if ($value === Application::INSTALL_MOD_MODELS) {
                array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_MODELS));
            }
            if ($value === Application::INSTALL_MOD_PUBLIC) {
                array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_PUBLIC));
            }
            if ($value === Application::INSTALL_MOD_RUNTIME) {
                array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_RUNTIME));
            }
            if ($value === Application::INSTALL_MOD_VIEWS) {
                array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_VIEWS));
            }
        }
        return $dataSRC;
    }

        array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_CONTROLLERS));
        array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_MIGRATIONS));
        array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_MODELS));
        array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_PUBLIC));
        array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_RUNTIME));
        array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_VIEWS));

        return $dataSRC;
    }

    private function getPath(string $namedPath)
    {
        if (file_exists($this->ROOT_DIR . $namedPath . DIRECTORY_SEPARATOR)) {
           return $this->ROOT_DIR . $namedPath . DIRECTORY_SEPARATOR;
        }

        throw new Exception("ATOM '$namedPath' Not Exist");
    }

}
