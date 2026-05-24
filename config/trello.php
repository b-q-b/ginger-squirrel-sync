<?php
/**
 * Trello REST client.
 * Auth: API key + user token, sent as query params on every request.
 * Docs: https://developer.atlassian.com/cloud/trello/rest/
 */
class Trello {
    private string $key;
    private string $token;
    private string $base = 'https://api.trello.com/1';

    public function __construct(?string $key = null, ?string $token = null) {
        $this->key = $key ?? ($_ENV['TRELLO_KEY'] ?? '');
        $this->token = $token ?? ($_ENV['TRELLO_TOKEN'] ?? '');
    }

    public function configured(): bool {
        return $this->key !== '' && $this->token !== '';
    }

    public function request(string $method, string $path, ?array $body = null, array $query = []): array {
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Trello key/token not configured'];
        }
        $query = array_merge(['key' => $this->key, 'token' => $this->token], $query);
        $url = $this->base . $path . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? []));
        } elseif (in_array($method, ['PUT', 'DELETE'])) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $err];

        $data = json_decode((string)$resp, true);
        // Trello sometimes returns plain text for errors
        if ($status >= 400) {
            $msg = is_string($data) ? $data : ($data['message'] ?? "HTTP $status");
            if (!is_string($msg)) $msg = (string)$resp ?: "HTTP $status";
            return ['ok' => false, 'status' => $status, 'data' => $data ?: $resp, 'error' => $msg];
        }
        return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => null];
    }

    public function me(): array { return $this->request('GET', '/members/me'); }
    public function boards(): array { return $this->request('GET', '/members/me/boards', null, ['fields' => 'name,closed,url,shortUrl']); }
    public function lists(string $boardId): array { return $this->request('GET', "/boards/{$boardId}/lists", null, ['fields' => 'name,closed,pos']); }
    public function cards(string $listId, array $opts = []): array {
        return $this->request('GET', "/lists/{$listId}/cards", null, array_merge(['fields' => 'name,desc,due,dueComplete,idList,idLabels,labels,dateLastActivity,closed,shortUrl'], $opts));
    }
    public function boardCards(string $boardId, array $opts = []): array {
        return $this->request('GET', "/boards/{$boardId}/cards", null, array_merge(['fields' => 'name,desc,due,dueComplete,idList,idLabels,labels,dateLastActivity,closed,shortUrl'], $opts));
    }
    public function card(string $cardId): array { return $this->request('GET', "/cards/{$cardId}", null, ['customFieldItems' => 'true']); }
    public function createCard(array $body): array { return $this->request('POST', '/cards', null, $body); }
    public function updateCard(string $cardId, array $body): array { return $this->request('PUT', "/cards/{$cardId}", null, $body); }
    public function deleteCard(string $cardId): array { return $this->request('DELETE', "/cards/{$cardId}"); }
    public function boardLabels(string $boardId): array { return $this->request('GET', "/boards/{$boardId}/labels", null, ['fields' => 'name,color']); }
    public function cardChecklists(string $cardId): array { return $this->request('GET', "/cards/{$cardId}/checklists"); }
    public function createChecklist(string $cardId, string $name): array { return $this->request('POST', "/cards/{$cardId}/checklists", null, ['name' => $name]); }
    public function deleteChecklist(string $checklistId): array { return $this->request('DELETE', "/checklists/{$checklistId}"); }
    public function checklistItems(string $checklistId): array { return $this->request('GET', "/checklists/{$checklistId}/checkItems"); }
    public function createCheckItem(string $checklistId, string $name, bool $checked = false): array {
        return $this->request('POST', "/checklists/{$checklistId}/checkItems", null, [
            'name' => $name,
            'checked' => $checked ? 'true' : 'false',
        ]);
    }
    public function updateCheckItem(string $cardId, string $itemId, string $name, ?bool $checked = null): array {
        $query = ['name' => $name];
        if ($checked !== null) $query['state'] = $checked ? 'complete' : 'incomplete';
        return $this->request('PUT', "/cards/{$cardId}/checkItem/{$itemId}", null, $query);
    }
    public function deleteCheckItem(string $checklistId, string $itemId): array {
        return $this->request('DELETE', "/checklists/{$checklistId}/checkItems/{$itemId}");
    }
}
