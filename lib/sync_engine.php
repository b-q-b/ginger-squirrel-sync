<?php
require_once __DIR__ . '/../config/clickup.php';
require_once __DIR__ . '/../config/trello.php';
require_once __DIR__ . '/field_mapper.php';
require_once __DIR__ . '/echo_guard.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/shadow_tasks.php';

/**
 * Look up an existing sync_map row by either platform's task ID.
 */
function findSyncMap(Supabase $db, ?string $trelloId, ?string $clickupId): ?array {
    if ($trelloId) {
        $r = $db->from('sync_map', 'select=*&trello_card_id=eq.' . urlencode($trelloId) . '&limit=1');
        if (!empty($r['data'][0])) return $r['data'][0];
    }
    if ($clickupId) {
        $r = $db->from('sync_map', 'select=*&clickup_task_id=eq.' . urlencode($clickupId) . '&limit=1');
        if (!empty($r['data'][0])) return $r['data'][0];
    }
    return null;
}

/**
 * Build a { trelloListName => trelloListId } map for a board, used to translate
 * ClickUp status → Trello list move.
 */
function fetchTrelloListsByName(Trello $tr, string $boardId): array {
    $r = $tr->lists($boardId);
    if (!$r['ok']) return [];
    $out = [];
    foreach (($r['data'] ?? []) as $l) {
        $out[(string)($l['name'] ?? '')] = (string)($l['id'] ?? '');
    }
    return $out;
}

function fetchTrelloListNamesById(Trello $tr, string $boardId): array {
    $r = $tr->lists($boardId);
    if (!$r['ok']) return [];
    $out = [];
    foreach (($r['data'] ?? []) as $l) {
        $out[(string)($l['id'] ?? '')] = (string)($l['name'] ?? '');
    }
    return $out;
}

/**
 * Resolve a list of {name, color} label specs to Trello label IDs.
 * Find-or-create against the given board. Returns array of label IDs.
 */
function resolveTrelloLabels(Trello $tr, string $boardId, array $specs): array {
    if (!$specs) return [];
    $existing = $tr->boardLabels($boardId);
    $byName = [];
    foreach (($existing['data'] ?? []) as $l) {
        $byName[strtolower((string)$l['name'])] = $l;
    }
    $ids = [];
    foreach ($specs as $spec) {
        $name = (string)$spec['name'];
        $key = strtolower($name);
        if (isset($byName[$key])) {
            $ids[] = (string)$byName[$key]['id'];
            continue;
        }
        // Create label
        $r = $tr->request('POST', '/labels', null, [
            'name' => $name,
            'color' => (string)($spec['color'] ?? 'black'),
            'idBoard' => $boardId,
        ]);
        if ($r['ok'] && !empty($r['data']['id'])) {
            $ids[] = (string)$r['data']['id'];
            $byName[$key] = $r['data'];
        }
    }
    return $ids;
}

/**
 * Sync ONE Trello card → ClickUp.
 *
 * @return array  ['action' => 'create|update|skip_echo|error', 'clickup_task_id' => str, 'error' => str|null]
 */
