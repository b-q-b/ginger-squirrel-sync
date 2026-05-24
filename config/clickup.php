<?php
/**
 * ClickUp REST client — v2 API.
 * Auth: personal token (`pk_<userid>_<random>`).
 * Docs: https://clickup.com/api
 */
class ClickUp {
    private string $token;
    private string $base = 'https://api.clickup.com/api/v2';

    public function __construct(?string $token = null) {
        $this->token = $token ?? ($_ENV['CLICKUP_TOKEN'] ?? '');
    }

    public function configured(): bool {
        return $this->token !== '';
    }

    /**
     * @param string $method GET|POST|PUT|DELETE
     * @param string $path   e.g. "/user", "/list/{id}/task"
     * @param array|null $body  JSON body for POST/PUT
     * @param array $query  optional query params
     * @return array ['ok' => bool, 'status' => int, 'data' => mixed, 'error' => string|null]
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): array {
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'ClickUp token not configured'];
        }
        $url = $this->base . $path;
        if ($query) $url .= '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif (in_array($method, ['PUT', 'PATCH', 'DELETE'])) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $err];

        $data = json_decode((string)$resp, true);
        if ($status >= 400) {
            $msg = $data['err'] ?? $data['error'] ?? "HTTP $status";
            return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => $msg];
        }
        return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => null];
    }

    public function me(): array        { return $this->request('GET', '/user'); }
    public function teams(): array     { return $this->request('GET', '/team'); }
    public function spaces(string $teamId): array { return $this->request('GET', "/team/{$teamId}/space", null, ['archived' => 'false']); }
    public function folders(string $spaceId): array { return $this->request('GET', "/space/{$spaceId}/folder", null, ['archived' => 'false']); }
    public function lists(string $folderId): array { return $this->request('GET', "/folder/{$folderId}/list", null, ['archived' => 'false']); }
    public function folderlessLists(string $spaceId): array { return $this->request('GET', "/space/{$spaceId}/list", null, ['archived' => 'false']); }
    public function listStatuses(string $listId): array { return $this->request('GET', "/list/{$listId}"); }

    public function tasks(string $listId, array $opts = []): array {
        // Default: top-level tasks only. Subtasks fetched separately via subtasksOf().
        return $this->request('GET', "/list/{$listId}/task", null, array_merge(['archived' => 'false', 'subtasks' => 'false'], $opts));
    }
    public function subtasksOf(string $listId, string $parentTaskId): array {
        $r = $this->request('GET', "/list/{$listId}/task", null, ['archived' => 'false', 'subtasks' => 'true']);
        if (!$r['ok']) return $r;
        $subs = [];
        foreach (($r['data']['tasks'] ?? []) as $t) {
            if (($t['parent'] ?? null) === $parentTaskId) $subs[] = $t;
        }
        return ['ok' => true, 'status' => 200, 'data' => ['tasks' => $subs], 'error' => null];
    }
    public function createSubtask(string $listId, string $parentTaskId, array $body): array {
        // ClickUp v2: POST /list/{list_id}/task with `parent` in the body creates a subtask
        $body['parent'] = $parentTaskId;
        return $this->request('POST', "/list/{$listId}/task", $body);
    }
    public function task(string $taskId): array { return $this->request('GET', "/task/{$taskId}"); }
    public function createTask(string $listId, array $body): array { return $this->request('POST', "/list/{$listId}/task", $body); }
    public function updateTask(string $taskId, array $body): array { return $this->request('PUT', "/task/{$taskId}", $body); }
    public function deleteTask(string $taskId): array { return $this->request('DELETE', "/task/{$taskId}"); }
}
