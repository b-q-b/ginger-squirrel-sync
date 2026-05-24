<?php
/**
 * Serves a meeting's audio file with auth. Files live outside the web-accessible
 * area (under /data/) so direct HTTP access is blocked by .htaccess.
 *
 *   GET /api/meetings-audio.php?id=X
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/audio_storage.php';
requireAuth();

$id = $_GET['id'] ?? '';
if (!$id) { http_response_code(400); exit('id required'); }

$db = new Supabase('service');
$r = $db->from('meetings', 'select=id,audio_extension,audio_mime,title,deleted_at&id=eq.' . urlencode($id) . '&limit=1');
$row = $r['data'][0] ?? null;
if (!$row || !empty($row['deleted_at'])) { http_response_code(404); exit('not found'); }

$ext = (string)($row['audio_extension'] ?? '');
$path = audioFilePath((string)$row['id'], $ext);
if (!is_file($path)) { http_response_code(404); exit('audio missing'); }

$mime = (string)($row['audio_mime'] ?? extensionToMime($ext));
$size = filesize($path);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');

// Basic byte-range support so the browser audio player can seek
if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $m)) {
        $start = (int)$m[1];
        $end = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : $size - 1;
        if ($end >= $size) $end = $size - 1;
        $length = $end - $start + 1;
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
        header("Content-Length: $length");
        $fp = fopen($path, 'rb');
        fseek($fp, $start);
        echo fread($fp, $length);
        fclose($fp);
        exit;
    }
}

readfile($path);
