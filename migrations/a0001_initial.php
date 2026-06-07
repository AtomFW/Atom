<?php

final class a0001_initial {
    public object $db;

    public function up()
    {
        $db = $this->db->database;
        $SQL = "CREATE TABLE `{{prefix}}users` (
            `id` bigint UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_uuid` binary(16) NOT NULL DEFAULT (UUID_TO_BIN(UUID())) COMMENT 'UUID_TO_BIN(UUID())',
            `is_online` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'online = 1 also offline = 0',
            `username` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NOT NULL COMMENT 'full username',
            `username_view` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL COMMENT 'null = default, changed display nickname',
            `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_ci NOT NULL COMMENT 'e-mail must always be written in lowercase letters',
            `email_two` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_ci DEFAULT NULL COMMENT '2 security email',
            `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL COMMENT 'full first name',
            `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL COMMENT 'full last name',
            `country` char(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'PL, CA, US...',
            `language` char(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'pl, ca, en....',
            `gender` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 = girl, 1 = boy',
            `account_type_id` smallint UNSIGNED NOT NULL DEFAULT '45' COMMENT 'id from allowed_values table',
            `role_id` smallint UNSIGNED NOT NULL DEFAULT '111' COMMENT 'role_id id from allowed_values table',
            `status_id` smallint UNSIGNED NOT NULL DEFAULT '70' COMMENT 'All statuses are kept, such as pending verification (which is waiting for email confirmation), whether the user is banned, etc.\r\nid from allowed_values table',
            `phone` varchar(25) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'with international prefix e.g. +48123456789',
            `avatar_uri` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL COMMENT 'full uri path to avatar',
            `phone_verified` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'verified = 1, not verified = 0',
            `two_factor_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'enabled = 1, disabled = 0',
            `registration_ip_id` int UNSIGNED NOT NULL COMMENT 'kept in connections_banned and we provide the record id',
            `last_login_ip_id` int UNSIGNED NOT NULL COMMENT 'id from connection_banned table',
            `password_hash` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'hash is set in php according to the setting in config',
            `date_of_birth` datetime(3) DEFAULT NULL COMMENT 'date of birth to check whether the user is of legal age',
            `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT 'time when the account was created',
            `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT 'time when the data was updated',
            `last_active_at` datetime(3) NOT NULL COMMENT 'time when you were last online',
            `last_login_at` datetime(3) NOT NULL COMMENT 'last time you logged in',
            `active_sessions` json DEFAULT NULL COMMENT 'array of session objects: [{\"id\": \"...\", \"ip\": \"...\", \"user_agent\": \"...\"}]',
            `metadata` json DEFAULT NULL COMMENT 'timezone, preferences, company_name, vat_number, social_links, notes...'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin COMMENT='share all user data (account)';";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = $this->db->database;
        $SQL = "DROP TABLE `{{prefix}}users`;";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}