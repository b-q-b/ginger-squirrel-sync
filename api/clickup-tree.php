<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/clickup.php';
requireAuth();
header('Content-Type: application/json');

try {
    $cu = new ClickUp();
    if (!$cu->configured()) {
        echo json_encode(['ok' => false, 'error' => 'ClickUp not configured']);
        exit;
    }

    $teams = $cu->teams();
    if (!$teams['ok']) {
        echo json_encode(['ok' => false, 'error' => $teams['error']]);
        exit;
    }

    // Flatten everything into picker-friendly list of selectable lists.
    $lists = [];
    foreach (($teams['data']['teams'] ?? []) as $team) {
        $teamId = (string)($team['id'] ?? '');
        $teamName = (string)($team['name'] ?? '');

        $spacesRes = $cu->spaces($teamId);
        foreach (($spacesRes['data']['spaces'] ?? []) as $space) {
            $spaceId = (string)($space['id'] ?? '');
            $spaceName = (string)($space['name'] ?? '');

            // Folder-grouped lists
            $foldersRes = $cu->folders($spaceId);
            foreach (($foldersRes['data']['folders'] ?? []) as $folder) {
                $folderName = (string)($folder['name'] ?? '');
                foreach (($folder['lists'] ?? []) as $list) {
                    $lists[] = [
                        'team_id' => $teamId,
                        'space_id' => $spaceId,
                        'space_name' => $spaceName,
                        'folder_name' => $folderName,
                        'list_id' => (string)($list['id'] ?? ''),
                        'list_name' => (string)($list['name'] ?? ''),
                        'label' => "{$spaceName} / {$folderName} / " . (string)($list['name'] ?? ''),
                        'statuses' => array_map(fn($s) => [
                            'status' => (string)($s['status'] ?? ''),
                            'color' => (string)($s['color'] ?? ''),
                            'orderindex' => (int)($s['orderindex'] ?? 0),
                        ], $list['statuses'] ?? []),
                    ];
                }
            }

            // Folderless lists
            $folderlessRes = $cu->folderlessLists($spaceId);
            foreach (($folderlessRes['data']['lists'] ?? []) as $list) {
                $lists[] = [
                    'team_id' => $teamId,
                    'space_id' => $spaceId,
                    'space_name' => $spaceName,
                    'folder_name' => null,
                    'list_id' => (string)($list['id'] ?? ''),
                    'list_name' => (string)($list['name'] ?? ''),
                    'label' => "{$spaceName} / " . (string)($list['name'] ?? ''),
                    'statuses' => array_map(fn($s) => [
                        'status' => (string)($s['status'] ?? ''),
                        'color' => (string)($s['color'] ?? ''),
                        'orderindex' => (int)($s['orderindex'] ?? 0),
                    ], $list['statuses'] ?? []),
                ];
            }
        }
    }

    echo json_encode(['ok' => true, 'lists' => $lists]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
