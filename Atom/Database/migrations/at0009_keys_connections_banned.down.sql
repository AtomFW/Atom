ALTER TABLE `{{prefix}}connections_banned`
    DROP KEY `cb_user_id`,
    DROP KEY `ux_entity`,
    DROP KEY `ix_expires_at`,
    DROP KEY `ix_banned_at`,
    DROP KEY `ix_is_bot_reason`,
    DROP KEY `connections_banned_ibfk_2`;
