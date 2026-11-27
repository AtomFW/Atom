ALTER TABLE `{{prefix}}allowed_values`
    DROP KEY `ux_table_column_key`,
    DROP KEY `idx_table_column`,
    DROP KEY `idx_table_column_active`,
    DROP KEY `idx_value_key`,
    DROP KEY `idx_added_by`;
