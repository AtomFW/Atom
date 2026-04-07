<?php

final class a0006_initial_system_events {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "CREATE TABLE `{{prefix}}system_events` (
            `id` int UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `event_time` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT 'time when log event was added',
            `event_type_id` smallint UNSIGNED NOT NULL COMMENT 'np. server_created, server_deleted, post_created, post_deleted, user_banned, data_purged, backup_started.... | id from allowed_values	',
            `severity_id` smallint UNSIGNED NOT NULL DEFAULT '40' COMMENT 'what type of event is it? | id from allowed_values	',
            `entity_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL COMMENT 'server, post, user, file, ban, comment, etc.',
            `entity_id` int UNSIGNED DEFAULT NULL COMMENT 'Record ID from the target table (if any)',
            `actor_type_id` smallint UNSIGNED NOT NULL DEFAULT '44' COMMENT 'who the event came from, user, admin, cron, api, etc. | id from allowed_values	',
            `actor_id` int UNSIGNED NOT NULL COMMENT 'user_id or server_id if it''s the system | server or users table id',
            `actor_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL COMMENT 'login, cron name, etc.',
            `ip_address_id` int UNSIGNED NOT NULL COMMENT 'INET6_ATON() | id from connections table',
            `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'description of the event, what happened, short news',
            `old_values` json DEFAULT NULL COMMENT 'what was before the change (for UPDATE/DELETE)',
            `new_values` json DEFAULT NULL COMMENT 'what is after the change (for CREATE/UPDATE)',
            `server_id` mediumint UNSIGNED NOT NULL COMMENT 'server id from the sr column | id from servers table',
            `is_add_from_server` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 = add for server, 0 = add from user',
            `metadata` json DEFAULT NULL COMMENT 'other metadata for a given event'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin COMMENT='System event log / audit log – all important actions in the application';";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "DROP TABLE `{{prefix}}system_events`;";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}