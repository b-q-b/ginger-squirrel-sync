-- Ginger Sync v2 — initial schema (.NET + React on Hostinger VPS)
-- Mirrors the PHP version's `ginger_sync` schema. Run once.

CREATE SCHEMA IF NOT EXISTS ginger_sync;

-- ── Sync core ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.mappings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    label TEXT NOT NULL,
    trello_board_id TEXT NOT NULL,
    trello_list_id TEXT,
    click_up_space_id TEXT NOT NULL,
    click_up_list_id TEXT NOT NULL,
    status_map JSONB NOT NULL DEFAULT '{}'::jsonb,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS ginger_sync.sync_map (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    mapping_id UUID NOT NULL REFERENCES ginger_sync.mappings(id) ON DELETE CASCADE,
    trello_card_id TEXT,
    click_up_task_id TEXT,
    last_hash TEXT,
    last_direction TEXT CHECK (last_direction IN ('TrelloToClickUp', 'ClickUpToTrello')),
    last_synced_at TIMESTAMPTZ,
    deleted_at TIMESTAMPTZ,
    CONSTRAINT sync_map_trello_uq UNIQUE (trello_card_id),
    CONSTRAINT sync_map_clickup_uq UNIQUE (click_up_task_id)
);

CREATE INDEX IF NOT EXISTS sync_map_mapping_idx ON ginger_sync.sync_map(mapping_id);

CREATE TABLE IF NOT EXISTS ginger_sync.sync_events (
    id BIGSERIAL PRIMARY KEY,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    source TEXT NOT NULL,
    direction TEXT,
    action TEXT NOT NULL,
    trello_card_id TEXT,
    click_up_task_id TEXT,
    mapping_id UUID,
    status TEXT NOT NULL,
    error TEXT,
    payload_hash TEXT
);

CREATE INDEX IF NOT EXISTS sync_events_created_idx ON ginger_sync.sync_events(created_at DESC);

-- ── Hot Plate ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.hot_plate_categories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT 'blue',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS ginger_sync.hot_plate_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title TEXT NOT NULL,
    description TEXT,
    "column" TEXT NOT NULL DEFAULT 'Todo' CHECK ("column" IN ('Todo','InProgress','Waiting','Done')),
    priority INT NOT NULL DEFAULT 2 CHECK (priority BETWEEN 1 AND 4),
    due_date DATE,
    position INT NOT NULL DEFAULT 0,
    category_id UUID REFERENCES ginger_sync.hot_plate_categories(id) ON DELETE SET NULL,
    energy_level TEXT CHECK (energy_level IS NULL OR energy_level IN ('Quick','Social','Deep','Creative')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ
);

-- ── Meetings ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.meetings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title TEXT NOT NULL DEFAULT 'Untitled meeting',
    recorded_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    duration_ms INT,
    language TEXT NOT NULL DEFAULT 'en',
    status TEXT NOT NULL DEFAULT 'Uploaded'
        CHECK (status IN ('Uploaded','Transcribing','Analyzing','Ready','Error','AudioOnly')),
    error_message TEXT,
    audio_path TEXT,
    audio_mime TEXT,
    audio_size_bytes BIGINT,
    audio_extension TEXT,
    speakers_expected INT CHECK (speakers_expected IS NULL OR speakers_expected BETWEEN 1 AND 10),
    transcript TEXT,
    assembly_a_i_transcript_id TEXT,
    analysis JSONB,
    hot_plate_item_id UUID REFERENCES ginger_sync.hot_plate_items(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS meetings_status_idx ON ginger_sync.meetings(status) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS meetings_recorded_idx ON ginger_sync.meetings(recorded_at DESC) WHERE deleted_at IS NULL;
