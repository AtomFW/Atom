ALTER TABLE `{{prefix}}system_events`
    ADD CONSTRAINT `system_events_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `{{prefix}}servers` (`id`);
