-- Ginger Sync — Phase 2.1: fix shadow tasks unique constraints.
-- The partial unique indexes from phase 2 work in PostgreSQL but PostgREST
-- can't reference them in `on_conflict=` upserts. Replace with regular
-- UNIQUE constraints (PostgreSQL still allows multiple NULL values in
-- regular UNIQUE columns by default, which is what we want).
--
-- Run this once in your Supabase SQL editor.

DROP INDEX IF EXISTS ginger_sync.tasks_trello_uq;
DROP INDEX IF EXISTS ginger_sync.tasks_clickup_uq;

ALTER TABLE ginger_sync.tasks
    ADD CONSTRAINT tasks_trello_uq UNIQUE (trello_card_id);
ALTER TABLE ginger_sync.tasks
    ADD CONSTRAINT tasks_clickup_uq UNIQUE (clickup_task_id);

-- Clean up any debug rows left from earlier testing
DELETE FROM ginger_sync.tasks WHERE trello_card_id LIKE 'TEST_ROW_DELETE_ME_%' OR trello_card_id IN ('fake_trello_2');
