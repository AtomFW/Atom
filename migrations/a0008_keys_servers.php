<?php

final class a0008_keys_servers {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}servers`
            ADD UNIQUE KEY `ux_server_uuid` (`server_uuid`),
            ADD UNIQUE KEY `at_user_id` (`user_id`),
            ADD KEY `ix_ip_address` (`ip_address`),
            ADD KEY `ix_provider_active` (`provider_id`,`is_active`),
            ADD KEY `ix_role_environment` (`server_role_id`,`environment_id`),
            ADD KEY `ix_last_online` (`last_online_at`),
            ADD KEY `ix_updated_at` (`updated_at` DESC);";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}servers`
            DROP KEY `ux_server_uuid`,
            DROP KEY `at_user_id`,
            DROP KEY `ix_ip_address`,
            DROP KEY `ix_provider_active`,
            DROP KEY `ix_role_environment`,
            DROP KEY `ix_last_online`,
            DROP KEY `ix_updated_at`;";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}