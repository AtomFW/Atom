<?php

final class a0011_keys_connections_banned {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}connections_banned`
            ADD UNIQUE KEY `cb_user_id` (`user_id`),
            ADD UNIQUE KEY `ux_entity` (`entity_type_id`,`entity_hash`),
            ADD KEY `ix_expires_at` (`expires_at`),
            ADD KEY `ix_banned_at` (`banned_at` DESC),
            ADD KEY `ix_is_bot_reason` (`is_bot`,`ban_reason_id`),
            ADD KEY `connections_banned_ibfk_2` (`server_id`);";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}connections_banned`
            DROP KEY `cb_user_id`,
            DROP KEY `ux_entity`,
            DROP KEY `ix_expires_at`,
            DROP KEY `ix_banned_at`,
            DROP KEY `ix_is_bot_reason`,
            DROP KEY `connections_banned_ibfk_2`;";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}