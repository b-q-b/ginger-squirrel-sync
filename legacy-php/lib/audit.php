<?php
/**
 * Append a row to the sync_events audit log. Best-effort — never throws.
 */
function logSyncEvent(Supabase $db, array $event): void {
    $row = array_merge([
        'source' => 'unknown',
        'direction' => null,
        'action' => 'noop',
        'trello_card_id' => null,
        'clickup_task_id' => null,
        'mapping_id' => null,
        'status' => 'ok',
        'error' => null,
        'payload_hash' => null,
    ], $event);

    try {
        $db->insert('sync_events', $row);
    } catch (Throwable $e) {
        error_log('logSyncEvent failed: ' . $e->getMessage());
    }
}
