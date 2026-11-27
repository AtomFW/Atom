CREATE TABLE IF NOT EXISTS {{prefix}}oauth_identities (
    id INTEGER PRIMARY KEY,
    user_id BIGINT NOT NULL,
    provider VARCHAR(64) NOT NULL,
    provider_user_id VARCHAR(191) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    avatar_url VARCHAR(512) DEFAULT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL,
    UNIQUE(provider, provider_user_id)
);
