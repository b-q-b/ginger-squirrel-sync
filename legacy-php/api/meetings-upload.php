<?php
/**
 * Accepts a single audio file upload, stores it on disk, creates a meeting row
 * in status=uploaded, and returns the meeting id.
 *
 *   POST /api/meetings-upload.php
 *     multipart/form-data:
 *       audio              (file, required)
 *       title              (string, optional)
 *       speakers_expected  (int, optional, 1..10)
 *       hot_plate_item_id  (uuid, optional)
 *       csrf               (string, required)
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/audio_storage.php';
requireAuth();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit;
}
requireCsrf();

$f = $_FILES['audio'] ?? null;
if (!$f || ($f['error'] ?? PHP_FILE_UPLOAD_ERROR_NO_FILE) !== UPLOAD_ERR_OK) {
    $code = $f['error'] ?? -1;
    $msg = match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large (PHP/HTML limit)',
        UPLOAD_ERR_PARTIAL    => 'Partial upload',
        UPLOAD_ERR_NO_FILE    => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'No tmp dir on server',
        UPLOAD_ERR_CANT_WRITE => 'Cannot write to disk',
        default => "Upload error $code",
    };
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$mime = (string)($f['type'] ?? '');
$ext = mimeToExtension($mime);
if (!$ext) {
    // Try by filename
    $byExt = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (in_array($byExt, ['mp3','m4a','aac','wav','webm','ogg','flac','mp4'], true)) {
        $ext = $byExt;
        $mime = extensionToMime($ext);
    }
}
if (!$ext) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Unsupported audio type: $mime"]);
    exit;
}

try {
    $db = new Supabase('service');

    // Create the meeting row first so we have its ID for the file path
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') {
        $base = pathinfo((string)($f['name'] ?? ''), PATHINFO_FILENAME) ?: 'Untitled';
        $title = mb_substr($base, 0, 80);
    }
    $speakers = (int)($_POST['speakers_expected'] ?? 0);
    if ($speakers < 1 || $speakers > 10) $speakers = null;
    $hpItem = $_POST['hot_plate_item_id'] ?? null;
    if (!$hpItem) $hpItem = null;

    $created = $db->insert('meetings', [
        'title' => $title,
        'recorded_at' => gmdate('c'),
        'status' => 'uploaded',
        'audio_mime' => $mime,
        'audio_extension' => $ext,
        'audio_size_bytes' => (int)($f['size'] ?? 0),
        'speakers_expected' => $speakers,
        'hot_plate_item_id' => $hpItem,
    ]);
    if (!empty($created['error'])) {
        echo json_encode(['ok' => false, 'error' => $created['error']]); exit;
    }
    $row = $created['data'][0] ?? null;
    if (!$row) { echo json_encode(['ok' => false, 'error' => 'create failed']); exit; }
    $meetingId = (string)$row['id'];

    // Move uploaded tmp file into our storage layout
    $dir = ensureAudioDir($meetingId);
    $target = audioFilePath($meetingId, $ext);
    if (!@move_uploaded_file((string)$f['tmp_name'], $target)) {
        // Roll back the row
        $db->update('meetings', 'id=eq.' . urlencode($meetingId), [
            'status' => 'error',
            'error_message' => 'Failed to move uploaded file to ' . $target,
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to store uploaded file']);
        exit;
    }

    $db->update('meetings', 'id=eq.' . urlencode($meetingId), [
        'audio_path' => 'meetings/' . $meetingId . '/audio.' . $ext,
    ]);

    echo json_encode(['ok' => true, 'meeting_id' => $meetingId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
