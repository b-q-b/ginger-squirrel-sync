<?php
/**
 * AssemblyAI transcription client.
 *
 * Flow:
 *   1) Upload audio file → returns upload_url
 *   2) Create transcript job with upload_url → returns transcript_id, status=queued
 *   3) Poll transcript_id → status: queued → processing → completed | error
 *
 * Docs: https://www.assemblyai.com/docs/
 *
 * IMPORTANT: speech_models is a non-empty ARRAY (the new API requirement).
 * Valid values include "universal-3-pro" and "universal-2".
 */
class AssemblyAI {
    private string $key;
    private string $base = 'https://api.assemblyai.com/v2';

    public function __construct(?string $key = null) {
        $this->key = $key ?? ($_ENV['ASSEMBLYAI_API_KEY'] ?? '');
    }

    public function configured(): bool { return $this->key !== ''; }

    /**
     * Upload an audio file. Returns the upload URL or false on error.
     */
    public function upload(string $localPath): array {
        if (!is_file($localPath)) {
            return ['ok' => false, 'error' => "File not found: $localPath"];
        }
        $ch = curl_init($this->base . '/upload');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => file_get_contents($localPath),
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $this->key,
                'Content-Type: application/octet-stream',
            ],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) return ['ok' => false, 'error' => $err];
        $data = json_decode((string)$resp, true);
        if ($status >= 400) return ['ok' => false, 'error' => $data['error'] ?? "HTTP $status"];
        return ['ok' => true, 'upload_url' => (string)($data['upload_url'] ?? '')];
    }

    /**
     * Create a transcript job from an uploaded audio URL.
     * @param string $audioUrl Returned by upload()
     * @param int|null $speakersExpected 1..10 hint (improves diarization)
     */
    public function createTranscript(string $audioUrl, ?int $speakersExpected = null, string $language = 'en'): array {
        $body = [
            'audio_url' => $audioUrl,
            'speech_models' => ['universal-3-pro'],
            'speaker_labels' => true,
            'language_code' => $language,
        ];
        if ($speakersExpected !== null && $speakersExpected > 0) {
            $body['speakers_expected'] = max(1, min(10, $speakersExpected));
        }
        return $this->request('POST', '/transcript', $body);
    }

    /**
     * Get current state of a transcript job.
     * Returns { status: 'queued'|'processing'|'completed'|'error', text, utterances[], audio_duration, ... }
     */
    public function getTranscript(string $transcriptId): array {
        return $this->request('GET', '/transcript/' . urlencode($transcriptId));
    }

    /**
     * Format an AssemblyAI transcript response into our canonical format.
     * Concatenates utterances with [Speaker X] labels, merges consecutive same-speaker turns.
     */
    public function formatTranscript(array $transcriptData): string {
        $utterances = $transcriptData['utterances'] ?? null;
        if (!is_array($utterances) || !$utterances) {
            // Fall back to plain text
            return (string)($transcriptData['text'] ?? '');
        }
        $out = [];
        $lastSpeaker = null;
        $buf = [];
        foreach ($utterances as $u) {
            $sp = (string)($u['speaker'] ?? '');
            $txt = trim((string)($u['text'] ?? ''));
            if ($sp === '' || $txt === '') continue;
            if ($sp !== $lastSpeaker && $buf) {
                $out[] = "[Speaker {$lastSpeaker}] " . implode(' ', $buf);
                $buf = [];
            }
            $lastSpeaker = $sp;
            $buf[] = $txt;
        }
        if ($buf) $out[] = "[Speaker {$lastSpeaker}] " . implode(' ', $buf);
        return implode("\n\n", $out);
    }

    private function request(string $method, string $path, ?array $body = null): array {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'ASSEMBLYAI_API_KEY not set'];
        }
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $this->key,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? new stdClass()));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) return ['ok' => false, 'error' => $err];
        $data = json_decode((string)$resp, true);
        if ($status >= 400) {
            $msg = is_array($data) ? ($data['error'] ?? json_encode($data)) : "HTTP $status";
            return ['ok' => false, 'error' => "AssemblyAI $status: " . $msg, 'data' => $data];
        }
        return ['ok' => true, 'data' => $data];
    }
}
