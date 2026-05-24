<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/trello.php';
requireAuth();
header('Content-Type: application/json');

try {
    $tr = new Trello();
    if (!$tr->configured()) {
        echo json_encode(['ok' => false, 'error' => 'Trello not configured']);
        exit;
    }
    $r = $tr->boards();
    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'error' => $r['error']]);
        exit;
    }
    $boards = [];
    foreach (($r['data'] ?? []) as $b) {
        if (!empty($b['closed'])) continue;
        $boards[] = [
            'id' => (string)($b['id'] ?? ''),
            'name' => (string)($b['name'] ?? ''),
            'url' => (string)($b['url'] ?? ''),
        ];
    }
    echo json_encode(['ok' => true, 'boards' => $boards]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
