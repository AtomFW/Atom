<?php

final class a0019_constraint_system_events {
    public object $db;

    public function up()
    {
        $db = $this->db->database;
        $SQL = "ALTER TABLE `{{prefix}}system_events`
            ADD CONSTRAINT `system_events_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`);";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = $this->db->database;
        $SQL = "ALTER TABLE `{{prefix}}system_events`
            DROP FOREIGN KEY `system_events_ibfk_1`;";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}