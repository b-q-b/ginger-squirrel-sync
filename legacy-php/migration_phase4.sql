-- Ginger Sync — Phase 4: Meetings (audio upload + transcription + AI analysis)
-- Run once in the Supabase SQL editor.
-- Run AFTER migration_phase3.sql (Hot Plate) — meetings has an FK to hot_plate_items.

CREATE TABLE IF NOT EXISTS ginger_sync.meetings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title TEXT NOT NULL DEFAULT 'Untitled meeting',
    recorded_at TIMESTAMPTZ DEFAULT NOW(),
    duration_ms INTEGER,
    language TEXT NOT NULL DEFAULT 'en',

    -- State machine: uploaded → transcribing → analyzing → ready (or error / audio_only)
    status TEXT NOT NULL DEFAULT 'uploaded' CHECK (status IN (
        'uploaded', 'transcribing', 'analyzing', 'ready', 'error', 'audio_only'
    )),
    error_message TEXT,

    -- Audio file metadata (the actual file lives on disk at data/meetings/{id}/audio.{ext})
    audio_path TEXT,            -- relative path within data/meetings/
    audio_mime TEXT,
    audio_size_bytes BIGINT,
    audio_extension TEXT,       -- 'mp3' | 'm4a' | 'wav' | 'webm' | 'ogg' | 'mp4'

    -- Transcription
    speakers_expected INTEGER CHECK (speakers_expected IS NULL OR speakers_expected BETWEEN 1 AND 10),
    transcript TEXT,
    assemblyai_transcript_id TEXT,

    -- Analysis result (JSONB with summary, decisions, action_items, questions)
    analysis JSONB,

    -- Linkage
    hot_plate_item_id UUID REFERENCES ginger_sync.hot_plate_items(id) ON DELETE SET NULL,

    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    deleted_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS meetings_status_idx ON ginger_sync.meetings(status) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS meetings_recorded_idx ON ginger_sync.meetings(recorded_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS meetings_hot_plate_idx ON ginger_sync.meetings(hot_plate_item_id) WHERE hot_plate_item_id IS NOT NULL;

GRANT SELECT, INSERT, UPDATE, DELETE ON ginger_sync.meetings TO service_role;
