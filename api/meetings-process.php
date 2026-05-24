<?php
/**
 * Drives the meeting through the pipeline:
 *   uploaded   → upload audio to AssemblyAI, create transcript job, status=transcribing
 *   transcribing → poll AssemblyAI; if completed, persist transcript, status=analyzing
 *   analyzing  → call OpenRouter for analysis, persist, status=ready
 *
 * Idempotent: safe to call repeatedly. Browser polls this every few seconds
 * after upload until status becomes ready or error.
 *
 *   POST /api/meetings-process.php  { id }
 *
 * Authenticated (session). Also callable from cron via ?key=WEBHOOK_SECRET&id=X
 * if you wire it up; currently the polling browser drives it.
 */
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/audio_storage.php';
require_once __DIR__ . '/../lib/transcribe.php';
require_once __DIR__ . '/../lib/analyze.php';

header('Content-Type: application/json');

// Allow either session auth OR cron key
$cronKey = $_GET['key'] ?? '';
$envSecret = $_ENV['WEBHOOK_SECRET'] ?? '';
$isCron = $cronKey && $envSecret && hash_equals($envSecret, (string)$cronKey);
if (!$isCron) {
    requireAuth();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$isCron) {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit;
}
if (!$isCron) requireCsrf();

$body = $isCron ? [] : (json_decode(file_get_contents('php://input'), true) ?: []);
$id = (string)($body['id'] ?? $_GET['id'] ?? '');
if (!$id) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id required']); exit; }

set_time_limit(120);

try {
    $db = new Supabase('service');
    $aai = new AssemblyAI();
    $analyzer = new MeetingAnalyzer();

    $r = $db->from('meetings', 'select=*&id=eq.' . urlencode($id) . '&limit=1');
    $row = $r['data'][0] ?? null;
    if (!$row) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not found']); exit; }

    $status = (string)($row['status'] ?? '');

    // ── Step 1: uploaded → transcribing ──
    if ($status === 'uploaded') {
        if (!$aai->configured()) {
            $db->update('meetings', 'id=eq.' . urlencode($id), [
                'status' => 'audio_only',
                'error_message' => 'AssemblyAI not configured. Audio stored; transcription skipped.',
            ]);
            echo json_encode(['ok' => true, 'status' => 'audio_only']); exit;
        }
        $ext = (string)($row['audio_extension'] ?? '');
        $localPath = audioFilePath($id, $ext);
        if (!is_file($localPath)) {
            $db->update('meetings', 'id=eq.' . urlencode($id), ['status' => 'error', 'error_message' => "Audio file missing on disk: $localPath"]);
            echo json_encode(['ok' => false, 'error' => 'audio missing']); exit;
        }

        $up = $aai->upload($localPath);
        if (!$up['ok']) {
            $db->update('meetings', 'id=eq.' . urlencode($id), ['status' => 'error', 'error_message' => 'upload: ' . $up['error']]);
            echo json_encode(['ok' => false, 'error' => $up['error']]); exit;
        }
        $createRes = $aai->createTranscript(
            $up['upload_url'],
            $row['speakers_expected'] ? (int)$row['speakers_expected'] : null,
            (string)($row['language'] ?? 'en')
        );
        if (!$createRes['ok']) {
            $db->update('meetings', 'id=eq.' . urlencode($id), ['status' => 'error', 'error_message' => 'create transcript: ' . $createRes['error']]);
            echo json_encode(['ok' => false, 'error' => $createRes['error']]); exit;
        }
        $tId = (string)($createRes['data']['id'] ?? '');
        $db->update('meetings', 'id=eq.' . urlencode($id), [
            'status' => 'transcribing',
            'assemblyai_transcript_id' => $tId,
            'error_message' => null,
            'updated_at' => gmdate('c'),
        ]);
        echo json_encode(['ok' => true, 'status' => 'transcribing', 'transcript_id' => $tId]); exit;
    }

    // ── Step 2: transcribing → analyzing ──
    if ($status === 'transcribing') {
        $tId = (string)($row['assemblyai_transcript_id'] ?? '');
        if (!$tId) {
            $db->update('meetings', 'id=eq.' . urlencode($id), ['status' => 'error', 'error_message' => 'missing transcript id']);
            echo json_encode(['ok' => false, 'error' => 'missing transcript id']); exit;
        }
        $get = $aai->getTranscript($tId);
        if (!$get['ok']) {
            // Could be transient — surface but don't change status
            echo json_encode(['ok' => false, 'status' => 'transcribing', 'error' => $get['error']]); exit;
        }
        $aaiStatus = (string)($get['data']['status'] ?? '');
        if ($aaiStatus === 'completed') {
            $text = $aai->formatTranscript($get['data']);
            $durMs = (int)(($get['data']['audio_duration'] ?? 0) * 1000);
            $db->update('meetings', 'id=eq.' . urlencode($id), [
                'status' => 'analyzing',
                'transcript' => $text,
                'duration_ms' => $durMs ?: null,
                'updated_at' => gmdate('c'),
            ]);
            echo json_encode(['ok' => true, 'status' => 'analyzing']); exit;
        }
        if ($aaiStatus === 'error') {
            $err = (string)($get['data']['error'] ?? 'unknown transcription error');
            $db->update('meetings', 'id=eq.' . urlencode($id), ['status' => 'error', 'error_message' => $err]);
            echo json_encode(['ok' => false, 'error' => $err]); exit;
        }
        // queued | processing — still waiting
        echo json_encode(['ok' => true, 'status' => 'transcribing', 'assemblyai_status' => $aaiStatus]); exit;
    }

    // ── Step 3: analyzing → ready ──
    if ($status === 'analyzing') {
        $transcript = (string)($row['transcript'] ?? '');
        if ($transcript === '') {
            $db->update('meetings', 'id=eq.' . urlencode($id), ['status' => 'error', 'error_message' => 'no transcript to analyze']);
            echo json_encode(['ok' => false, 'error' => 'no transcript']); exit;
        }
        if (!$analyzer->configured()) {
            $db->update('meetings', 'id=eq.' . urlencode($id), ['status' => 'ready', 'error_message' => 'OPENROUTER_API_KEY not set; transcript saved but no analysis']);
            echo json_encode(['ok' => true, 'status' => 'ready', 'note' => 'no analysis (key missing)']); exit;
        }
        $res = $analyzer->analyze($transcript, (string)($row['title'] ?? ''));
        if (!$res['ok']) {
            $db->update('meetings', 'id=eq.' . urlencode($id), ['status' => 'error', 'error_message' => 'analyze: ' . $res['error']]);
            echo json_encode(['ok' => false, 'error' => $res['error']]); exit;
        }
        $db->update('meetings', 'id=eq.' . urlencode($id), [
            'status' => 'ready',
            'analysis' => $res['analysis'],
            'error_message' => null,
            'updated_at' => gmdate('c'),
        ]);
        echo json_encode(['ok' => true, 'status' => 'ready']); exit;
    }

    // Already terminal
    echo json_encode(['ok' => true, 'status' => $status, 'note' => 'no work to do']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
