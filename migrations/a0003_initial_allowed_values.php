<?php

final class a0003_initial_allowed_values {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "CREATE TABLE `{{prefix}}allowed_values` (
            `id` int UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `table_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'np. users, temp_blocks, system_events, visit_logs',
            `column_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'np. role, status, block_type, event_type',
            `value_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL COMMENT 'SHORT SUMMARY – what will be saved in the database and used in PHP (user, admin, headadmin, over)',
            `value_label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL COMMENT 'FULL NAME – to be displayed in the admin panel/dropdowns (Regular User, Administrator, Main Administrator, Overlord)',
            `sort_order` smallint UNSIGNED NOT NULL DEFAULT '100' COMMENT 'settings in what order the value should be set',
            `is_active` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 = active, 0 = deactive value',
            `is_system` tinyint UNSIGNED NOT NULL DEFAULT '0' COMMENT 'TRUE = system value, cannot be edited/deleted by user',
            `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'what is the property for?',
            `added_by` bigint UNSIGNED DEFAULT NULL COMMENT 'users.id | id from users table',
            `added_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT 'time when the data was added',
            `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT 'time when the data was updated'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin COMMENT='Dynamic allowed values/enums for all tables in the system';";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "DROP TABLE `{{prefix}}allowed_values`;";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}