function syncTrelloCardToClickUp(Supabase $db, array $mapping, array $trelloCard, string $source = 'manual'): array {
    $cu = new ClickUp();
    $tr = new Trello();
    $statusMap = is_array($mapping['status_map'] ?? null) ? $mapping['status_map'] : [];
    $boardId = (string)($mapping['trello_board_id'] ?? '');
    $clickupListId = (string)($mapping['clickup_list_id'] ?? '');
    $mappingId = (string)($mapping['id'] ?? '');

    $trelloId = (string)($trelloCard['id'] ?? '');
    if ($trelloId === '') {
        return ['action' => 'error', 'error' => 'Trello card missing id'];
    }

    $listNamesById = fetchTrelloListNamesById($tr, $boardId);
    $payload = trelloCardToClickUp($trelloCard, $statusMap, $listNamesById);
    $hash = canonicalHash(canonicalFromTrello($trelloCard, $statusMap, $listNamesById));

    $map = findSyncMap($db, $trelloId, null);

    // Echo guard
    $guard = echoGuardCheck($map, $hash);
    if ($guard['skip']) {
        logSyncEvent($db, [
            'source' => $source, 'direction' => 'trello_to_clickup',
            'action' => 'skip_hash', 'trello_card_id' => $trelloId,
            'clickup_task_id' => $map['clickup_task_id'] ?? null,
            'mapping_id' => $mappingId, 'status' => 'skipped',
            'payload_hash' => $hash, 'error' => $guard['reason'],
        ]);
        return ['action' => 'skip_echo', 'clickup_task_id' => $map['clickup_task_id'] ?? null, 'error' => null];
    }

    // Create or update ClickUp task
    $clickupId = $map['clickup_task_id'] ?? null;
    if ($clickupId) {
        $r = $cu->updateTask($clickupId, $payload);
        $action = 'update';
    } else {
        $r = $cu->createTask($clickupListId, $payload);
        $action = 'create';
        if ($r['ok']) {
            $clickupId = (string)($r['data']['id'] ?? '');
        }
    }

    if (!$r['ok']) {
        logSyncEvent($db, [
            'source' => $source, 'direction' => 'trello_to_clickup',
            'action' => $action, 'trello_card_id' => $trelloId, 'clickup_task_id' => $clickupId,
            'mapping_id' => $mappingId, 'status' => 'error',
            'payload_hash' => $hash, 'error' => $r['error'],
        ]);
        return ['action' => 'error', 'clickup_task_id' => $clickupId, 'error' => $r['error']];
    }

    // Upsert sync_map
    $now = gmdate('c');
    $db->upsert('sync_map', [
        'mapping_id' => $mappingId,
        'trello_card_id' => $trelloId,
        'clickup_task_id' => $clickupId,
        'last_hash' => $hash,
        'last_direction' => 'trello_to_clickup',
        'last_synced_at' => $now,
    ], 'trello_card_id');

    // Trello checklists → ClickUp subtasks
    if ($clickupId) {
        try {
            syncChecklistItemsAsSubtasks($cu, $tr, $clickupListId, $clickupId, $trelloId);
        } catch (Throwable $e) {
            error_log('checklist→subtask sync failed: ' . $e->getMessage());
        }
    }

    // Update shadow tasks cache
    $cuTask = $clickupId ? ($cu->task($clickupId)['data'] ?? null) : null;
    upsertShadowTask($db, buildShadowRow($mappingId, null, $trelloCard, $cuTask));

    logSyncEvent($db, [
        'source' => $source, 'direction' => 'trello_to_clickup',
        'action' => $action, 'trello_card_id' => $trelloId, 'clickup_task_id' => $clickupId,
        'mapping_id' => $mappingId, 'status' => 'ok',
        'payload_hash' => $hash, 'error' => null,
    ]);
    return ['action' => $action, 'clickup_task_id' => $clickupId, 'error' => null];
}

/**
 * After we have a ClickUp parent task matching a Trello card, sync that card's
 * Trello checklist items as ClickUp subtasks.
 *
 * Match by NAME (case-insensitive). Reuses existing subtasks; creates missing ones.
 * Subtasks that no longer have a matching Trello checklist item are NOT deleted
 * (safer default — user may have intentionally added them in ClickUp).
 *
 * Returns: ['added' => int, 'matched' => int]
 */
function syncChecklistItemsAsSubtasks(ClickUp $cu, Trello $tr, string $clickupListId, string $clickupParentId, string $trelloCardId): array {
    $stats = ['added' => 0, 'matched' => 0];

    // Pull all checklist items from the Trello card
    $clRes = $tr->cardChecklists($trelloCardId);
    if (!$clRes['ok']) return $stats;

    $itemNames = [];
    $itemStates = [];
    foreach (($clRes['data'] ?? []) as $cl) {
        foreach (($cl['checkItems'] ?? []) as $it) {
            $name = trim((string)($it['name'] ?? ''));
            if ($name === '') continue;
            $itemNames[] = $name;
            $itemStates[strtolower($name)] = (string)($it['state'] ?? 'incomplete') === 'complete';
        }
    }
    if (!$itemNames) return $stats;

    // Fetch existing subtasks of the parent
    $subsRes = $cu->subtasksOf($clickupListId, $clickupParentId);
    $existingByName = [];
    foreach (($subsRes['data']['tasks'] ?? []) as $s) {
        $existingByName[strtolower((string)($s['name'] ?? ''))] = $s;
    }

    foreach ($itemNames as $name) {
        $key = strtolower($name);
        if (isset($existingByName[$key])) {
            $stats['matched']++;
            continue;
        }
        $body = ['name' => $name];
        // ClickUp doesn't accept arbitrary "complete" status without knowing the list's done-status.
        // Skip status-setting; user can set it later in ClickUp.
        $r = $cu->createSubtask($clickupListId, $clickupParentId, $body);
        if ($r['ok']) $stats['added']++;
    }
    return $stats;
}

