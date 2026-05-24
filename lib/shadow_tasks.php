<?php
/**
 * Shadow tasks table integration.
 * Writes the canonical state of every synced task to ginger_sync.tasks
 * so the Items page can render fast without live API calls.
 */

/**
 * Build a canonical row from raw Trello + ClickUp payloads.
 * Either side may be null (orphan).
 */
function buildShadowRow(string $mappingId, ?string $syncMapId, ?array $trelloCard, ?array $clickupTask): array {
    $row = [
        'mapping_id' => $mappingId,
        'sync_map_id' => $syncMapId,
        'trello_card_id' => null,
        'clickup_task_id' => null,
        'parent_clickup_task_id' => null,
        'name' => '',
        'description' => null,
        'status' => null,
        'due_date' => null,
        'labels' => [],
        'is_subtask' => false,
        'trello_data' => null,
        'clickup_data' => null,
        'last_seen_at' => gmdate('c'),
    ];

    if ($trelloCard) {
        $row['trello_card_id'] = (string)($trelloCard['id'] ?? '') ?: null;
        $row['name'] = (string)($trelloCard['name'] ?? '');
        $row['description'] = (string)($trelloCard['desc'] ?? '');
        if (!empty($trelloCard['due'])) {
            $row['due_date'] = gmdate('c', strtotime((string)$trelloCard['due']));
        }
        $labels = [];
        foreach (($trelloCard['labels'] ?? []) as $l) {
            $labels[] = ['name' => (string)($l['name'] ?? ''), 'color' => (string)($l['color'] ?? '')];
        }
        $row['labels'] = $labels;
        $row['trello_data'] = $trelloCard;
    }

    if ($clickupTask) {
        $row['clickup_task_id'] = (string)($clickupTask['id'] ?? '') ?: null;
        // ClickUp wins for canonical name/description if both present
        $row['name'] = (string)($clickupTask['name'] ?? $row['name']);
        if (!empty($clickupTask['description'])) {
            $row['description'] = (string)$clickupTask['description'];
        }
        $row['status'] = (string)($clickupTask['status']['status'] ?? $row['status']);
        if (!empty($clickupTask['due_date'])) {
            $row['due_date'] = gmdate('c', (int)floor(((int)$clickupTask['due_date']) / 1000));
        }
        $parent = $clickupTask['parent'] ?? null;
        $row['parent_clickup_task_id'] = $parent ? (string)$parent : null;
        $row['is_subtask'] = (bool)$parent;
        // Merge labels: keep Trello labels if they had names; else use ClickUp tags
        if (empty($row['labels']) && !empty($clickupTask['tags'])) {
            $labels = [];
            foreach ($clickupTask['tags'] as $t) {
                $labels[] = ['name' => (string)($t['name'] ?? ''), 'color' => (string)($t['tag_bg'] ?? '')];
            }
            $row['labels'] = $labels;
        }
        $row['clickup_data'] = $clickupTask;
    }

    return $row;
}

/**
 * Upsert a shadow task row. Conflict on either trello_card_id or clickup_task_id.
 *
 * Strategy: if the row already exists keyed by either external ID, update it.
 * Otherwise insert. If both IDs are present, prefer trello_card_id as the conflict key.
 */
function upsertShadowTask(Supabase $db, array $row): void {
    try {
        $tId = $row['trello_card_id'] ?? null;
        $cId = $row['clickup_task_id'] ?? null;

        if ($tId) {
            $row['labels'] = $row['labels'] ?? [];
            $db->upsert('tasks', $row, 'trello_card_id');
            return;
        }
        if ($cId) {
            $db->upsert('tasks', $row, 'clickup_task_id');
            return;
        }
        // No external ID — just insert (rare)
        $db->insert('tasks', $row);
    } catch (Throwable $e) {
        error_log('upsertShadowTask: ' . $e->getMessage());
    }
}
