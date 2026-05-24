<?php
/**
 * One-shot cleanup: delete Trello cards that were created from ClickUp subtasks
 * during a previous sync run (before subtask filtering was added).
 *
 * Identifies them by: sync_map row whose ClickUp task has parent != null.
 * Deletes the Trello card and soft-deletes the sync_map row.
 *
 * Returns a JSON summary. Run with ?dry_run=1 to preview without deleting.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/clickup.php';
require_once __DIR__ . '/../config/trello.php';
requireAuth();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}
requireCsrf();

$dryRun = !empty($_GET['dry_run']);

try {
    $db = new Supabase('service');
    $cu = new ClickUp();
    $tr = new Trello();

    // Get all active sync_map rows (not soft-deleted)
    $smRes = $db->from('sync_map', 'select=*&deleted_at=is.null');
    $rows = $smRes['data'] ?? [];

    $orphans = [];
    foreach ($rows as $row) {
        $cuId = (string)($row['clickup_task_id'] ?? '');
        if (!$cuId) continue;
        $tRes = $cu->task($cuId);
        if (!$tRes['ok']) continue;
        $parent = $tRes['data']['parent'] ?? null;
        if ($parent) {
            $orphans[] = [
                'sync_map_id' => $row['id'],
                'trello_card_id' => $row['trello_card_id'],
                'clickup_task_id' => $cuId,
                'task_name' => (string)($tRes['data']['name'] ?? ''),
                'parent_task_id' => $parent,
            ];
        }
    }

    $deleted = 0;
    $errors = [];
    if (!$dryRun) {
        foreach ($orphans as $o) {
            $tCardId = (string)($o['trello_card_id'] ?? '');
            if ($tCardId) {
                $r = $tr->deleteCard($tCardId);
                if (!$r['ok']) $errors[] = "Trello {$tCardId}: {$r['error']}";
            }
            $db->update('sync_map', 'id=eq.' . urlencode((string)$o['sync_map_id']), ['deleted_at' => gmdate('c')]);
            $deleted++;
        }
    }

    echo json_encode([
        'ok' => true,
        'dry_run' => $dryRun,
        'orphan_count' => count($orphans),
        'orphans' => $orphans,
        'deleted' => $deleted,
        'errors' => $errors,
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