/**
 * After we have a Trello card matching a ClickUp parent task, sync that task's
 * subtasks as checklist items on the card under a "Subtasks" checklist.
 *
 * Idempotent: matches existing checklist items by NAME (case-insensitive),
 * adds missing ones, deletes ones that no longer exist in ClickUp.
 *
 * Returns: ['added' => int, 'updated' => int, 'removed' => int]
 */
function syncSubtasksAsChecklist(ClickUp $cu, Trello $tr, string $clickupListId, string $clickupTaskId, string $trelloCardId): array {
    $stats = ['added' => 0, 'updated' => 0, 'removed' => 0];

    // Find subtasks of this ClickUp task
    $subsRes = $cu->subtasksOf($clickupListId, $clickupTaskId);
    $subs = $subsRes['ok'] ? ($subsRes['data']['tasks'] ?? []) : [];

    // Get existing Trello checklists; find or create one called "Subtasks"
    $clRes = $tr->cardChecklists($trelloCardId);
    $checklists = $clRes['ok'] ? ($clRes['data'] ?? []) : [];
    $clId = null;
    foreach ($checklists as $c) {
        if (strcasecmp((string)($c['name'] ?? ''), 'Subtasks') === 0) {
            $clId = (string)$c['id'];
            break;
        }
    }
    if (!$subs && !$clId) return $stats; // nothing to do

    if (!$clId) {
        $created = $tr->createChecklist($trelloCardId, 'Subtasks');
        if (!$created['ok']) return $stats;
        $clId = (string)($created['data']['id'] ?? '');
        if (!$clId) return $stats;
    }

    // Fetch current items in the checklist
    $itemsRes = $tr->checklistItems($clId);
    $items = $itemsRes['ok'] ? ($itemsRes['data'] ?? []) : [];
    $itemsByName = [];
    foreach ($items as $it) {
        $itemsByName[strtolower((string)($it['name'] ?? ''))] = $it;
    }

    $subNamesSeen = [];
    foreach ($subs as $sub) {
        $name = (string)($sub['name'] ?? '');
        if ($name === '') continue;
        $key = strtolower($name);
        $subNamesSeen[$key] = true;
        $isComplete = in_array(strtolower((string)($sub['status']['status'] ?? '')), ['complete', 'closed', 'done']);

        if (isset($itemsByName[$key])) {
            $existing = $itemsByName[$key];
            $needsUpdate = $isComplete !== (($existing['state'] ?? 'incomplete') === 'complete');
            if ($needsUpdate) {
                $tr->updateCheckItem($trelloCardId, (string)$existing['id'], $name, $isComplete);
                $stats['updated']++;
            }
        } else {
            $r = $tr->createCheckItem($clId, $name, $isComplete);
            if ($r['ok']) $stats['added']++;
        }
    }

    // Remove items that no longer correspond to a subtask
    foreach ($itemsByName as $key => $it) {
        if (!isset($subNamesSeen[$key])) {
            $tr->deleteCheckItem($clId, (string)$it['id']);
            $stats['removed']++;
        }
    }

    return $stats;
}

/**
 * Sync ONE ClickUp task → Trello.
 */
