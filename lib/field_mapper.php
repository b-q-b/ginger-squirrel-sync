<?php
/**
 * Bidirectional field translation between ClickUp tasks and Trello cards.
 * Plain functions, no state.
 *
 * Trello card payload (relevant fields):
 *   id, name, desc, due (ISO 8601), idList, idLabels, labels[],
 *   dateLastActivity (ISO), shortUrl, closed
 *
 * ClickUp task payload:
 *   id, name, description, status: { status, color }, due_date (ms epoch as string),
 *   tags[] { name, tag_fg, tag_bg }, list { id }, date_updated (ms epoch),
 *   url
 */

/**
 * Trello label color name → hex (Trello uses 10 named colors).
 * Used when projecting Trello labels into ClickUp tags (which want fg/bg hex).
 */
function trelloColorToHex(string $name): string {
    return [
        'green'  => '#61bd4f',
        'yellow' => '#f2d600',
        'orange' => '#ff9f1a',
        'red'    => '#eb5a46',
        'purple' => '#c377e0',
        'blue'   => '#0079bf',
        'sky'    => '#00c2e0',
        'lime'   => '#51e898',
        'pink'   => '#ff78cb',
        'black'  => '#344563',
    ][strtolower($name)] ?? '#b3bac5';
}

/**
 * Inverse — pick the closest Trello named color for a hex string.
 * Used when projecting ClickUp tag colors back to Trello label colors.
 */
function hexToTrelloColor(?string $hex): string {
    if (!$hex) return 'black';
    $hex = strtolower(ltrim($hex, '#'));
    if (strlen($hex) !== 6) return 'black';
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $palette = [
        'green'  => [0x61, 0xbd, 0x4f],
        'yellow' => [0xf2, 0xd6, 0x00],
        'orange' => [0xff, 0x9f, 0x1a],
        'red'    => [0xeb, 0x5a, 0x46],
        'purple' => [0xc3, 0x77, 0xe0],
        'blue'   => [0x00, 0x79, 0xbf],
        'sky'    => [0x00, 0xc2, 0xe0],
        'lime'   => [0x51, 0xe8, 0x98],
        'pink'   => [0xff, 0x78, 0xcb],
        'black'  => [0x34, 0x45, 0x63],
    ];
    $best = 'black';
    $bestDist = PHP_INT_MAX;
    foreach ($palette as $name => $rgb) {
        $d = ($rgb[0] - $r) ** 2 + ($rgb[1] - $g) ** 2 + ($rgb[2] - $b) ** 2;
        if ($d < $bestDist) { $bestDist = $d; $best = $name; }
    }
    return $best;
}

/**
 * Convert a Trello card to a ClickUp create/update payload.
 * @param array  $card        Trello card (full)
 * @param array  $statusMap   { trelloListName => clickupStatus }
 * @param array  $cuList      ClickUp list metadata (used to get list of Trello list names → list IDs)
 * @param array  $trelloListNamesById  { trelloListId => name }
 * @return array ClickUp task payload (subset of fields ClickUp accepts on POST/PUT)
 */
function trelloCardToClickUp(array $card, array $statusMap, array $trelloListNamesById = []): array {
    $payload = [
        'name' => (string)($card['name'] ?? ''),
        'description' => (string)($card['desc'] ?? ''),
    ];

    // Due date: Trello ISO 8601 → ClickUp Unix ms
    if (!empty($card['due'])) {
        $ts = strtotime((string)$card['due']);
        if ($ts) {
            $payload['due_date'] = $ts * 1000;
            $payload['due_date_time'] = true;
        }
    }

    // Status: lookup current Trello list name in status_map
    $listId = (string)($card['idList'] ?? '');
    $listName = $trelloListNamesById[$listId] ?? '';
    if ($listName !== '' && isset($statusMap[$listName])) {
        $payload['status'] = (string)$statusMap[$listName];
    }

    // Labels: Trello label → ClickUp tag (preserve color)
    $tags = [];
    foreach (($card['labels'] ?? []) as $lbl) {
        $name = trim((string)($lbl['name'] ?? ''));
        if ($name === '') continue;
        $hex = trelloColorToHex((string)($lbl['color'] ?? ''));
        $tags[] = ['name' => $name, 'tag_fg' => '#ffffff', 'tag_bg' => $hex];
    }
    if ($tags) $payload['tags'] = $tags;

    return $payload;
}

/**
 * Convert a ClickUp task to a Trello create/update payload.
 * @param array  $task           ClickUp task
 * @param array  $statusMap      { trelloListName => clickupStatus } (we reverse it)
 * @param array  $trelloListsByName { listName => listId } (for status → list mapping)
 * @param string $defaultTrelloListId  fallback list (e.g. first list of board)
 * @return array Trello card payload (subset)
 */
