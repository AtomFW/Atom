<?php
use Atom\Atom;

require_once("autoload.php");

$config = [
    'userClass' => \Atom\models\User::class,
    'db' => [
        'dsn' => "mysql:host=localhost;port=3306;dbname=newatom",
        'user' => "root",
        'password' => "",
    ]
];

$app = new Atom(rootDir: __DIR__, config: $config);

$app->db->applyMigrations();