<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
header('Content-Type: application/json');
requireCsrf();

$current = $_POST['current'] ?? '';
$next = $_POST['next'] ?? '';

if (strlen($next) < 8) {
    echo json_encode(['ok' => false, 'error' => 'New password must be at least 8 characters']);
    exit;
}

$hash = getPasswordHash();
if (!$hash || !password_verify($current, $hash)) {
    usleep(500000);
    echo json_encode(['ok' => false, 'error' => 'Current password is incorrect']);
    exit;
}

if (setPassword($next)) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Could not save new password']);
}
