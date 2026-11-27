ALTER TABLE `{{prefix}}connections_banned`
    ADD CONSTRAINT `connections_banned_ibfk_2` FOREIGN KEY (`server_id`) REFERENCES `{{prefix}}servers` (`id`);
