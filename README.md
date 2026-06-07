# Atom

Open Source

simple syntax just right for you

Quick use, prototyping, and modularity make it ideal for implementation in the MVC OOP framework.

Atom is mini mvc framework to simple work.

## Installation

Install Atom with gh repo

```gh repo
  gh repo clone AtomFW/Atom
```

then configure the database connection in the .env file

after that create any .php file in the main atom folder and use

```php
<?php
$atom = new Atom(__DIR__);

$migration = new Migrations($atom->database, $atom->datetime)
$migration->applyMigrations();
```
to install the Atom table in your database. All created tables are located in the migrations folder, where all new db migrations originate.

## Demo

A Download this reposytory to your computer and runing in PHP server min 8.5!

## Usage/Examples

Params
all the startup code is in public/index.php as well as .htaccess to redirect

All config is in .env for the quicky and faste usage

```php
<?php
# index.php from public/index.php
use Atom\Atom;
use App\Controllers\AboutController;
use App\Controllers\SiteController;

// Register the Composer autoloader....
require_once __DIR__ . '/../vendor/autoload.php';

$app = new Atom(dirname(__DIR__));

$app->on(Atom::EVENT_BEFORE_REQUEST, function(){
    // echo "Before request from second installation";
});
 
$app->router->get('/', [SiteController::class, 'home']);
$app->router->get('/register', [SiteController::class, 'register']);
$app->router->post('/register', [SiteController::class, 'register']);
$app->router->get('/login', [SiteController::class, 'login']);
$app->router->get('/login/{id}', [SiteController::class, 'login']);
$app->router->post('/login', [SiteController::class, 'login']);
$app->router->get('/logout', [SiteController::class, 'logout']);
$app->router->get('/contact', [SiteController::class, 'contact']);
$app->router->get('/about', [AboutController::class, 'index']);
$app->router->get('/profile', [SiteController::class, 'profile']);
$app->router->get('/profile/{id:\d+}/{username}', [SiteController::class, 'login']);
$app->router->get('/uploads', [SiteController::class, 'uploads']);
$app->router->get('/uploadsMulti', [SiteController::class, 'uploadsMulti']);
$app->router->post('/atpi/upload/', [SiteController::class, 'upload']);
$app->router->post('/atpi/uploadMulti/', [SiteController::class, 'uploadMulti']);
$app->router->post('/atpi/subscribe/', [SiteController::class, 'subscribe']);
$app->router->post('/atpi/push/', [SiteController::class, 'push']);
$app->router->get('/webPush', [SiteController::class, 'webPush']);

$app->on(Atom::EVENT_AFTER_REQUEST, function(){
    // echo "After request from second installation";
});

$app->run();

```


## Documentation

In Progress


## License

Is in the License.md file (Community License (FCL) 2.0)


## Authors

- [@Timonix](https://www.github.com/di-Timonix)