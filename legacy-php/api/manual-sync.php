<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/sync_engine.php';
requireAuth();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}
requireCsrf();

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$mappingId = (string)($body['mapping_id'] ?? '');
if ($mappingId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing mapping_id']);
    exit;
}

try {
    $db = new Supabase('service');
    $r = $db->from('mappings', 'select=*&id=eq.' . urlencode($mappingId) . '&limit=1');
    if (empty($r['data'][0])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Mapping not found']);
        exit;
    }
    $mapping = $r['data'][0];

    if (empty($mapping['is_active'])) {
        echo json_encode(['ok' => false, 'error' => 'Mapping is paused — resume it first']);
        exit;
    }

    set_time_limit(120);
    $stats = reconcileMapping($db, $mapping, 'manual');
    echo json_encode(['ok' => true, 'stats' => $stats]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
