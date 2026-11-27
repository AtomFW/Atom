ALTER TABLE `{{prefix}}servers`
    DROP KEY `ux_server_uuid`,
    DROP KEY `at_user_id`,
    DROP KEY `ix_ip_address`,
    DROP KEY `ix_provider_active`,
    DROP KEY `ix_role_environment`,
    DROP KEY `ix_last_online`,
    DROP KEY `ix_updated_at`;
