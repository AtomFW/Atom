<?php

final class a0002_initial_servers {
    public object $db;

    public function up()
    {
        $db = $this->db->database;
        $SQL = "CREATE TABLE `{{prefix}}servers` (
            `id` mediumint UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `server_uuid` binary(16) NOT NULL COMMENT 'UUID_TO_BIN(uuid())',
            `hostname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'server hostname',
            `os_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL COMMENT 'os name server',
            `os_version` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL COMMENT 'os version',
            `ip_address` varbinary(16) NOT NULL COMMENT 'INET6_ATON() – IPv4 i IPv6',
            `location_id` smallint UNSIGNED NOT NULL COMMENT 'where the server is located | id from allowed_values',
            `provider_id` smallint UNSIGNED NOT NULL DEFAULT '123' COMMENT 'self_hosted, ovh, google, aws, azure, jbm, oracle... | id from allowed_values',
            `is_active` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 = active, 0 = inactive',
            `environment_id` smallint UNSIGNED NOT NULL DEFAULT '16' COMMENT '''production'',''staging'',''development'',''testing'', 0 = debug | id from allowed_values',
            `main_service_type_id` smallint UNSIGNED NOT NULL DEFAULT '114' COMMENT 'php-fpm, nginx, mysql, redis, backup, etc. | id from allowed_values',
            `server_role_id` smallint UNSIGNED NOT NULL DEFAULT '19' COMMENT '''primary'',''secondary'',''backup'',''worker'',''storage'',''monitoring'',''cdn_edge'',''backup_only'',''database_only'', 0 = primary | id from allowed_values',
            `cpu_cores` smallint UNSIGNED DEFAULT NULL COMMENT 'how many processor cores does the server have',
            `total_ram_mb` bigint UNSIGNED DEFAULT NULL COMMENT 'how much RAM capacity the server has in MB',
            `total_disk_gb` bigint UNSIGNED DEFAULT NULL COMMENT 'how many disks does the server have in maximum capacity in GB',
            `cpu_load` decimal(5,2) DEFAULT NULL COMMENT 'load average 1-min lub % użycia',
            `used_ram_mb` bigint UNSIGNED DEFAULT NULL COMMENT 'how much RAM capacity the server currently has in MB',
            `used_disk_gb` bigint UNSIGNED DEFAULT NULL COMMENT 'how much disk space is currently used on the server in GB',
            `type_id` smallint UNSIGNED NOT NULL DEFAULT '94' COMMENT 'dedicated, vps, container, other, 0 = dedicated | id from allowed_values',
            `user_id` bigint UNSIGNED NOT NULL COMMENT 'who added, 0 = atom',
            `last_online_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT 'UTC time when the server was last active',
            `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT 'time when the data was updated',
            `added_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT 'UTC time when the entry was added',
			`description` varchar(4096) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL COMMENT 'description of what the server is for, etc.',
            `metadata` json DEFAULT NULL COMMENT 'other servers data'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin COMMENT='Registry of all servers/instances';";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = $this->db->database;
        $SQL = "DROP TABLE `{{prefix}}servers`;";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}