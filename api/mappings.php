<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
header('Content-Type: application/json');

$db = new Supabase('service');
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $r = $db->from('mappings', 'select=*&order=created_at.desc');
        echo json_encode(['ok' => empty($r['error']), 'data' => $r['data'] ?? [], 'error' => $r['error']]);
        exit;
    }

    // All mutations require CSRF
    requireCsrf();

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $required = ['label', 'trello_board_id', 'clickup_space_id', 'clickup_list_id'];
        foreach ($required as $k) {
            if (empty($body[$k])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => "Missing $k"]);
                exit;
            }
        }
        $row = [
            'label' => (string)$body['label'],
            'trello_board_id' => (string)$body['trello_board_id'],
            'trello_list_id' => !empty($body['trello_list_id']) ? (string)$body['trello_list_id'] : null,
            'clickup_space_id' => (string)$body['clickup_space_id'],
            'clickup_list_id' => (string)$body['clickup_list_id'],
            'status_map' => $body['status_map'] ?? new stdClass(),
            'is_active' => !empty($body['is_active']),
        ];
        $r = $db->insert('mappings', $row);
        echo json_encode(['ok' => empty($r['error']), 'data' => $r['data'][0] ?? null, 'error' => $r['error']]);
        exit;
    }

    if ($method === 'PATCH') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = $body['id'] ?? '';
        if (!$id) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'missing id']); exit; }
        unset($body['id']);
        $r = $db->update('mappings', 'id=eq.' . urlencode($id), $body);
        echo json_encode(['ok' => empty($r['error']), 'error' => $r['error']]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? '';
        if (!$id) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'missing id']); exit; }
        $r = $db->delete('mappings', 'id=eq.' . urlencode($id));
        echo json_encode(['ok' => empty($r['error']), 'error' => $r['error']]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
