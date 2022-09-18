<?php
use Atom\core\Atom;

require_once("autoload.php");

$config = [
    'userClass' => \Atom\models\User::class,
    'db' => [
        'dsn' => "mysql:host=localhost;port=3306;dbname=php_mvc",
        'user' => "root",
        'password' => "",
    ]
];

$app = new Atom(__DIR__, $config);

$app->db->applyMigrations();