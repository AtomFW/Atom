CREATE TABLE IF NOT EXISTS {{prefix}}oauth_tokens (
    id INTEGER PRIMARY KEY,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    token_type VARCHAR(24) NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    client_id VARCHAR(120) NOT NULL,
    scopes TEXT NOT NULL,
    expires_at BIGINT NOT NULL,
    revoked INT NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL
);
