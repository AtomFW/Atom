ALTER TABLE `{{prefix}}system_events`
    DROP KEY `idx_event_time`,
    DROP KEY `idx_event_type`,
    DROP KEY `idx_severity`,
    DROP KEY `idx_entity`,
    DROP KEY `idx_actor`,
    DROP KEY `idx_server_event`,
    DROP KEY `idx_event_time_type`;
