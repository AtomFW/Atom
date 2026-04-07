<?php

final class a0012_keys_system_events {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}system_events`
            ADD KEY `idx_event_time` (`event_time` DESC),
            ADD KEY `idx_event_type` (`event_type_id`,`event_time` DESC),
            ADD KEY `idx_severity` (`severity_id`,`event_time` DESC),
            ADD KEY `idx_entity` (`entity_type`,`entity_id`,`event_time` DESC),
            ADD KEY `idx_actor` (`actor_type_id`,`actor_id`,`event_time` DESC),
            ADD KEY `idx_server_event` (`server_id`,`event_time` DESC),
            ADD KEY `idx_event_time_type` (`event_time`,`event_type_id`);";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}system_events`
            DROP KEY `idx_event_time`,
            DROP KEY `idx_event_type`,
            DROP KEY `idx_severity`,
            DROP KEY `idx_entity`,
            DROP KEY `idx_actor`,
            DROP KEY `idx_server_event`,
            DROP KEY `idx_event_time_type`;";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}