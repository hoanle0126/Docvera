-- Docvera Migration 001: Initial Schema (Update-Safe, SaaS & REST API Ready)

CREATE TABLE IF NOT EXISTS migrations (
    id VARCHAR(128) PRIMARY KEY,
    executed_at VARCHAR(32) NOT NULL
);

CREATE TABLE IF NOT EXISTS processing_jobs (
    id VARCHAR(64) PRIMARY KEY,
    tool_id VARCHAR(64) NOT NULL,
    driver_used VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    session_hash VARCHAR(128) NOT NULL,
    download_token_hash VARCHAR(128) NOT NULL,
    input_count INT NOT NULL DEFAULT 1,
    input_bytes BIGINT NOT NULL DEFAULT 0,
    output_filename VARCHAR(255) NULL,
    output_mime VARCHAR(128) NULL,
    output_bytes BIGINT NOT NULL DEFAULT 0,
    summary_json TEXT NULL,
    error_message TEXT NULL,
    created_at VARCHAR(32) NOT NULL,
    completed_at VARCHAR(32) NULL,
    expires_at VARCHAR(32) NOT NULL,
    deleted_at VARCHAR(32) NULL
);

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(128) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

-- Future Phase 2 Readiness: Quotas & API Keys (Non-breaking foundation)
CREATE TABLE IF NOT EXISTS user_quotas (
    subject_key VARCHAR(128) PRIMARY KEY,
    tier VARCHAR(64) NOT NULL DEFAULT 'guest',
    jobs_used_today INT NOT NULL DEFAULT 0,
    bytes_used_today BIGINT NOT NULL DEFAULT 0,
    period_date VARCHAR(16) NOT NULL
);

CREATE TABLE IF NOT EXISTS api_keys (
    id VARCHAR(64) PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    key_hash VARCHAR(128) NOT NULL,
    is_active INT NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL
);

