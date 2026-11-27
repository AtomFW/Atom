CREATE TABLE IF NOT EXISTS {{prefix}}profiler_traces (
    id INTEGER PRIMARY KEY,
    trace_id VARCHAR(64) NOT NULL UNIQUE,
    http_method VARCHAR(16) NOT NULL,
    request_url VARCHAR(512) NOT NULL,
    status_code INT NOT NULL DEFAULT 200,
    total_duration_ns BIGINT NOT NULL,
    cpu_total_us BIGINT NOT NULL DEFAULT 0,
    memory_peak_bytes BIGINT NOT NULL DEFAULT 0,
    disk_io_bytes BIGINT NOT NULL DEFAULT 0,
    bottleneck_count INT NOT NULL DEFAULT 0,
    timonix_cloud_json TEXT NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
