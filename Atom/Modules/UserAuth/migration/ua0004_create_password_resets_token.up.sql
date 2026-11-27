CREATE TABLE IF NOT EXISTS `{{prefix}}password_resets_token` (
    `id` bigint UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_ci NOT NULL,
    `token_hash` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `expires_at` datetime(3) NOT NULL,
    KEY `idx_password_resets_email` (`email`),
    KEY `idx_password_resets_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin COMMENT='Password reset tokens managed by UserAuth module';
