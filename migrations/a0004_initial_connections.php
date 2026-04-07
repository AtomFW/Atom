<?php

final class a0004_initial_connections {
    public function up()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "CREATE TABLE `{{prefix}}connections` (
            `id` bigint UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'unique id',
            `ip` varbinary(16) NOT NULL COMMENT 'connection ip',
            `isp` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'internet provider',
            `lang` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL COMMENT 'connection language',
            `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'city name',
            `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'cauntry connection',
            `user_agent` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'user agent http',
            `browser_sec` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL COMMENT 'date browser (HTTP_SEC_CH_UA)',
            `coordinates` point NOT NULL COMMENT 'connection coordinates',
            `area_code` tinyint DEFAULT NULL COMMENT 'area code connection',
            `dma_code` tinyint DEFAULT NULL COMMENT 'region code',
            `region` tinyint NOT NULL COMMENT 'id region',
            `unique_id` varchar(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'unique id connection',
            `server_id` mediumint UNSIGNED NOT NULL COMMENT 'atom server id from servers table',
            `datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'datetime added',
            `raw_details` json DEFAULT NULL COMMENT 'other connection data'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='all requests made to servers';";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }

    public function down()
    {
        $db = \Atom\Atom::$app->db;
        $SQL = "DROP TABLE `{{prefix}}connections`;";
        $SQL = $db->adaptMigration($SQL);
        $db->pdo->exec($SQL);
    }
}