-- ─────────────────────────────────────────────────────────────────────
-- Idempotent v2 schema bootstrap.
--
-- Run this once against the v2 Supabase Postgres after the v1 → v2 dump
-- restore. It creates any tables that v1 never had (e.g. v1 may not have
-- run migration_phase4 for `meetings`, or migration_phase3 for hot_plate
-- if those phases shipped late). Every statement uses IF NOT EXISTS, so
-- it's safe to re-run after future merges.
--
--   psql "$POSTGRES_URL" -f api/db/bootstrap_v2.sql
-- ─────────────────────────────────────────────────────────────────────

CREATE SCHEMA IF NOT EXISTS ginger_sync;

-- ── Settings (key/value json) ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.settings (
    key        TEXT PRIMARY KEY,
    value      JSONB NOT NULL,
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- ── Webhook registrations (Trello + ClickUp) ─────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.webhook_registrations (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    platform        TEXT NOT NULL CHECK (platform IN ('clickup', 'trello')),
    external_id     TEXT NOT NULL,
    target_id       TEXT NOT NULL,
    status          TEXT NOT NULL CHECK (status IN ('active', 'disabled', 'failed')),
    last_checked_at TIMESTAMPTZ,
    created_at      TIMESTAMPTZ DEFAULT NOW()
);

-- ── Hot Plate ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.hot_plate_categories (
    id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name       TEXT NOT NULL,
    color      TEXT NOT NULL DEFAULT 'blue',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS ginger_sync.hot_plate_items (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title         TEXT NOT NULL,
    description   TEXT,
    column_key    TEXT NOT NULL DEFAULT 'todo' CHECK (column_key IN ('todo', 'in_progress', 'waiting', 'done')),
    priority      INTEGER NOT NULL DEFAULT 2 CHECK (priority BETWEEN 1 AND 4),
    due_date      DATE,
    position      INTEGER NOT NULL DEFAULT 0,
    category_id   UUID REFERENCES ginger_sync.hot_plate_categories(id) ON DELETE SET NULL,
    energy_level  TEXT CHECK (energy_level IS NULL OR energy_level IN ('quick', 'social', 'deep', 'creative')),
    created_at    TIMESTAMPTZ DEFAULT NOW(),
    updated_at    TIMESTAMPTZ DEFAULT NOW(),
    deleted_at    TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS hot_plate_items_column_idx ON ginger_sync.hot_plate_items(column_key) WHERE deleted_at IS NULL;

-- ── Meetings ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.meetings (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title                    TEXT NOT NULL DEFAULT 'Untitled meeting',
    recorded_at              TIMESTAMPTZ DEFAULT NOW(),
    duration_ms              INTEGER,
    language                 TEXT NOT NULL DEFAULT 'en',
    status                   TEXT NOT NULL DEFAULT 'uploaded' CHECK (status IN (
        'uploaded', 'transcribing', 'analyzing', 'ready', 'error', 'audio_only'
    )),
    error_message            TEXT,
    audio_path               TEXT,
    audio_mime               TEXT,
    audio_size_bytes         BIGINT,
    audio_extension          TEXT,
    speakers_expected        INTEGER CHECK (speakers_expected IS NULL OR speakers_expected BETWEEN 1 AND 10),
    transcript               TEXT,
    assemblyai_transcript_id TEXT,
    analysis                 JSONB,
    hot_plate_item_id        UUID REFERENCES ginger_sync.hot_plate_items(id) ON DELETE SET NULL,
    created_at               TIMESTAMPTZ DEFAULT NOW(),
    updated_at               TIMESTAMPTZ DEFAULT NOW(),
    deleted_at               TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS meetings_status_idx     ON ginger_sync.meetings(status) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS meetings_recorded_idx   ON ginger_sync.meetings(recorded_at DESC) WHERE deleted_at IS NULL;

-- ── Grants (Supabase service_role / postgres) ────────────────────────
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'service_role') THEN
        EXECUTE 'GRANT USAGE ON SCHEMA ginger_sync TO service_role';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA ginger_sync TO service_role';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA ginger_sync GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO service_role';
    END IF;
END$$;

-- ── Report what now exists ───────────────────────────────────────────
SELECT table_name
FROM information_schema.tables
WHERE table_schema = 'ginger_sync'
ORDER BY table_name;
