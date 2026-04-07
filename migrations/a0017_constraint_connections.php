<?php

final class a0017_constraint_connections {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}connections`
            ADD CONSTRAINT `connections_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`);";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}connections`
            DROP FOREIGN KEY `connections_ibfk_1`;";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}