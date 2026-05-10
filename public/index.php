<?php
use App\controllers\AboutController;
use App\controllers\SiteController;
use Atom\Atom;

error_reporting(E_ALL);
ini_set('display_errors', 1);

// require_once("../autoload.php");
// Register the Composer autoloader...
require_once __DIR__ . '/../vendor/autoload.php';

$app = new Atom(dirname(__DIR__));

$app->on(Atom::EVENT_BEFORE_REQUEST, function(){
    // echo "Before request from second installation";
});
 
$app->router->get('/newatom/public/', [SiteController::class, 'home']);
$app->router->get('/newatom/public/register', [SiteController::class, 'register']);
$app->router->post('/newatom/public/register', [SiteController::class, 'register']);
$app->router->get('/newatom/public/login', [SiteController::class, 'login']);
$app->router->get('/newatom/public/login/{id}', [SiteController::class, 'login']);
$app->router->post('/newatom/public/login', [SiteController::class, 'login']);
$app->router->get('/newatom/public/logout', [SiteController::class, 'logout']);
$app->router->get('/newatom/public/contact', [SiteController::class, 'contact']);
$app->router->get('/newatom/public/about', [AboutController::class, 'index']);
$app->router->get('/newatom/public/profile', [SiteController::class, 'profile']);
$app->router->get('/newatom/public/profile/{id:\d+}/{username}', [SiteController::class, 'login']);
$app->router->get('/newatom/public/uploads', [SiteController::class, 'uploads']);
$app->router->get('/newatom/public/uploadsMulti', [SiteController::class, 'uploadsMulti']);
$app->router->post('/newatom/public/atpi/upload/', [SiteController::class, 'upload']);
$app->router->post('/newatom/public/atpi/uploadMulti/', [SiteController::class, 'uploadMulti']);
$app->router->post('/newatom/public/atpi/subscribe/', [SiteController::class, 'subscribe']);
$app->router->post('/newatom/public/atpi/push/', [SiteController::class, 'push']);
$app->router->get('/newatom/public/webPush', [SiteController::class, 'webPush']);

// /profile/{id}
// /profile/13
// \/profile\/\w+

// /profile/{id}/zura
// /profile/12/zura
// /{id}
$app->run();
