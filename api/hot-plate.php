<?php
/**
 * Hot Plate CRUD endpoint.
 *
 *   GET  /api/hot-plate.php                         → list items + categories
 *   POST /api/hot-plate.php  { action: 'create', ...item }
 *   POST /api/hot-plate.php  { action: 'update', id, ...fields }
 *   POST /api/hot-plate.php  { action: 'delete', id }
 *   POST /api/hot-plate.php  { action: 'reorder', moves: [{id, column_key, position}] }
 *   POST /api/hot-plate.php  { action: 'category_create', name, color }
 *   POST /api/hot-plate.php  { action: 'category_update', id, name?, color? }
 *   POST /api/hot-plate.php  { action: 'category_delete', id }
 */
require_once __DIR__ . '/../config/auth.php';
requireAuth();
header('Content-Type: application/json');

$db = new Supabase('service');
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $items = $db->from('hot_plate_items', 'select=*&deleted_at=is.null&order=position.asc');
        $cats  = $db->from('hot_plate_categories', 'select=*&order=sort_order.asc');
        echo json_encode([
            'ok' => true,
            'items' => $items['data'] ?? [],
            'categories' => $cats['data'] ?? [],
        ]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'method not allowed']);
        exit;
    }

    requireCsrf();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($body['action'] ?? '');

    switch ($action) {
        case 'create': {
            // Determine next position in target column
            $col = (string)($body['column_key'] ?? 'todo');
            $posRes = $db->from('hot_plate_items', 'select=position&deleted_at=is.null&column_key=eq.' . urlencode($col) . '&order=position.desc&limit=1');
            $maxPos = (int)($posRes['data'][0]['position'] ?? -1);

            $row = [
                'title'        => trim((string)($body['title'] ?? '')),
                'description'  => isset($body['description']) ? (string)$body['description'] : null,
                'column_key'   => $col,
                'priority'     => max(1, min(4, (int)($body['priority'] ?? 2))),
                'due_date'     => $body['due_date'] ?? null,
                'category_id'  => $body['category_id'] ?? null,
                'energy_level' => $body['energy_level'] ?? null,
                'position'     => $maxPos + 1,
            ];
            if ($row['title'] === '') {
                http_response_code(400); echo json_encode(['ok' => false, 'error' => 'title required']); exit;
            }
            $r = $db->insert('hot_plate_items', $row);
            echo json_encode(['ok' => empty($r['error']), 'item' => $r['data'][0] ?? null, 'error' => $r['error']]);
            exit;
        }

        case 'update': {
            $id = (string)($body['id'] ?? '');
            if (!$id) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id required']); exit; }
            $patch = [];
            foreach (['title','description','column_key','priority','due_date','category_id','energy_level','position'] as $k) {
                if (array_key_exists($k, $body)) $patch[$k] = $body[$k];
            }
            $patch['updated_at'] = gmdate('c');
            if (isset($patch['priority'])) $patch['priority'] = max(1, min(4, (int)$patch['priority']));
            $r = $db->update('hot_plate_items', 'id=eq.' . urlencode($id), $patch);
            echo json_encode(['ok' => empty($r['error']), 'error' => $r['error']]);
            exit;
        }

        case 'delete': {
            $id = (string)($body['id'] ?? '');
            if (!$id) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id required']); exit; }
            // Soft delete
            $r = $db->update('hot_plate_items', 'id=eq.' . urlencode($id), ['deleted_at' => gmdate('c')]);
            echo json_encode(['ok' => empty($r['error']), 'error' => $r['error']]);
            exit;
        }

        case 'reorder': {
            $moves = $body['moves'] ?? [];
            if (!is_array($moves)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'moves required']); exit; }
            foreach ($moves as $m) {
                $id = (string)($m['id'] ?? '');
                if (!$id) continue;
                $patch = [
                    'column_key' => (string)($m['column_key'] ?? 'todo'),
                    'position'   => (int)($m['position'] ?? 0),
                    'updated_at' => gmdate('c'),
                ];
                $db->update('hot_plate_items', 'id=eq.' . urlencode($id), $patch);
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'category_create': {
            $name = trim((string)($body['name'] ?? ''));
            if ($name === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'name required']); exit; }
            $sortRes = $db->from('hot_plate_categories', 'select=sort_order&order=sort_order.desc&limit=1');
            $maxSort = (int)($sortRes['data'][0]['sort_order'] ?? -1);
            $r = $db->insert('hot_plate_categories', [
                'name' => $name,
                'color' => in_array($body['color'] ?? '', ['blue','green','purple','orange','amber','red','pink','cyan']) ? $body['color'] : 'blue',
                'sort_order' => $maxSort + 1,
            ]);
            echo json_encode(['ok' => empty($r['error']), 'category' => $r['data'][0] ?? null, 'error' => $r['error']]);
            exit;
        }

        case 'category_update': {
            $id = (string)($body['id'] ?? '');
            if (!$id) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id required']); exit; }
            $patch = [];
            foreach (['name','color','sort_order'] as $k) {
                if (array_key_exists($k, $body)) $patch[$k] = $body[$k];
            }
            $r = $db->update('hot_plate_categories', 'id=eq.' . urlencode($id), $patch);
            echo json_encode(['ok' => empty($r['error']), 'error' => $r['error']]);
            exit;
        }

        case 'category_delete': {
            $id = (string)($body['id'] ?? '');
            if (!$id) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id required']); exit; }
            $r = $db->delete('hot_plate_categories', 'id=eq.' . urlencode($id));
            echo json_encode(['ok' => empty($r['error']), 'error' => $r['error']]);
            exit;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
