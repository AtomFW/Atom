CREATE TABLE IF NOT EXISTS {{prefix}}cluster_nodes (
    id INTEGER PRIMARY KEY,
    cluster_id VARCHAR(120) NOT NULL,
    node_id VARCHAR(120) NOT NULL UNIQUE,
    endpoint_url VARCHAR(512) NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'worker',
    cpu_load REAL NOT NULL DEFAULT 0.0,
    used_ram_mb REAL NOT NULL DEFAULT 0.0,
    total_ram_mb REAL NOT NULL DEFAULT 1024.0,
    active_connections INT NOT NULL DEFAULT 0,
    clock_offset_ms REAL NOT NULL DEFAULT 0.0,
    weight INT NOT NULL DEFAULT 100,
    is_healthy INT NOT NULL DEFAULT 1,
    last_heartbeat_ns BIGINT NOT NULL DEFAULT 0,
    updated_at VARCHAR(32) NOT NULL
);
