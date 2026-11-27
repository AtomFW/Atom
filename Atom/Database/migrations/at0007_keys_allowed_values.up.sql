ALTER TABLE `{{prefix}}allowed_values`
    ADD UNIQUE KEY `ux_table_column_key` (`table_name`, `column_name`, `value_key`),
    ADD KEY `idx_table_column` (`table_name`, `column_name`, `sort_order`),
    ADD KEY `idx_table_column_active` (`table_name`, `column_name`, `is_active`),
    ADD KEY `idx_value_key` (`value_key`),
    ADD KEY `idx_added_by` (`added_by`);
