CREATE TABLE IF NOT EXISTS {{prefix}}atom_exchange_peers (
    id INTEGER PRIMARY KEY,
    node_id VARCHAR(120) NOT NULL UNIQUE,
    endpoint_url VARCHAR(512) NOT NULL,
    public_key_hex VARCHAR(128) NOT NULL,
    key_id VARCHAR(64) NOT NULL DEFAULT 'atmx-k1',
    is_trusted INT NOT NULL DEFAULT 1,
    last_seen_at VARCHAR(32) DEFAULT NULL,
    created_at VARCHAR(32) NOT NULL
);