function syncClickUpTaskToTrello(Supabase $db, array $mapping, array $cuTask, string $source = 'manual'): array {
    $cu = new ClickUp();
    $tr = new Trello();
    $statusMap = is_array($mapping['status_map'] ?? null) ? $mapping['status_map'] : [];
    $boardId = (string)($mapping['trello_board_id'] ?? '');
    $defaultListId = (string)($mapping['trello_list_id'] ?? '');
    $mappingId = (string)($mapping['id'] ?? '');

    $clickupId = (string)($cuTask['id'] ?? '');
    if ($clickupId === '') {
        return ['action' => 'error', 'error' => 'ClickUp task missing id'];
    }

    $listsByName = fetchTrelloListsByName($tr, $boardId);

    // Choose a fallback list: explicit mapping list, else first board list.
    $fallbackListId = $defaultListId ?: (reset($listsByName) ?: '');
    $payload = clickUpTaskToTrello($cuTask, $statusMap, $listsByName, $fallbackListId);

    // Resolve label specs → idLabels (find or create Trello labels with matching color)
    if (!empty($payload['_label_specs'])) {
        $labelIds = resolveTrelloLabels($tr, $boardId, $payload['_label_specs']);
        if ($labelIds) $payload['idLabels'] = implode(',', $labelIds);
        unset($payload['_label_specs']);
    }

    $hash = canonicalHash(canonicalFromClickUp($cuTask, $statusMap));

    $map = findSyncMap($db, null, $clickupId);
    $guard = echoGuardCheck($map, $hash);
    if ($guard['skip']) {
        logSyncEvent($db, [
            'source' => $source, 'direction' => 'clickup_to_trello',
            'action' => 'skip_hash', 'trello_card_id' => $map['trello_card_id'] ?? null,
            'clickup_task_id' => $clickupId, 'mapping_id' => $mappingId,
            'status' => 'skipped', 'payload_hash' => $hash, 'error' => $guard['reason'],
        ]);
        return ['action' => 'skip_echo', 'trello_card_id' => $map['trello_card_id'] ?? null, 'error' => null];
    }

    $trelloId = $map['trello_card_id'] ?? null;
    if ($trelloId) {
        $r = $tr->updateCard($trelloId, $payload);
        $action = 'update';
    } else {
        $r = $tr->createCard($payload);
        $action = 'create';
        if ($r['ok']) {
            $trelloId = (string)($r['data']['id'] ?? '');
        }
    }

    if (!$r['ok']) {
        logSyncEvent($db, [
            'source' => $source, 'direction' => 'clickup_to_trello',
            'action' => $action, 'trello_card_id' => $trelloId, 'clickup_task_id' => $clickupId,
            'mapping_id' => $mappingId, 'status' => 'error',
            'payload_hash' => $hash, 'error' => $r['error'],
        ]);
        return ['action' => 'error', 'trello_card_id' => $trelloId, 'error' => $r['error']];
    }

    $now = gmdate('c');
    $db->upsert('sync_map', [
        'mapping_id' => $mappingId,
        'trello_card_id' => $trelloId,
        'clickup_task_id' => $clickupId,
        'last_hash' => $hash,
        'last_direction' => 'clickup_to_trello',
        'last_synced_at' => $now,
    ], 'clickup_task_id');

    // After main fields synced, mirror subtasks as Trello checklist items.
    if ($trelloId && $clickupListId = (string)($mapping['clickup_list_id'] ?? '')) {
        try {
            syncSubtasksAsChecklist($cu, $tr, $clickupListId, $clickupId, $trelloId);
        } catch (Throwable $e) {
            error_log('subtask checklist sync failed: ' . $e->getMessage());
        }
    }

    // Update shadow tasks cache
    $trelloCard = $trelloId ? ($tr->card($trelloId)['data'] ?? null) : null;
    upsertShadowTask($db, buildShadowRow($mappingId, null, $trelloCard, $cuTask));

    logSyncEvent($db, [
        'source' => $source, 'direction' => 'clickup_to_trello',
        'action' => $action, 'trello_card_id' => $trelloId, 'clickup_task_id' => $clickupId,
        'mapping_id' => $mappingId, 'status' => 'ok',
        'payload_hash' => $hash, 'error' => null,
    ]);
    return ['action' => $action, 'trello_card_id' => $trelloId, 'error' => null];
}

/**
 * Timestamp-aware reconcile:
 *   - For each known pair, compare Trello.dateLastActivity vs ClickUp.date_updated
 *     vs sync_map.last_synced_at and only sync from the SIDE THAT CHANGED.
 *     If both changed, last-write-wins (newer timestamp wins).
 *   - Trello-only cards (no sync_map): create matching ClickUp tasks.
 *   - ClickUp-only tasks (no sync_map): create matching Trello cards.
 *
 * The 60-second tolerance avoids treating our own writes (which bump the
 * destination side's timestamp) as user edits.
 */
