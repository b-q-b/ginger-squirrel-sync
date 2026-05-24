<?php
/**
 * Lightweight Supabase REST client. Adapted from Jupiter.
 */
class Supabase {
    private string $url;
    private string $key;
    private array $defaultHeaders;

    public function __construct(?string $useServiceKey = null) {
        $this->url = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
        $this->key = $useServiceKey
            ? ($_ENV['SUPABASE_SERVICE_KEY'] ?? '')
            : ($_ENV['SUPABASE_ANON_KEY'] ?? '');
        $this->defaultHeaders = [
            "apikey: {$this->key}",
            "Authorization: Bearer {$this->key}",
            "Content-Type: application/json",
            "Prefer: return=representation",
            "Accept-Profile: ginger_sync",
            "Content-Profile: ginger_sync",
        ];
    }

    public function from(string $table, string $query = '', string $method = 'GET', ?array $body = null): array {
        $url = "{$this->url}/rest/v1/{$table}";
        if ($query) $url .= "?{$query}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->defaultHeaders,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TCP_KEEPALIVE => 1,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) return ['error' => $error, 'data' => null];
        $data = json_decode($response, true);
        if ($httpCode >= 400) return ['error' => $data['message'] ?? "HTTP {$httpCode}", 'data' => null];
        return ['error' => null, 'data' => $data];
    }

    public function insert(string $table, array $row): array {
        return $this->from($table, '', 'POST', $row);
    }

    public function upsert(string $table, array $rowOrRows, string $onConflict = 'id'): array {
        $url = "{$this->url}/rest/v1/{$table}?on_conflict=" . rawurlencode($onConflict);
        $headers = $this->defaultHeaders;
        $headers[] = 'Prefer: resolution=merge-duplicates,return=representation';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($rowOrRows),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) return ['error' => $error, 'data' => null];
        $data = json_decode($response, true);
        if ($httpCode >= 400) return ['error' => $data['message'] ?? "HTTP {$httpCode}", 'data' => null];
        return ['error' => null, 'data' => $data];
    }

    public function update(string $table, string $filter, array $data): array {
        return $this->from($table, $filter, 'PATCH', $data);
    }

    public function delete(string $table, string $filter): array {
        return $this->from($table, $filter, 'DELETE');
    }
}
