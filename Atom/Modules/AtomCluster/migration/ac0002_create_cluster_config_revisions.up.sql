CREATE TABLE IF NOT EXISTS {{prefix}}cluster_config_revisions (
    id INTEGER PRIMARY KEY,
    cluster_id VARCHAR(120) NOT NULL,
    revision INT NOT NULL,
    config_namespace VARCHAR(64) NOT NULL,
    supervisor_node_id VARCHAR(120) NOT NULL,
    encrypted_frame TEXT NOT NULL,
    applied_at VARCHAR(32) NOT NULL
);
