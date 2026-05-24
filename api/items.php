<?php
/**
 * Returns a unified view of items for a mapping (or all mappings).
 *
 * Default: reads from the shadow `tasks` table (fast, no API calls).
 * Pass ?live=1 to refetch from Trello + ClickUp live (used by the Refresh button).
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/clickup.php';
require_once __DIR__ . '/../config/trello.php';
requireAuth();
header('Content-Type: application/json');

$mappingId = $_GET['mapping_id'] ?? '';
$live = !empty($_GET['live']);

try {
    $db = new Supabase('service');
    $cu = new ClickUp();
    $tr = new Trello();

    $filter = 'select=*' . ($mappingId ? '&id=eq.' . urlencode($mappingId) : '&is_active=eq.true');
    $mres = $db->from('mappings', $filter);
    $mappings = $mres['data'] ?? [];
    if (!$mappings) {
        echo json_encode(['ok' => true, 'mappings' => []]);
        exit;
    }

    $out = [];
    foreach ($mappings as $m) {
        $mid = (string)$m['id'];
        $boardId = (string)($m['trello_board_id'] ?? '');
        $listId = (string)($m['trello_list_id'] ?? '');
        $cuListId = (string)($m['clickup_list_id'] ?? '');

        if ($live) {
            // Live mode: hit Trello + ClickUp APIs and refresh shadow table along the way.
            $tCardsRes = $listId ? $tr->cards($listId) : $tr->boardCards($boardId);
            $tCards = [];
            foreach (($tCardsRes['data'] ?? []) as $c) {
                if (!empty($c['closed'])) continue;
                $tCards[(string)$c['id']] = $c;
            }
            $cuTasksRes = $cu->tasks($cuListId);
            $cuTasks = [];
            foreach (($cuTasksRes['data']['tasks'] ?? []) as $t) {
                if (!empty($t['parent'])) continue; // skip subtasks
                $cuTasks[(string)$t['id']] = $t;
            }

            // Refresh shadow rows from live data
            require_once __DIR__ . '/../lib/shadow_tasks.php';
            $smRes = $db->from('sync_map', 'select=*&mapping_id=eq.' . urlencode($mid) . '&deleted_at=is.null');
            $smByT = []; $smByC = [];
            foreach (($smRes['data'] ?? []) as $sm) {
                if (!empty($sm['trello_card_id']))  $smByT[(string)$sm['trello_card_id']] = $sm;
                if (!empty($sm['clickup_task_id'])) $smByC[(string)$sm['clickup_task_id']] = $sm;
            }
            $touched = [];
            foreach ($tCards as $id => $card) {
                $sm = $smByT[$id] ?? null;
                $cuT = ($sm && !empty($sm['clickup_task_id'])) ? ($cuTasks[(string)$sm['clickup_task_id']] ?? null) : null;
                upsertShadowTask($db, buildShadowRow($mid, $sm['id'] ?? null, $card, $cuT));
                $touched[] = $id;
            }
            foreach ($cuTasks as $id => $task) {
                $sm = $smByC[$id] ?? null;
                if ($sm && !empty($sm['trello_card_id']) && in_array((string)$sm['trello_card_id'], $touched)) continue;
                $tCard = ($sm && !empty($sm['trello_card_id'])) ? ($tCards[(string)$sm['trello_card_id']] ?? null) : null;
                upsertShadowTask($db, buildShadowRow($mid, $sm['id'] ?? null, $tCard, $task));
            }
        }

        // Read from shadow table (fast)
        $tasksRes = $db->from('tasks', 'select=*&mapping_id=eq.' . urlencode($mid) . '&deleted_at=is.null&order=last_seen_at.desc');
        $rows = $tasksRes['data'] ?? [];

        $synced = [];
        $trelloOnly = [];
        $clickupOnly = [];
        foreach ($rows as $r) {
            if (!empty($r['is_subtask'])) continue; // subtasks render inside parents (future)
            $tId = (string)($r['trello_card_id'] ?? '');
            $cId = (string)($r['clickup_task_id'] ?? '');
            $tData = $r['trello_data'] ?? null;
            $cData = $r['clickup_data'] ?? null;
            $tView = $tId ? [
                'id' => $tId,
                'name' => (string)($r['name'] ?? ''),
                'url' => (string)($tData['shortUrl'] ?? $tData['url'] ?? ''),
                'due' => (string)($tData['due'] ?? ''),
                'last_activity' => (string)($tData['dateLastActivity'] ?? ''),
            ] : null;
            $cView = $cId ? [
                'id' => $cId,
                'name' => (string)($r['name'] ?? ''),
                'url' => (string)($cData['url'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'due_date' => $cData['due_date'] ?? null,
                'date_updated' => $cData['date_updated'] ?? null,
            ] : null;

            if ($tId && $cId) {
                $synced[] = [
                    'trello' => $tView,
                    'clickup' => $cView,
                    'last_synced_at' => $r['last_seen_at'] ?? null,
                    'last_direction' => null,
                    'labels' => $r['labels'] ?? [],
                ];
            } elseif ($tId) {
                $trelloOnly[] = $tView;
            } elseif ($cId) {
                $clickupOnly[] = $cView;
            }
        }

        $out[] = [
            'mapping' => [
                'id' => $mid,
                'label' => (string)($m['label'] ?? ''),
                'is_active' => !empty($m['is_active']),
            ],
            'counts' => [
                'synced' => count($synced),
                'trello_only' => count($trelloOnly),
                'clickup_only' => count($clickupOnly),
                'trello_total' => count($synced) + count($trelloOnly),
                'clickup_total' => count($synced) + count($clickupOnly),
            ],
            'synced' => $synced,
            'trello_only' => $trelloOnly,
            'clickup_only' => $clickupOnly,
            'live' => $live,
        ];
    }

    echo json_encode(['ok' => true, 'mappings' => $out]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
