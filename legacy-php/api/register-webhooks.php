<?php
/**
 * Register webhooks with ClickUp and Trello for all active mappings.
 * Idempotent: skips registrations that already exist + are active.
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

$baseUrl = rtrim($_ENV['PUBLIC_BASE_URL'] ?? '', '/');
$trelloHook  = $baseUrl . '/api/webhook-trello.php';
$clickupHook = $baseUrl . '/api/webhook-clickup.php';

try {
    $db = new Supabase('service');
    $cu = new ClickUp();
    $tr = new Trello();

    $mappingsRes = $db->from('mappings', 'select=*&is_active=eq.true');
    $mappings = $mappingsRes['data'] ?? [];

    $existingRes = $db->from('webhook_registrations', 'select=*&status=eq.active');
    $existing = $existingRes['data'] ?? [];
    $existingByTarget = [];
    foreach ($existing as $w) {
        $existingByTarget[(string)$w['platform'] . ':' . (string)$w['target_id']] = $w;
    }

    $results = [];

    // ── ClickUp: webhook per workspace (team), filtered to listIds we care about ──
    $teams = $cu->teams();
    $teamIds = [];
    foreach (($teams['data']['teams'] ?? []) as $t) $teamIds[] = (string)$t['id'];

    foreach ($teamIds as $teamId) {
        $key = 'clickup:' . $teamId;
        if (isset($existingByTarget[$key])) {
            $results[] = ['platform' => 'clickup', 'target_id' => $teamId, 'status' => 'already-registered'];
            continue;
        }
        $listIds = [];
        foreach ($mappings as $m) {
            $lid = (string)($m['clickup_list_id'] ?? '');
            if ($lid !== '') $listIds[] = $lid;
        }
        $listIds = array_values(array_unique($listIds));

        $body = [
            'endpoint' => $clickupHook,
            'events' => ['taskCreated', 'taskUpdated', 'taskDeleted', 'taskStatusUpdated'],
        ];
        if ($listIds) $body['list_id'] = $listIds[0]; // ClickUp accepts list_id as filter
        $r = $cu->request('POST', "/team/{$teamId}/webhook", $body);
        if ($r['ok']) {
            $whId = (string)($r['data']['id'] ?? '');
            $secret = (string)($r['data']['secret'] ?? $r['data']['webhook']['secret'] ?? '');
            $db->insert('webhook_registrations', [
                'platform' => 'clickup',
                'external_id' => $whId,
                'target_id' => $teamId,
                'status' => 'active',
                'last_checked_at' => gmdate('c'),
            ]);
            $results[] = ['platform' => 'clickup', 'target_id' => $teamId, 'status' => 'registered', 'webhook_id' => $whId];
        } else {
            $results[] = ['platform' => 'clickup', 'target_id' => $teamId, 'status' => 'error', 'error' => $r['error']];
        }
    }

    // ── Trello: one webhook per board ──
    $boardIds = [];
    foreach ($mappings as $m) {
        $bid = (string)($m['trello_board_id'] ?? '');
        if ($bid !== '') $boardIds[] = $bid;
    }
    $boardIds = array_values(array_unique($boardIds));

    foreach ($boardIds as $bid) {
        $key = 'trello:' . $bid;
        if (isset($existingByTarget[$key])) {
            $results[] = ['platform' => 'trello', 'target_id' => $bid, 'status' => 'already-registered'];
            continue;
        }
        $r = $tr->request('POST', '/webhooks', null, [
            'callbackURL' => $trelloHook,
            'idModel' => $bid,
            'description' => 'Ginger Sync — board ' . $bid,
        ]);
        if ($r['ok']) {
            $whId = (string)($r['data']['id'] ?? '');
            $db->insert('webhook_registrations', [
                'platform' => 'trello',
                'external_id' => $whId,
                'target_id' => $bid,
                'status' => 'active',
                'last_checked_at' => gmdate('c'),
            ]);
            $results[] = ['platform' => 'trello', 'target_id' => $bid, 'status' => 'registered', 'webhook_id' => $whId];
        } else {
            $results[] = ['platform' => 'trello', 'target_id' => $bid, 'status' => 'error', 'error' => $r['error']];
        }
    }

    echo json_encode(['ok' => true, 'results' => $results]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
