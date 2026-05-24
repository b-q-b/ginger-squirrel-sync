<?php
/**
 * Meetings CRUD endpoint.
 *
 *   GET  /api/meetings.php                → list (latest first)
 *   GET  /api/meetings.php?id=X           → single
 *   POST /api/meetings.php { action: 'update', id, title?|hot_plate_item_id?|speakers_expected? }
 *   POST /api/meetings.php { action: 'delete', id }
 *   POST /api/meetings.php { action: 'retry', id }   ← re-run pipeline for a failed meeting
 */
require_once __DIR__ . '/../config/auth.php';
requireAuth();
header('Content-Type: application/json');

$db = new Supabase('service');
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $id = $_GET['id'] ?? '';
        if ($id) {
            $r = $db->from('meetings', 'select=*&id=eq.' . urlencode($id) . '&deleted_at=is.null&limit=1');
            $row = $r['data'][0] ?? null;
            if (!$row) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not found']); exit; }
            echo json_encode(['ok' => true, 'meeting' => $row]);
            exit;
        }
        // List — drop heavy fields
        $r = $db->from('meetings', 'select=id,title,recorded_at,duration_ms,status,error_message,hot_plate_item_id,created_at,updated_at&deleted_at=is.null&order=recorded_at.desc&limit=200');
        echo json_encode(['ok' => true, 'meetings' => $r['data'] ?? []]);
        exit;
    }

    if ($method !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method not allowed']); exit; }
    requireCsrf();

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($body['action'] ?? '');
    $id = (string)($body['id'] ?? '');

    if ($action === 'update') {
        if (!$id) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id required']); exit; }
        $patch = [];
        foreach (['title', 'hot_plate_item_id', 'speakers_expected', 'language'] as $k) {
            if (array_key_exists($k, $body)) $patch[$k] = $body[$k];
        }
        $patch['updated_at'] = gmdate('c');
        $r = $db->update('meetings', 'id=eq.' . urlencode($id), $patch);
        echo json_encode(['ok' => empty($r['error']), 'error' => $r['error']]);
        exit;
    }

    if ($action === 'delete') {
        if (!$id) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id required']); exit; }
        $r = $db->update('meetings', 'id=eq.' . urlencode($id), ['deleted_at' => gmdate('c')]);
        echo json_encode(['ok' => empty($r['error']), 'error' => $r['error']]);
        exit;
    }

    if ($action === 'retry') {
        if (!$id) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id required']); exit; }
        $r = $db->update('meetings', 'id=eq.' . urlencode($id), [
            'status' => 'uploaded',
            'error_message' => null,
            'assemblyai_transcript_id' => null,
            'updated_at' => gmdate('c'),
        ]);
        echo json_encode(['ok' => empty($r['error']), 'error' => $r['error']]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
