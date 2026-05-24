<?php
/**
 * Audio file storage on local disk (Zume for now, future VPS).
 * Files live at `data/meetings/{meeting_id}/audio.{ext}` relative to the app root.
 * `.htaccess` in /data/ blocks direct HTTP access; serving happens through
 * api/meetings-audio.php with auth gate.
 */

function audioRoot(): string {
    return realpath(__DIR__ . '/..') . '/data/meetings';
}

function audioDir(string $meetingId): string {
    return audioRoot() . '/' . preg_replace('/[^a-z0-9-]/i', '', $meetingId);
}

function audioFilePath(string $meetingId, string $extension): string {
    $ext = preg_replace('/[^a-z0-9]/i', '', strtolower($extension));
    return audioDir($meetingId) . '/audio.' . $ext;
}

/**
 * Detect a safe extension from a MIME type. Returns null for unknown types.
 */
function mimeToExtension(string $mime): ?string {
    $mime = strtolower(trim($mime));
    // Strip params (e.g. 'audio/webm; codecs=opus')
    if (($p = strpos($mime, ';')) !== false) $mime = substr($mime, 0, $p);
    return [
        'audio/mpeg'  => 'mp3',
        'audio/mp3'   => 'mp3',
        'audio/mp4'   => 'm4a',
        'audio/m4a'   => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac'   => 'aac',
        'audio/wav'   => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave'  => 'wav',
        'audio/webm'  => 'webm',
        'audio/ogg'   => 'ogg',
        'audio/x-flac'=> 'flac',
        'audio/flac'  => 'flac',
        'video/mp4'   => 'mp4', // some phone recorders give video/mp4
    ][$mime] ?? null;
}

function extensionToMime(string $ext): string {
    return [
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
        'wav' => 'audio/wav', 'webm' => 'audio/webm', 'ogg' => 'audio/ogg',
        'flac' => 'audio/flac', 'mp4' => 'video/mp4',
    ][strtolower($ext)] ?? 'application/octet-stream';
}

/**
 * Ensure the per-meeting directory exists. Creates a `.htaccess` inside data/
 * on first call as a safety net.
 */
function ensureAudioDir(string $meetingId): string {
    $root = audioRoot();
    if (!is_dir($root)) {
        @mkdir($root, 0775, true);
        $htAccess = dirname($root) . '/.htaccess';
        if (!is_file($htAccess)) {
            @file_put_contents($htAccess, "Require all denied\n");
        }
    }
    $dir = audioDir($meetingId);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}
