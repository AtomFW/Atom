ALTER TABLE `{{prefix}}servers`
    ADD UNIQUE KEY `ux_server_uuid` (`server_uuid`),
    ADD UNIQUE KEY `at_user_id` (`user_id`),
    ADD KEY `ix_ip_address` (`ip_address`),
    ADD KEY `ix_provider_active` (`provider_id`, `is_active`),
    ADD KEY `ix_role_environment` (`server_role_id`, `environment_id`),
    ADD KEY `ix_last_online` (`last_online_at`),
    ADD KEY `ix_updated_at` (`updated_at` DESC);
