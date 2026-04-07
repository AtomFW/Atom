<?php

final class a0018_constraint_connections_banned {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}connections_banned`
            ADD CONSTRAINT `connections_banned_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            ADD CONSTRAINT `connections_banned_ibfk_2` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`);";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}connections_banned`
            DROP FOREIGN KEY `connections_banned_ibfk_1`,
            DROP FOREIGN KEY `connections_banned_ibfk_2`,
            DROP FOREIGN KEY `ibfk_1`;";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}