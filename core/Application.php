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
	
	private array $AppPath = [];

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

    public function install()
    {
        $counter = 0;
        foreach ($this->AppPath as $key => $value) {
            if (file_exists(self::$ROOT_DIR . DIRECTORY_SEPARATOR . str_replace(__dir__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR, "", $value))) {
                $counter++;
            }
        }

        if ($counter !== 0) {
            echo "Atom Aplication is exists";
			return false;
        }

        if ( $this->exec($this->AppPath) ) {
			$counter = 0;
			foreach ($this->AppPath as $key => $value) {
				if (file_exists(self::$ROOT_DIR . DIRECTORY_SEPARATOR . str_replace(__dir__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR, "", $value))) {
					$counter++;
				}
			}
			 if ($counter !== count($this->AppPath)) {
				throw new Exception("Atom Aplication is Error Constuct", 1);
			}
			echo "New Atom Aplication is created!";
			return;
		}
		
		throw new Exception("Atom Aplication is Error Constuct", 1);
    }

    private function exec(array $path)
    {
		try {
			foreach ($path as $key => $value) {
				
				if (($value === ".") || ($value === "..")) {
					continue;
				}
				if (is_dir($value)) {
					if (!file_exists(self::$ROOT_DIR . DIRECTORY_SEPARATOR . str_replace(__dir__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR, "", $value))) {
						mkdir (self::$ROOT_DIR . DIRECTORY_SEPARATOR . str_replace(__dir__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR, "", $value));
					}
					$scan = scandir($value);
					
					foreach ($scan as $key => $val) {
						if (($val === ".") || ($val === "..")) {
							unset($scan[$key]);
							continue;
						}
						$scan[$key] = str_replace(DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $value . DIRECTORY_SEPARATOR . $val);
					}
					sort($scan);
					$this->exec($scan);
					continue;
				}
				if (is_file($value)) {
					if(!copy($value, self::$ROOT_DIR . DIRECTORY_SEPARATOR . str_replace(__dir__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR, "", $value))) {
						throw new Exception("Am ERROR accurredd while copying data", 4);
					}
				}
			}
        
		} catch (\Throwable $th) {
			throw new Exception($th, 1);
		}

        return true;
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
        $this->AppPath = $dataSRC;
		return $this;
    }

        array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_CONTROLLERS));
        array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_MIGRATIONS));
        array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_MODELS));
        array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_PUBLIC));
        array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_RUNTIME));
        array_push($dataSRC, $this->getPath(Application::INSTALL_MOD_VIEWS));

        $this->AppPath =  $dataSRC;
		return $this;
    }

    private function getPath(string $namedPath)
    {
        if (file_exists(__dir__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . $namedPath . DIRECTORY_SEPARATOR)) {
           return __dir__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . $namedPath . DIRECTORY_SEPARATOR;
        }

        throw new Exception("ATOM '$namedPath' Not Exist");
    }

}
