<?php

final class a0015_constraint_servers {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}servers`
            ADD CONSTRAINT `servers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}servers`
            DROP FOREIGN KEY `servers_ibfk_1`";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}