function reconcileMapping(Supabase $db, array $mapping, string $source = 'manual'): array {
    $tr = new Trello();
    $cu = new ClickUp();
    $boardId = (string)($mapping['trello_board_id'] ?? '');
    $trelloListId = (string)($mapping['trello_list_id'] ?? '');
    $clickupListId = (string)($mapping['clickup_list_id'] ?? '');
    $mappingId = (string)($mapping['id'] ?? '');

    $stats = ['trello_to_clickup' => 0, 'clickup_to_trello' => 0, 'errors' => 0, 'skipped' => 0];
    $TOLERANCE_SECONDS = 60;

    $cardsRes = $trelloListId ? $tr->cards($trelloListId) : $tr->boardCards($boardId);
    $tasksRes = $cu->tasks($clickupListId);
    if (!$cardsRes['ok'] || !$tasksRes['ok']) {
        $stats['errors']++;
        return $stats;
    }

    $tCards = [];
    foreach (($cardsRes['data'] ?? []) as $c) {
        if (!empty($c['closed'])) continue;
        $tCards[(string)$c['id']] = $c;
    }
    $cuTasks = [];
    foreach (($tasksRes['data']['tasks'] ?? []) as $t) {
        if (!empty($t['parent'])) continue;  // top-level only
        $cuTasks[(string)$t['id']] = $t;
    }

    // Existing pairs from sync_map
    $smRes = $db->from('sync_map', 'select=*&mapping_id=eq.' . urlencode($mappingId) . '&deleted_at=is.null');
    $sm = $smRes['data'] ?? [];
    $smByT = []; $smByC = [];
    foreach ($sm as $row) {
        if (!empty($row['trello_card_id']))  $smByT[(string)$row['trello_card_id']]  = $row;
        if (!empty($row['clickup_task_id'])) $smByC[(string)$row['clickup_task_id']] = $row;
    }

    // Cache the Trello list names once per mapping (used in canonical hash + per-card sync)
    $listNamesById = fetchTrelloListNamesById($tr, $boardId);
    $statusMap = is_array($mapping['status_map'] ?? null) ? $mapping['status_map'] : [];

    // === Known pairs: content-equality short-circuit, then timestamp-aware ===
    $pairsHandled = []; // tCardId|cuTaskId once each
    foreach ($sm as $row) {
        $tId = (string)($row['trello_card_id'] ?? '');
        $cId = (string)($row['clickup_task_id'] ?? '');
        if ($tId === '' || $cId === '') continue;
        $card = $tCards[$tId] ?? null;
        $task = $cuTasks[$cId] ?? null;
        if (!$card || !$task) continue; // orphaned mapping (resource deleted) — handled by webhooks
        $pairsHandled[$tId] = true;
        $pairsHandled[$cId] = true;

        // Content-equality short-circuit: if both sides agree, do nothing.
        $tHash = canonicalHash(canonicalFromTrello($card, $statusMap, $listNamesById));
        $cHash = canonicalHash(canonicalFromClickUp($task, $statusMap));
        if ($tHash === $cHash) {
            // Refresh last_seen so we know cron observed this pair, even though it skipped.
            $db->update('sync_map', 'id=eq.' . urlencode((string)$row['id']), [
                'last_hash' => $tHash,
            ]);
            $stats['skipped']++;
            continue;
        }

        // Content differs — use timestamps to decide which side is the source of truth.
        $tTs = !empty($card['dateLastActivity']) ? strtotime((string)$card['dateLastActivity']) : 0;
        $cuTs = !empty($task['date_updated']) ? (int)floor(((int)$task['date_updated']) / 1000) : 0;
        $lastSync = !empty($row['last_synced_at']) ? strtotime((string)$row['last_synced_at']) : 0;

        $tChanged  = $tTs  > ($lastSync + $TOLERANCE_SECONDS);
        $cuChanged = $cuTs > ($lastSync + $TOLERANCE_SECONDS);

        $direction = null;
        if ($tChanged && $cuChanged) {
            $direction = ($tTs >= $cuTs) ? 'trello_to_clickup' : 'clickup_to_trello';
        } elseif ($tChanged) {
            $direction = 'trello_to_clickup';
        } elseif ($cuChanged) {
            $direction = 'clickup_to_trello';
        } else {
            // Hash differs but neither timestamp says "changed" — rare drift case.
            // Default to whichever side has the more recent timestamp.
            $direction = ($tTs >= $cuTs) ? 'trello_to_clickup' : 'clickup_to_trello';
        }

        if ($direction === 'trello_to_clickup') {
            $r = syncTrelloCardToClickUp($db, $mapping, $card, $source);
        } else {
            $r = syncClickUpTaskToTrello($db, $mapping, $task, $source);
        }

        if ($r['action'] === 'error') $stats['errors']++;
        elseif (str_starts_with($r['action'], 'skip')) $stats['skipped']++;
        else $stats[$direction]++;
    }

    // === Orphans: Trello-only → create on ClickUp ===
    foreach ($tCards as $tId => $card) {
        if (isset($pairsHandled[$tId])) continue;
        $r = syncTrelloCardToClickUp($db, $mapping, $card, $source);
        if ($r['action'] === 'error') $stats['errors']++;
        elseif (str_starts_with($r['action'], 'skip')) $stats['skipped']++;
        else $stats['trello_to_clickup']++;
    }

    // === Orphans: ClickUp-only → create on Trello ===
    foreach ($cuTasks as $cId => $task) {
        if (isset($pairsHandled[$cId])) continue;
        // Also skip if the task happens to already be in sync_map (defensive)
        if (isset($smByC[$cId])) continue;
        $r = syncClickUpTaskToTrello($db, $mapping, $task, $source);
        if ($r['action'] === 'error') $stats['errors']++;
        elseif (str_starts_with($r['action'], 'skip')) $stats['skipped']++;
        else $stats['clickup_to_trello']++;
    }

    return $stats;
}
