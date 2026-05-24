<?php
const GS_SESSION_LIFETIME = 7200;

ini_set('session.gc_maxlifetime', (string)GS_SESSION_LIFETIME);
ini_set('session.cookie_lifetime', (string)GS_SESSION_LIFETIME);
ini_set('session.use_strict_mode', '1');

session_set_cookie_params([
    'lifetime' => GS_SESSION_LIFETIME,
    'path'     => '/jupiter/ginger-sync/',
    'domain'   => '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/supabase.php';

function isLoggedIn(): bool {
    if (empty($_SESSION['gs_auth'])) return false;
    $last = (int)($_SESSION['gs_last_activity'] ?? 0);
    if ($last && (time() - $last) > GS_SESSION_LIFETIME) {
        $_SESSION = [];
        return false;
    }
    return true;
}

function requireAuth(): void {
    if (!isLoggedIn()) {
        header('Location: /jupiter/ginger-sync/');
        exit;
    }
    $_SESSION['gs_last_activity'] = time();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + GS_SESSION_LIFETIME,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
}

function getPasswordHash(): string {
    try {
        $db = new Supabase('service');
        $result = $db->from('settings', 'select=value&key=eq.dashboard_password_hash&limit=1');
        $hash = (string)($result['data'][0]['value'] ?? '');
        if ($hash) return trim($hash, '"');
    } catch (Throwable $e) {
        // fall through to env fallback
    }
    return $_ENV['DASHBOARD_PASSWORD_HASH'] ?? '';
}

function login(string $password): bool {
    $hash = getPasswordHash();
    if ($hash && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION['gs_auth'] = true;
        $_SESSION['gs_login_time'] = time();
        $_SESSION['gs_last_activity'] = time();
        return true;
    }
    return false;
}

function setPassword(string $newPassword): bool {
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $db = new Supabase('service');
    $res = $db->upsert('settings', [
        'key' => 'dashboard_password_hash',
        'value' => json_encode($hash),
    ], 'key');
    return empty($res['error']);
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function csrfToken(): string {
    if (empty($_SESSION['gs_csrf'])) {
        $_SESSION['gs_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['gs_csrf'];
}

function requireCsrf(): void {
    $given = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!hash_equals($_SESSION['gs_csrf'] ?? '', (string)$given)) {
        http_response_code(403);
        echo json_encode(['error' => 'bad csrf']);
        exit;
    }
}
