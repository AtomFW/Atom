<?php

final class a0014_insert_servers {
    public object $db;

    public function up()
    {
        $db = $this->db->database;
        $SQL = "INSERT INTO `{{prefix}}servers` (`id`, `server_uuid`, `hostname`, `os_name`, `os_version`, `ip_address`, `location_id`, `provider_id`, `is_active`, `environment_id`, `main_service_type_id`, `server_role_id`, `cpu_cores`, `total_ram_mb`, `total_disk_gb`, `cpu_load`, `used_ram_mb`, `used_disk_gb`, `type_id`, `user_id`, `last_online_at`, `updated_at`, `added_at`, `metadata`) VALUES
        (1, UUID_TO_BIN(UUID(), 1), '{{hostname}}', '{{osname}}', '{{osversion}}', INET6_ATON('{{osip}}'), 13, 120, 1, 0, 120, 0, NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, '{{datetimeutcsql}}', '{{datetimeutcsql}}', '{{datetimeutcsql}}', NULL);";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = $this->db->database;
        $SQL = "DELETE FROM `{{prefix}}servers` WHERE `id` = '1'";
        $SQL = $this->db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}