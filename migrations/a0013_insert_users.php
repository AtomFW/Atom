<?php

final class a0013_insert_users {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "INSERT INTO `{{prefix}}users` (`id`, `user_uuid`, `is_online`, `username`, `username_view`, `email`, `email_two`, `first_name`, `last_name`, `country`, `language`, `gender`, `account_type_id`, `role_id`, `status_id`, `phone`, `avatar_uri`, `phone_verified`, `two_factor_enabled`, `registration_ip_id`, `last_login_ip_id`, `password_hash`, `date_of_birth`, `created_at`, `updated_at`, `last_active_at`, `last_login_at`, `active_sessions`, `metadata`) VALUES
        (1, UUID_TO_BIN(UUID(), 1), 1, 'Atom', NULL, 'atom@localhost', NULL, NULL, NULL, 'PL', 'pl', 1, 44, 44, 44, NULL, '{{domain}}/resources/image/atom.webp', 0, 0, 0, 0, '', NULL, '{{datetimeutcsql}}', '{{datetimeutcsql}}', '{{datetimeutcsql}}', '{{datetimeutcsql}}', NULL, NULL);";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "DELETE FROM `{{prefix}}users` WHERE `id` = '1'";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}