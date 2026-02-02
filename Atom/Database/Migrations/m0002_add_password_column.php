<?php

class m0002_add_password_column {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $db->pdo->exec("ALTER TABLE users ADD COLUMN password VARCHAR(512) NOT NULL");
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $db->pdo->exec("ALTER TABLE users REMOVE COLUMN password VARCHAR(512) NOT NULL");
    }
}