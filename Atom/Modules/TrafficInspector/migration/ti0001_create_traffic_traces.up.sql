CREATE TABLE IF NOT EXISTS {{prefix}}traffic_traces (
    id INTEGER PRIMARY KEY,
    trace_id VARCHAR(64) NOT NULL UNIQUE,
    http_method VARCHAR(16) NOT NULL,
    request_url VARCHAR(512) NOT NULL,
    client_ip VARCHAR(45) NOT NULL DEFAULT '127.0.0.1',
    intent_code VARCHAR(64) NOT NULL,
    status_code INT NOT NULL DEFAULT 200,
    duration_ms REAL NOT NULL DEFAULT 0.0,
    trace_json TEXT NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
