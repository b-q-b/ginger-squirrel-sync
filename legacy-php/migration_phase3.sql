-- Ginger Sync — Phase 3: Hot Plate (Kanban personal task board)
-- Run once in the Supabase SQL editor.

-- ── Categories ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.hot_plate_categories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT 'blue',  -- blue|green|purple|orange|amber|red|pink|cyan
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT NOW()
);
GRANT SELECT, INSERT, UPDATE, DELETE ON ginger_sync.hot_plate_categories TO service_role;

-- ── Tasks ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ginger_sync.hot_plate_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title TEXT NOT NULL,
    description TEXT,
    column_key TEXT NOT NULL DEFAULT 'todo' CHECK (column_key IN ('todo', 'in_progress', 'waiting', 'done')),
    priority INTEGER NOT NULL DEFAULT 2 CHECK (priority BETWEEN 1 AND 4),  -- 1=low, 4=critical
    due_date DATE,
    position INTEGER NOT NULL DEFAULT 0,
    category_id UUID REFERENCES ginger_sync.hot_plate_categories(id) ON DELETE SET NULL,
    energy_level TEXT CHECK (energy_level IS NULL OR energy_level IN ('quick', 'social', 'deep', 'creative')),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    deleted_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS hot_plate_items_col_idx ON ginger_sync.hot_plate_items(column_key, position);
CREATE INDEX IF NOT EXISTS hot_plate_items_cat_idx ON ginger_sync.hot_plate_items(category_id) WHERE category_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS hot_plate_items_due_idx ON ginger_sync.hot_plate_items(due_date) WHERE due_date IS NOT NULL;

GRANT SELECT, INSERT, UPDATE, DELETE ON ginger_sync.hot_plate_items TO service_role;

-- ── Seed a couple of default categories ────────────────────────
INSERT INTO ginger_sync.hot_plate_categories (name, color, sort_order) VALUES
    ('General', 'blue', 0),
    ('Client work', 'orange', 1),
    ('Personal', 'green', 2)
ON CONFLICT DO NOTHING;
