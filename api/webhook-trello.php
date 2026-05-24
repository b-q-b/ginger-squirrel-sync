<?php
/**
 * Trello webhook receiver.
 *
 * Trello pings this endpoint with HEAD on registration to verify it exists,
 * then POSTs an `action` JSON payload on every event.
 *
 * Signature: header `X-Trello-Webhook` is base64(HMAC-SHA1(secret, body + callbackUrl))
 * Docs: https://developer.atlassian.com/cloud/trello/guides/rest-api/webhooks/
 */
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../lib/sync_engine.php';

// Trello sends HEAD to verify the URL — must respond 200 quickly.
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_TRELLO_WEBHOOK'] ?? '';
$secret = $_ENV['TRELLO_SECRET'] ?? '';
$callbackUrl = ($_ENV['PUBLIC_BASE_URL'] ?? '') . '/api/webhook-trello.php';

// Signature verification
if ($secret && $signature) {
    $computed = base64_encode(hash_hmac('sha1', $body . $callbackUrl, $secret, true));
    if (!hash_equals($computed, $signature)) {
        error_log('Trello webhook bad signature');
        http_response_code(401);
        exit;
    }
}

// Always return 200 ASAP — Trello disables webhook after consecutive failures.
// We process the event after sending the response.
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

try {
    $payload = json_decode($body, true);
    $action = $payload['action'] ?? null;
    if (!$action) exit;

    $type = (string)($action['type'] ?? '');
    $card = $action['data']['card'] ?? null;
    $boardId = (string)($action['data']['board']['id'] ?? '');
    if (!$card || !$boardId) exit;

    $cardId = (string)($card['id'] ?? '');
    if ($cardId === '') exit;

    $db = new Supabase('service');

    // Find the mapping for this board (active only)
    $mr = $db->from('mappings', 'select=*&trello_board_id=eq.' . urlencode($boardId) . '&is_active=eq.true&limit=1');
    $mapping = $mr['data'][0] ?? null;
    if (!$mapping) exit;

    // Check actor is not us (avoid echo from our own writes)
    $actorId = (string)($action['idMemberCreator'] ?? '');
    $tr = new Trello();
    static $ourTrelloId = null;
    if ($ourTrelloId === null) {
        $me = $tr->me();
        $ourTrelloId = $me['ok'] ? (string)($me['data']['id'] ?? '') : '';
    }
    if ($actorId && $ourTrelloId && $actorId === $ourTrelloId) {
        logSyncEvent($db, [
            'source' => 'trello_webhook', 'direction' => 'trello_to_clickup',
            'action' => 'skip_echo', 'trello_card_id' => $cardId,
            'mapping_id' => $mapping['id'], 'status' => 'skipped',
            'error' => 'actor is our integration user',
        ]);
        exit;
    }

    // Handle delete events
    if ($type === 'deleteCard') {
        $existing = findSyncMap($db, $cardId, null);
        if ($existing && !empty($existing['clickup_task_id'])) {
            $cu = new ClickUp();
            $cu->deleteTask((string)$existing['clickup_task_id']);
            $db->update('sync_map', 'id=eq.' . urlencode((string)$existing['id']), ['deleted_at' => gmdate('c')]);
            logSyncEvent($db, [
                'source' => 'trello_webhook', 'direction' => 'trello_to_clickup',
                'action' => 'delete', 'trello_card_id' => $cardId,
                'clickup_task_id' => $existing['clickup_task_id'],
                'mapping_id' => $mapping['id'], 'status' => 'ok',
            ]);
        }
        exit;
    }

    // For create/update events, fetch the full card (Trello's webhook payload is partial)
    $full = $tr->card($cardId);
    if (!$full['ok']) {
        logSyncEvent($db, [
            'source' => 'trello_webhook', 'direction' => 'trello_to_clickup',
            'action' => 'fetch_failed', 'trello_card_id' => $cardId,
            'mapping_id' => $mapping['id'], 'status' => 'error',
            'error' => $full['error'],
        ]);
        exit;
    }

    syncTrelloCardToClickUp($db, $mapping, $full['data'], 'trello_webhook');
} catch (Throwable $e) {
    error_log('webhook-trello: ' . $e->getMessage());
}
