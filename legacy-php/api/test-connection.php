<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/clickup.php';
require_once __DIR__ . '/../config/trello.php';
requireAuth();
header('Content-Type: application/json');

$platform = $_GET['platform'] ?? '';

try {
    if ($platform === 'clickup') {
        $cu = new ClickUp();
        if (!$cu->configured()) {
            echo json_encode(['ok' => false, 'error' => 'CLICKUP_TOKEN not set in .env']);
            exit;
        }
        $r = $cu->me();
        if (!$r['ok']) {
            echo json_encode(['ok' => false, 'error' => $r['error'], 'status' => $r['status']]);
            exit;
        }
        $u = $r['data']['user'] ?? [];
        $teams = $cu->teams();
        $teamCount = count($teams['data']['teams'] ?? []);
        echo json_encode([
            'ok' => true,
            'platform' => 'clickup',
            'user' => [
                'id' => $u['id'] ?? null,
                'username' => $u['username'] ?? null,
                'email' => $u['email'] ?? null,
            ],
            'teams' => $teamCount,
        ]);
        exit;
    }

    if ($platform === 'trello') {
        $tr = new Trello();
        if (!$tr->configured()) {
            echo json_encode(['ok' => false, 'error' => 'TRELLO_KEY or TRELLO_TOKEN not set in .env']);
            exit;
        }
        $r = $tr->me();
        if (!$r['ok']) {
            echo json_encode(['ok' => false, 'error' => $r['error'], 'status' => $r['status']]);
            exit;
        }
        $boards = $tr->boards();
        $boardCount = is_array($boards['data'] ?? null) ? count($boards['data']) : 0;
        echo json_encode([
            'ok' => true,
            'platform' => 'trello',
            'user' => [
                'id' => $r['data']['id'] ?? null,
                'username' => $r['data']['username'] ?? null,
                'fullName' => $r['data']['fullName'] ?? null,
            ],
            'boards' => $boardCount,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown platform — use ?platform=clickup|trello']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
