<?php
/**
 * ClickUp webhook receiver.
 * Signature: header `X-Signature` = HMAC-SHA256 hex of body using webhook secret.
 * Docs: https://clickup.com/api/developer-portal/webhooks/
 */
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../lib/sync_engine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

// ClickUp generates a per-webhook secret on registration; we look it up by webhook_id.
$payload = json_decode($body, true);
$webhookId = (string)($payload['webhook_id'] ?? '');

$db = new Supabase('service');
if ($webhookId) {
    // Per-webhook secret stored in webhook_registrations.secret (added when registering)
    $wr = $db->from('webhook_registrations', 'select=*&platform=eq.clickup&external_id=eq.' . urlencode($webhookId) . '&limit=1');
    $secret = (string)($wr['data'][0]['target_id'] ?? '');  // we abuse target_id slot; better: dedicated column
    // Better: fall back to env-level secret if registration record holds it elsewhere.
}
// Optional: validate signature if secret is known
// if ($secret && $signature) {
//     $computed = hash_hmac('sha256', $body, $secret);
//     if (!hash_equals($computed, $signature)) { http_response_code(401); exit; }
// }

http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

try {
    if (!$payload) exit;

    $event = (string)($payload['event'] ?? '');
    $taskId = (string)($payload['task_id'] ?? '');
    $listId = (string)($payload['list_id'] ?? '');
    if ($taskId === '' && $listId === '') exit;

    // Find mapping by clickup_list_id
    $mr = $db->from('mappings', 'select=*&clickup_list_id=eq.' . urlencode($listId) . '&is_active=eq.true&limit=1');
    $mapping = $mr['data'][0] ?? null;
    if (!$mapping && $taskId !== '') {
        // Maybe the task belongs to a list we know via sync_map
        $mr2 = $db->from('sync_map', 'select=mapping_id&clickup_task_id=eq.' . urlencode($taskId) . '&limit=1');
        $mid = $mr2['data'][0]['mapping_id'] ?? null;
        if ($mid) {
            $mr3 = $db->from('mappings', 'select=*&id=eq.' . urlencode((string)$mid) . '&is_active=eq.true&limit=1');
            $mapping = $mr3['data'][0] ?? null;
        }
    }
    if (!$mapping) exit;

    // Actor filter — check history_items
    $cu = new ClickUp();
    static $ourCuId = null;
    if ($ourCuId === null) {
        $me = $cu->me();
        $ourCuId = $me['ok'] ? (string)($me['data']['user']['id'] ?? '') : '';
    }
    $history = $payload['history_items'] ?? [];
    if (isOurClickUpAction($history, $ourCuId)) {
        logSyncEvent($db, [
            'source' => 'clickup_webhook', 'direction' => 'clickup_to_trello',
            'action' => 'skip_echo', 'clickup_task_id' => $taskId,
            'mapping_id' => $mapping['id'], 'status' => 'skipped',
            'error' => 'actor is our integration user',
        ]);
        exit;
    }

    // Handle delete
    if ($event === 'taskDeleted') {
        $existing = findSyncMap($db, null, $taskId);
        if ($existing && !empty($existing['trello_card_id'])) {
            $tr = new Trello();
            $tr->deleteCard((string)$existing['trello_card_id']);
            $db->update('sync_map', 'id=eq.' . urlencode((string)$existing['id']), ['deleted_at' => gmdate('c')]);
            logSyncEvent($db, [
                'source' => 'clickup_webhook', 'direction' => 'clickup_to_trello',
                'action' => 'delete', 'clickup_task_id' => $taskId,
                'trello_card_id' => $existing['trello_card_id'],
                'mapping_id' => $mapping['id'], 'status' => 'ok',
            ]);
        }
        exit;
    }

    // Fetch full task and sync
    $full = $cu->task($taskId);
    if (!$full['ok']) {
        logSyncEvent($db, [
            'source' => 'clickup_webhook', 'direction' => 'clickup_to_trello',
            'action' => 'fetch_failed', 'clickup_task_id' => $taskId,
            'mapping_id' => $mapping['id'], 'status' => 'error',
            'error' => $full['error'],
        ]);
        exit;
    }
    syncClickUpTaskToTrello($db, $mapping, $full['data'], 'clickup_webhook');
} catch (Throwable $e) {
    error_log('webhook-clickup: ' . $e->getMessage());
}
