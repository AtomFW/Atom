<?php

final class a0017_constraint_connections {
    public object $db;

    public function up()
    {
        $db = $this->db->database;
        $SQL = "ALTER TABLE `{{prefix}}connections`
            ADD CONSTRAINT `connections_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`);";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = $this->db->database;
        $SQL = "ALTER TABLE `{{prefix}}connections`
            DROP FOREIGN KEY `connections_ibfk_1`;";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}