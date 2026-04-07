<?php

final class a0010_keys_connections {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}connections`
            ADD KEY `idx_server_datetime` (`server_id`,`datetime` DESC),
            ADD KEY `idx_datetime` (`datetime` DESC),
            ADD KEY `idx_country` (`country`),
            ADD KEY `idx_country_city` (`country`,`city`),
            ADD KEY `idx_country_datetime` (`country`,`datetime` DESC),
            ADD KEY `idx_ip` (`ip`),
            ADD KEY `idx_unique_id` (`unique_id`),
            ADD KEY `idx_browser_sec` (`browser_sec`),
            ADD KEY `idx_user_agent` (`user_agent`(100)),
            ADD KEY `idx_isp` (`isp`(50)),
            ADD KEY `idx_lang` (`lang`),
            ADD KEY `idx_region` (`region`),
            ADD SPATIAL KEY `idx_coordinates` (`coordinates`);";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}connections`
            DROP KEY `idx_server_datetime`,
            DROP KEY `idx_datetime`,
            DROP KEY `idx_country`,
            DROP KEY `idx_country_city`,
            DROP KEY `idx_country_datetime`,
            DROP KEY `idx_ip`,
            DROP KEY `idx_unique_id`,
            DROP KEY `idx_browser_sec`,
            DROP KEY `idx_user_agent`,
            DROP KEY `idx_isp`,
            DROP KEY `idx_lang`,
            DROP KEY `idx_coordinates`,
            DROP KEY `idx_region`;";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}