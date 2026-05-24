-- Ginger Sync — Phase 2: shadow `tasks` table.
-- Run once in your Supabase SQL editor.
--
-- Stores the canonical (last-known) state of every synced task.
-- Acts as a fast cache for the Items page (replaces live API calls)
-- AND seeds a future "master DB" without committing to one yet.

CREATE TABLE IF NOT EXISTS ginger_sync.tasks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    mapping_id UUID NOT NULL REFERENCES ginger_sync.mappings(id) ON DELETE CASCADE,
    sync_map_id UUID REFERENCES ginger_sync.sync_map(id) ON DELETE SET NULL,

    -- External identifiers (one or both depending on sync state)
    trello_card_id TEXT,
    clickup_task_id TEXT,
    parent_clickup_task_id TEXT,        -- for ClickUp subtasks

    -- Canonical fields (last known good state)
    name TEXT NOT NULL DEFAULT '',
    description TEXT,
    status TEXT,                        -- ClickUp status name (or first list name on Trello-only)
    due_date TIMESTAMPTZ,
    labels JSONB NOT NULL DEFAULT '[]'::jsonb,   -- [{name, color}]
    is_subtask BOOLEAN NOT NULL DEFAULT FALSE,

    -- Source-specific raw data for debugging / future fields
    trello_data JSONB,
    clickup_data JSONB,

    -- Bookkeeping
    last_seen_at TIMESTAMPTZ DEFAULT NOW(),       -- last time we observed this task on either platform
    last_changed_at TIMESTAMPTZ DEFAULT NOW(),    -- last time content actually changed
    created_at TIMESTAMPTZ DEFAULT NOW(),
    deleted_at TIMESTAMPTZ                        -- soft delete when removed from both platforms
);

-- Unique index on each external ID (NULL allowed for unsynced)
CREATE UNIQUE INDEX IF NOT EXISTS tasks_trello_uq ON ginger_sync.tasks(trello_card_id) WHERE trello_card_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS tasks_clickup_uq ON ginger_sync.tasks(clickup_task_id) WHERE clickup_task_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS tasks_mapping_idx ON ginger_sync.tasks(mapping_id);
CREATE INDEX IF NOT EXISTS tasks_last_seen_idx ON ginger_sync.tasks(last_seen_at DESC);
CREATE INDEX IF NOT EXISTS tasks_parent_idx ON ginger_sync.tasks(parent_clickup_task_id) WHERE parent_clickup_task_id IS NOT NULL;

GRANT SELECT, INSERT, UPDATE, DELETE ON ginger_sync.tasks TO service_role;
