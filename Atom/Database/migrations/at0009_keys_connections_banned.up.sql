ALTER TABLE `{{prefix}}connections_banned`
    ADD UNIQUE KEY `cb_user_id` (`user_id`),
    ADD UNIQUE KEY `ux_entity` (`entity_type_id`, `entity_hash`),
    ADD KEY `ix_expires_at` (`expires_at`),
    ADD KEY `ix_banned_at` (`banned_at` DESC),
    ADD KEY `ix_is_bot_reason` (`is_bot`, `ban_reason_id`),
    ADD KEY `connections_banned_ibfk_2` (`server_id`);
