CREATE TABLE IF NOT EXISTS {{prefix}}error_debugger_snapshots (
    id INTEGER PRIMARY KEY,
    exception_class VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    file_path VARCHAR(512) NOT NULL,
    line_number INT NOT NULL,
    column_number INT NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL
);