function clickUpTaskToTrello(array $task, array $statusMap, array $trelloListsByName, string $defaultTrelloListId = ''): array {
    $payload = [
        'name' => (string)($task['name'] ?? ''),
        'desc' => (string)($task['description'] ?? $task['text_content'] ?? ''),
    ];

    // Due date: ClickUp ms-epoch → Trello ISO 8601
    $due = $task['due_date'] ?? null;
    if ($due) {
        $sec = (int)floor(((int)$due) / 1000);
        if ($sec > 0) {
            $payload['due'] = gmdate('c', $sec);
        }
    }

    // Status → Trello list (reverse the status_map)
    $cuStatus = (string)($task['status']['status'] ?? '');
    if ($cuStatus !== '') {
        $reverseMap = [];
        foreach ($statusMap as $trelloName => $cuName) {
            $reverseMap[strtolower((string)$cuName)] = (string)$trelloName;
        }
        $trelloListName = $reverseMap[strtolower($cuStatus)] ?? null;
        if ($trelloListName && isset($trelloListsByName[$trelloListName])) {
            $payload['idList'] = (string)$trelloListsByName[$trelloListName];
        } elseif ($defaultTrelloListId) {
            $payload['idList'] = $defaultTrelloListId;
        }
    } elseif ($defaultTrelloListId) {
        $payload['idList'] = $defaultTrelloListId;
    }

    // Labels: collect tag specs so the engine can find-or-create Trello labels.
    // Output stored under a private key prefixed `_` so it's not sent to Trello directly.
    $labelSpecs = [];
    foreach (($task['tags'] ?? []) as $tag) {
        $name = trim((string)($tag['name'] ?? ''));
        if ($name === '') continue;
        $bg = (string)($tag['tag_bg'] ?? '');
        $labelSpecs[] = ['name' => $name, 'color' => hexToTrelloColor($bg)];
    }
    if ($labelSpecs) $payload['_label_specs'] = $labelSpecs;

    return $payload;
}

/**
 * Compute a CANONICAL content hash from a Trello card.
 * Direction-agnostic — the same content on either side hashes to the same value
 * so the echo guard can recognize a "this is what we just wrote" condition
 * regardless of which side originated the write.
 */
/**
 * Robust canonical form. Designed so the same logical content on
 * either platform produces the same hash:
 *   - Labels compared by NAME only (color is presentational and may differ
 *     in unrelated ways between the two systems).
 *   - Status is included only if the mapping's status_map covers it; otherwise
 *     "" on both sides so an unmapped status never causes a sync loop.
 *   - Due date as Unix seconds.
 */
function canonicalFromTrello(array $card, array $statusMap, array $listNamesById): array {
    $listId = (string)($card['idList'] ?? '');
    $listName = $listNamesById[$listId] ?? '';
    $statusCanon = isset($statusMap[$listName]) ? strtolower((string)$statusMap[$listName]) : '';

    $labels = [];
    foreach (($card['labels'] ?? []) as $l) {
        $name = trim((string)($l['name'] ?? ''));
        if ($name !== '') $labels[] = strtolower($name);
    }
    sort($labels);
    $labels = array_values(array_unique($labels));

    $due = !empty($card['due']) ? strtotime((string)$card['due']) : null;
    return [
        'name' => trim((string)($card['name'] ?? '')),
        'desc' => trim((string)($card['desc'] ?? '')),
        'due'  => $due ?: 0,
        'status' => $statusCanon,
        'labels' => $labels,
    ];
}

function canonicalFromClickUp(array $task, array $statusMap = []): array {
    $cuStatus = strtolower((string)($task['status']['status'] ?? ''));
    $managedStatuses = array_map('strtolower', array_map('strval', array_values($statusMap)));
    $statusCanon = in_array($cuStatus, $managedStatuses, true) ? $cuStatus : '';

    $labels = [];
    foreach (($task['tags'] ?? []) as $t) {
        $name = trim((string)($t['name'] ?? ''));
        if ($name !== '') $labels[] = strtolower($name);
    }
    sort($labels);
    $labels = array_values(array_unique($labels));

    $due = !empty($task['due_date']) ? (int)floor(((int)$task['due_date']) / 1000) : null;
    return [
        'name' => trim((string)($task['name'] ?? '')),
        'desc' => trim((string)($task['description'] ?? $task['text_content'] ?? '')),
        'due'  => $due ?: 0,
        'status' => $statusCanon,
        'labels' => $labels,
    ];
}

function canonicalHash(array $canonical): string {
    return sha1(json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** @deprecated kept for backward compat; prefer canonicalHash */
function syncFieldsHash(array $payload): string {
    $stable = [];
    foreach (['name', 'description', 'desc', 'status', 'due_date', 'due', 'tags', 'idList'] as $k) {
        if (array_key_exists($k, $payload)) $stable[$k] = $payload[$k];
    }
    ksort($stable);
    return sha1(json_encode($stable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
