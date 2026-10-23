-- Docvera Migration 002: Phase 2 SaaS, Entitlements, Billing, REST API & Admin Platform

ALTER TABLE processing_jobs ADD COLUMN user_id VARCHAR(64) NULL;
ALTER TABLE processing_jobs ADD COLUMN api_key_id VARCHAR(64) NULL;
ALTER TABLE processing_jobs ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT 'web';
ALTER TABLE processing_jobs ADD COLUMN execution_mode VARCHAR(16) NOT NULL DEFAULT 'sync';
ALTER TABLE processing_jobs ADD COLUMN page_count INT NOT NULL DEFAULT 0;
ALTER TABLE processing_jobs ADD COLUMN request_params_json TEXT NULL;

CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(64) PRIMARY KEY,
    email VARCHAR(191) NOT NULL UNIQUE,
    name VARCHAR(128) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'user',
    plan_id VARCHAR(64) NOT NULL DEFAULT 'free',
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    api_enabled_override INT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL,
    last_login_at VARCHAR(32) NULL
);

CREATE TABLE IF NOT EXISTS plans (
    id VARCHAR(64) PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    slug VARCHAR(64) NOT NULL,
    description TEXT NOT NULL,
    is_public INT NOT NULL DEFAULT 1,
    is_default INT NOT NULL DEFAULT 0,
    price_monthly_cents INT NOT NULL DEFAULT 0,
    price_yearly_cents INT NOT NULL DEFAULT 0,
    currency VARCHAR(12) NOT NULL DEFAULT 'USD',
    stripe_price_monthly_id VARCHAR(128) NULL,
    stripe_price_yearly_id VARCHAR(128) NULL,
    paypal_plan_monthly_id VARCHAR(128) NULL,
    paypal_plan_yearly_id VARCHAR(128) NULL,
    entitlements_json TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    updated_at VARCHAR(32) NOT NULL
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id VARCHAR(64) PRIMARY KEY,
    user_id VARCHAR(64) NOT NULL,
    plan_id VARCHAR(64) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    provider_subscription_id VARCHAR(128) NULL,
    provider_customer_id VARCHAR(128) NULL,
    billing_interval VARCHAR(16) NOT NULL DEFAULT 'monthly',
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    cancel_at_period_end INT NOT NULL DEFAULT 0,
    current_period_start VARCHAR(32) NOT NULL,
    current_period_end VARCHAR(32) NOT NULL,
    canceled_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE TABLE IF NOT EXISTS payments (
    id VARCHAR(64) PRIMARY KEY,
    user_id VARCHAR(64) NOT NULL,
    subscription_id VARCHAR(64) NULL,
    plan_id VARCHAR(64) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    provider_payment_id VARCHAR(128) NOT NULL,
    amount_cents INT NOT NULL DEFAULT 0,
    currency VARCHAR(12) NOT NULL DEFAULT 'USD',
    status VARCHAR(32) NOT NULL DEFAULT 'succeeded',
    description VARCHAR(255) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE TABLE IF NOT EXISTS webhook_events (
    id VARCHAR(64) PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    event_id VARCHAR(191) NOT NULL,
    event_type VARCHAR(128) NOT NULL,
    payload_hash VARCHAR(128) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'processed',
    processed_at VARCHAR(32) NOT NULL
);

CREATE TABLE IF NOT EXISTS usage_buckets (
    bucket_key VARCHAR(191) PRIMARY KEY,
    subject_key VARCHAR(128) NOT NULL,
    subject_type VARCHAR(32) NOT NULL,
    period_type VARCHAR(16) NOT NULL,
    period_key VARCHAR(32) NOT NULL,
    ops_count INT NOT NULL DEFAULT 0,
    api_requests_count INT NOT NULL DEFAULT 0,
    bytes_processed BIGINT NOT NULL DEFAULT 0,
    ocr_ops_count INT NOT NULL DEFAULT 0,
    active_concurrent_jobs INT NOT NULL DEFAULT 0,
    updated_at VARCHAR(32) NOT NULL
);

ALTER TABLE api_keys ADD COLUMN user_id VARCHAR(64) NULL;
ALTER TABLE api_keys ADD COLUMN key_prefix VARCHAR(32) NULL;
ALTER TABLE api_keys ADD COLUMN scopes_json TEXT NULL;
ALTER TABLE api_keys ADD COLUMN rate_limit_per_min INT NOT NULL DEFAULT 60;
ALTER TABLE api_keys ADD COLUMN last_used_at VARCHAR(32) NULL;
ALTER TABLE api_keys ADD COLUMN last_used_ip VARCHAR(64) NULL;
ALTER TABLE api_keys ADD COLUMN expires_at VARCHAR(32) NULL;
ALTER TABLE api_keys ADD COLUMN revoked_at VARCHAR(32) NULL;

CREATE TABLE IF NOT EXISTS api_audit_logs (
    id VARCHAR(64) PRIMARY KEY,
    request_id VARCHAR(64) NOT NULL,
    api_key_id VARCHAR(64) NULL,
    user_id VARCHAR(64) NULL,
    endpoint VARCHAR(191) NOT NULL,
    method VARCHAR(16) NOT NULL,
    tool_id VARCHAR(64) NULL,
    status_code INT NOT NULL,
    ip_address VARCHAR(64) NULL,
    duration_ms INT NOT NULL DEFAULT 0,
    idempotency_key VARCHAR(191) NULL,
    response_json TEXT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE TABLE IF NOT EXISTS tool_overrides (
    tool_id VARCHAR(64) PRIMARY KEY,
    is_enabled INT NOT NULL DEFAULT 1,
    guest_allowed INT NOT NULL DEFAULT 1,
    allowed_plans_json TEXT NULL,
    max_file_size_mb INT NULL,
    max_files INT NULL,
    api_enabled INT NOT NULL DEFAULT 1,
    default_options_json TEXT NULL,
    seo_title VARCHAR(255) NULL,
    seo_description TEXT NULL,
    seo_canonical VARCHAR(255) NULL,
    seo_indexable INT NOT NULL DEFAULT 1,
    seo_in_sitemap INT NOT NULL DEFAULT 1,
    updated_at VARCHAR(32) NOT NULL
);

CREATE TABLE IF NOT EXISTS cms_pages (
    slug VARCHAR(64) PRIMARY KEY,
    title VARCHAR(191) NOT NULL,
    meta_description TEXT NULL,
    content TEXT NOT NULL,
    is_published INT NOT NULL DEFAULT 1,
    in_sitemap INT NOT NULL DEFAULT 1,
    updated_at VARCHAR(32) NOT NULL
);

