<?php

final class a0019_constraint_system_events {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}system_events`
            ADD CONSTRAINT `system_events_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`);";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}system_events`
            DROP FOREIGN KEY `system_events_ibfk_1`;";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}