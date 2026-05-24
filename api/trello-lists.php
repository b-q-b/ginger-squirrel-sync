<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/trello.php';
requireAuth();
header('Content-Type: application/json');

try {
    $boardId = $_GET['board'] ?? '';
    if (!$boardId) {
        echo json_encode(['ok' => false, 'error' => 'Missing ?board=']);
        exit;
    }
    $tr = new Trello();
    $r = $tr->lists($boardId);
    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'error' => $r['error']]);
        exit;
    }
    $lists = [];
    foreach (($r['data'] ?? []) as $l) {
        if (!empty($l['closed'])) continue;
        $lists[] = [
            'id' => (string)($l['id'] ?? ''),
            'name' => (string)($l['name'] ?? ''),
            'pos' => (float)($l['pos'] ?? 0),
        ];
    }
    echo json_encode(['ok' => true, 'lists' => $lists]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
