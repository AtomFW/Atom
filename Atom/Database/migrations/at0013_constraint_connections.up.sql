ALTER TABLE `{{prefix}}connections`
    ADD CONSTRAINT `connections_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `{{prefix}}servers` (`id`);
