CREATE TABLE IF NOT EXISTS {{prefix}}mcp_audit_logs (
    id INTEGER PRIMARY KEY,
    method VARCHAR(120) NOT NULL,
    tool_or_resource VARCHAR(160) DEFAULT NULL,
    client_ip VARCHAR(45) NOT NULL DEFAULT '127.0.0.1',
    is_allowed INT NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL
);
