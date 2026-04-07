<?php

final class a0009_keys_allowed_values {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}allowed_values`
            ADD UNIQUE KEY `ux_table_column_key` (`table_name`,`column_name`,`value_key`),
            ADD KEY `idx_table_column` (`table_name`,`column_name`,`sort_order`),
            ADD KEY `idx_table_column_active` (`table_name`,`column_name`,`is_active`),
            ADD KEY `idx_value_key` (`value_key`),
            ADD KEY `idx_added_by` (`added_by`);";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "ALTER TABLE `{{prefix}}allowed_values`
            DROP KEY `ux_table_column_key`,
            DROP KEY `idx_table_column`,
            DROP KEY `idx_table_column_active`,
            DROP KEY `idx_value_key`,
            DROP KEY `idx_added_by`;";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}