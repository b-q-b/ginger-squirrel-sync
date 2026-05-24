-- Ginger Sync — initial schema
-- Run this once against your Supabase Postgres (SQL editor).
-- Creates schema `ginger_sync` and 5 tables + indexes.
-- Safe to re-run (IF NOT EXISTS everywhere).

CREATE SCHEMA IF NOT EXISTS ginger_sync;

-- Expose the schema to PostgREST (Supabase REST API)
-- Note: Supabase projects already include ginger_sync once added to
-- Project Settings → API → "Exposed schemas". Do that step in the dashboard
-- after running this migration.

GRANT USAGE ON SCHEMA ginger_sync TO anon, authenticated, service_role;
ALTER DEFAULT PRIVILEGES IN SCHEMA ginger_sync
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO service_role;
ALTER DEFAULT PRIVILEGES IN SCHEMA ginger_sync
    GRANT USAGE, SELECT ON SEQUENCES TO service_role;

-- ────────────────────────────────────────────────────────────────
-- 1. settings — global config, one row per key
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.settings (
    key TEXT PRIMARY KEY,
    value JSONB NOT NULL,
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

GRANT SELECT, INSERT, UPDATE, DELETE ON ginger_sync.settings TO service_role;

-- ────────────────────────────────────────────────────────────────
-- 2. mappings — board/list pair configurations
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.mappings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    label TEXT NOT NULL,
    trello_board_id TEXT NOT NULL,
    trello_list_id TEXT,
    clickup_space_id TEXT NOT NULL,
    clickup_list_id TEXT NOT NULL,
    status_map JSONB NOT NULL DEFAULT '{}'::jsonb,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

GRANT SELECT, INSERT, UPDATE, DELETE ON ginger_sync.mappings TO service_role;

-- ────────────────────────────────────────────────────────────────
-- 3. sync_map — the core ID ledger
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.sync_map (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    mapping_id UUID NOT NULL REFERENCES ginger_sync.mappings(id) ON DELETE CASCADE,
    trello_card_id TEXT UNIQUE,
    clickup_task_id TEXT UNIQUE,
    last_hash TEXT,
    last_direction TEXT CHECK (last_direction IN ('trello_to_clickup', 'clickup_to_trello')),
    last_synced_at TIMESTAMPTZ,
    deleted_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS sync_map_trello_idx ON ginger_sync.sync_map(trello_card_id);
CREATE INDEX IF NOT EXISTS sync_map_clickup_idx ON ginger_sync.sync_map(clickup_task_id);
CREATE INDEX IF NOT EXISTS sync_map_mapping_idx ON ginger_sync.sync_map(mapping_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON ginger_sync.sync_map TO service_role;

-- ────────────────────────────────────────────────────────────────
-- 4. sync_events — full audit log (dashboard + logs page)
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.sync_events (
    id BIGSERIAL PRIMARY KEY,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    source TEXT NOT NULL,
    direction TEXT,
    action TEXT NOT NULL,
    trello_card_id TEXT,
    clickup_task_id TEXT,
    mapping_id UUID,
    status TEXT NOT NULL,
    error TEXT,
    payload_hash TEXT
);

CREATE INDEX IF NOT EXISTS sync_events_created_idx ON ginger_sync.sync_events(created_at DESC);
CREATE INDEX IF NOT EXISTS sync_events_status_idx ON ginger_sync.sync_events(status);

GRANT SELECT, INSERT ON ginger_sync.sync_events TO service_role;
GRANT USAGE, SELECT ON SEQUENCE ginger_sync.sync_events_id_seq TO service_role;

-- ────────────────────────────────────────────────────────────────
-- 5. webhook_registrations — know what's active on each platform
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.webhook_registrations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    platform TEXT NOT NULL CHECK (platform IN ('clickup', 'trello')),
    external_id TEXT NOT NULL,
    target_id TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('active', 'disabled', 'failed')),
    last_checked_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

GRANT SELECT, INSERT, UPDATE, DELETE ON ginger_sync.webhook_registrations TO service_role;

-- ────────────────────────────────────────────────────────────────
-- Seed: initial dashboard password = "OpenShakra!"
-- The hash below was generated with:
--   php -r "echo password_hash('OpenShakra!', PASSWORD_BCRYPT);"
-- Change the password through /settings.php once you're logged in.
-- ────────────────────────────────────────────────────────────────
INSERT INTO ginger_sync.settings (key, value) VALUES
    ('dashboard_password_hash', to_jsonb('$2y$12$WtiC2bfodTtUg6B9K4lTqeDdUr77FEEbZ7/W6dJPzg.Iq6pEKmqrS'::text)),
    ('field_toggles', '{"title":true,"description":true,"status":true,"due_date":true,"labels":true}'::jsonb),
    ('reconcile_interval_minutes', to_jsonb(10))
ON CONFLICT (key) DO NOTHING;
