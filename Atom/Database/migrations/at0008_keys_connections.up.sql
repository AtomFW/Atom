ALTER TABLE `{{prefix}}connections`
    ADD KEY `idx_server_datetime` (`server_id`, `datetime` DESC),
    ADD KEY `idx_datetime` (`datetime` DESC),
    ADD KEY `idx_country` (`country`),
    ADD KEY `idx_country_city` (`country`, `city`),
    ADD KEY `idx_country_datetime` (`country`, `datetime` DESC),
    ADD KEY `idx_ip` (`ip`),
    ADD KEY `idx_unique_id` (`unique_id`),
    ADD KEY `idx_browser_sec` (`browser_sec`),
    ADD KEY `idx_user_agent` (`user_agent`(100)),
    ADD KEY `idx_isp` (`isp`(50)),
    ADD KEY `idx_lang` (`lang`),
    ADD KEY `idx_region` (`region`),
    ADD SPATIAL KEY `idx_coordinates` (`coordinates`);
