<?php
/**
 * Meeting analysis via OpenRouter → Claude.
 *
 * Takes a transcript and returns a structured JSON analysis:
 *   {
 *     "summary": ["...", "..."],
 *     "decisions": [{ "text": "..." }],
 *     "action_items": [{ "title": "...", "owner": "...", "due": "...", "context": "..." }],
 *     "questions": [{ "question": "...", "context": "..." }]
 *   }
 */
class MeetingAnalyzer {
    private string $key;
    private string $model;
    private string $base = 'https://openrouter.ai/api/v1';

    public function __construct(?string $key = null, string $model = 'anthropic/claude-sonnet-4.5') {
        $this->key = $key ?? ($_ENV['OPENROUTER_API_KEY'] ?? '');
        $this->model = $model;
    }

    public function configured(): bool { return $this->key !== ''; }

    /**
     * @return array ['ok' => bool, 'analysis' => array|null, 'error' => string|null]
     */
    public function analyze(string $transcript, string $title = ''): array {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'OPENROUTER_API_KEY not set', 'analysis' => null];
        }
        if (trim($transcript) === '') {
            return ['ok' => false, 'error' => 'empty transcript', 'analysis' => null];
        }

        $system = <<<PROMPT
You analyze meeting transcripts and return structured JSON.

Output ONLY a JSON object (no prose) with exactly these keys:
  - "summary": array of 3 to 5 short bullet strings, each a self-contained sentence
  - "decisions": array of { "text": string }  (concrete decisions made)
  - "action_items": array of { "title": string, "owner": string|null, "due": string|null, "context": string|null }
  - "questions": array of { "question": string, "context": string|null }  (open questions raised but not answered)

If a section has no entries, return an empty array. Do not invent owners or due dates not present in the transcript.
Avoid filler words ("leverage", "streamline", "empower"). Use plain language. Do not use em-dashes.
PROMPT;

        $user = $title
            ? "Meeting title: {$title}\n\nTranscript:\n\n{$transcript}"
            : "Transcript:\n\n{$transcript}";

        $body = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
            'max_tokens' => 2000,
        ];

        $ch = curl_init($this->base . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->key,
                'Content-Type: application/json',
                'HTTP-Referer: https://bqbstudio.com/jupiter/ginger-sync',
                'X-Title: Ginger Sync',
            ],
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) return ['ok' => false, 'error' => "curl: $err", 'analysis' => null];
        $data = json_decode((string)$resp, true);
        if ($status >= 400) {
            return ['ok' => false, 'error' => "OpenRouter $status: " . ($data['error']['message'] ?? json_encode($data)), 'analysis' => null];
        }

        $content = (string)($data['choices'][0]['message']['content'] ?? '');
        if ($content === '') return ['ok' => false, 'error' => 'empty completion', 'analysis' => null];

        $analysis = json_decode($content, true);
        if (!is_array($analysis)) {
            return ['ok' => false, 'error' => 'analysis was not valid JSON: ' . substr($content, 0, 200), 'analysis' => null];
        }

        // Normalize keys + defaults
        $normalized = [
            'summary' => array_values(array_filter(array_map('strval', $analysis['summary'] ?? []))),
            'decisions' => array_values($analysis['decisions'] ?? []),
            'action_items' => array_values($analysis['action_items'] ?? []),
            'questions' => array_values($analysis['questions'] ?? []),
        ];
        return ['ok' => true, 'analysis' => $normalized, 'error' => null];
    }
}
