ALTER TABLE `{{prefix}}system_events`
    ADD KEY `idx_event_time` (`event_time` DESC),
    ADD KEY `idx_event_type` (`event_type_id`, `event_time` DESC),
    ADD KEY `idx_severity` (`severity_id`, `event_time` DESC),
    ADD KEY `idx_entity` (`entity_type`, `entity_id`, `event_time` DESC),
    ADD KEY `idx_actor` (`actor_type_id`, `actor_id`, `event_time` DESC),
    ADD KEY `idx_server_event` (`server_id`, `event_time` DESC),
    ADD KEY `idx_event_time_type` (`event_time`, `event_type_id`);
