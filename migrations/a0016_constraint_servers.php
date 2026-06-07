<?php

final class a0016_constraint_servers {
    public object $db;

    public function up()
    {
        $db = $this->db->database;
        $SQL = "ALTER TABLE `{{prefix}}servers`
            ADD CONSTRAINT `servers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = $this->db->database;
        $SQL = "ALTER TABLE `{{prefix}}servers`
            DROP FOREIGN KEY `servers_ibfk_1`";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}