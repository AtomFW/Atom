ALTER TABLE `{{prefix}}users`
    ADD UNIQUE KEY `ux_user_uuid` (`user_uuid`),
    ADD UNIQUE KEY `ux_username` (`username`),
    ADD UNIQUE KEY `ux_email` (`email`),
    ADD KEY `idx_status` (`status_id`),
    ADD KEY `idx_role` (`role_id`),
    ADD KEY `idx_account_type` (`account_type_id`),
    ADD KEY `idx_last_active` (`last_active_at` DESC),
    ADD KEY `idx_last_login` (`last_login_at` DESC),
    ADD KEY `idx_country` (`country`),
    ADD KEY `idx_is_online` (`is_online`),
    ADD KEY `idx_name` (`last_name`, `first_name`);
