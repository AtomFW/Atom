CREATE TABLE IF NOT EXISTS {{prefix}}oauth_clients (
    id INTEGER PRIMARY KEY,
    client_id VARCHAR(120) NOT NULL UNIQUE,
    client_secret_hash VARCHAR(255) DEFAULT NULL,
    name VARCHAR(160) NOT NULL,
    redirect_uris TEXT NOT NULL,
    allowed_scopes TEXT NOT NULL,
    is_confidential INT NOT NULL DEFAULT 1,
    is_active INT NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL
);
