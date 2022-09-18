<?php
use Atom\controllers\AboutController;
use Atom\controllers\SiteController;
use Atom\core\Atom;


require_once("../autoload.php");

$config = [
    'userClass' => \Atom\models\User::class,
    'db' => [
        'dsn' => "mysql:host=localhost;port=3306;dbname=php_mvc",
        'user' => "root",
        'password' => "",
    ]
];

$app = new Atom(dirname(__DIR__), $config);

$app->on(Atom::EVENT_BEFORE_REQUEST, function(){
    // echo "Before request from second installation";
});

$app->router->get('/Atom/public/', [SiteController::class, 'home']);
$app->router->get('/Atom/public/register', [SiteController::class, 'register']);
$app->router->post('/Atom/public/register', [SiteController::class, 'register']);
$app->router->get('/Atom/public/login', [SiteController::class, 'login']);
$app->router->get('/Atom/public/login/{id}', [SiteController::class, 'login']);
$app->router->post('/Atom/public/login', [SiteController::class, 'login']);
$app->router->get('/Atom/public/logout', [SiteController::class, 'logout']);
$app->router->get('/Atom/public/contact', [SiteController::class, 'contact']);
$app->router->get('/Atom/public/about', [AboutController::class, 'index']);
$app->router->get('/Atom/public/profile', [SiteController::class, 'profile']);
$app->router->get('/Atom/public/profile/{id:\d+}/{username}', [SiteController::class, 'login']);
// /profile/{id}
// /profile/13
// \/profile\/\w+

// /profile/{id}/zura
// /profile/12/zura

// /{id}
$app->run();
