<?php

final class a0005_initial_connections_banned {
    public object $db;

    public function up()
    {
        $db = $this->db->database;
        $SQL = "CREATE TABLE `{{prefix}}connections_banned` (
            `id` int UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `entity_type_id` smallint UNSIGNED NOT NULL COMMENT 'ID type/ banned type, target (ip, fingerprint, atc) | id from allowed_values',
            `entity_value` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL COMMENT 'IP, fingerprint hash, regex pattern, CIDR etc.',
            `is_permanent` tinyint(1) GENERATED ALWAYS AS ((`expires_at` is null)) STORED COMMENT 'indicates whether a given connection is permanently blocked',
            `is_bot` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'indicates whether the connection points to a bot',
            `risk_level` tinyint UNSIGNED NOT NULL DEFAULT '80' COMMENT '0–100 how sure we are that it''s a bot/malicious',
            `ban_reason_id` smallint UNSIGNED NOT NULL DEFAULT '107' COMMENT 'what type of detection is automatic, manual.... | id from allowed_values',
            `source` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL COMMENT 'atom detection, internal heuristics, manual, honeypot...',
            `full_speech` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'decides whether the string should be matched or only appear',
            `user_id` bigint UNSIGNED DEFAULT NULL COMMENT 'user id from user table',
            `created_by_id` bigint NOT NULL DEFAULT '0' COMMENT 'admin username or system | id from users table',
            `entity_hash` binary(32) GENERATED ALWAYS AS (unhex(sha2(`entity_value`,256))) STORED COMMENT 'whether a given signature is permanently banned',
            `server_id` mediumint UNSIGNED NOT NULL COMMENT 'atom server id from servers table',
            `banned_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT 'banned since',
            `expires_at` datetime(3) DEFAULT NULL COMMENT 'NULL = permanent ban',
            `metadata` json DEFAULT NULL COMMENT 'e.g. number of requests, country, last 5 tracks, CF score etc.'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin COMMENT='banned IP, fingerprint, behavior patterns, temporary block';";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = $this->db->database;
        $SQL = "DROP TABLE `{{prefix}}connections_banned`;";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}