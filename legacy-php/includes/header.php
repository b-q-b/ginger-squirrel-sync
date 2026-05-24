<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();

$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
$pageTitle = $pageTitle ?? 'Ginger Sync';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ginger Sync — <?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🐿️</text></svg>">
    <link rel="stylesheet" href="/jupiter/ginger-sync/public/css/style.css">
</head>
<body>
    <nav class="sidebar">
        <div class="brand">
            <span class="brand-emoji">🐿️</span>
            <span class="brand-name">Ginger Sync</span>
        </div>
        <ul class="nav-list">
            <li><a href="/jupiter/ginger-sync/pages/dashboard.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">Dashboard</a></li>
            <li><a href="/jupiter/ginger-sync/pages/hot-plate.php" class="<?= $currentPage === 'hot-plate' ? 'active' : '' ?>">Hot Plate</a></li>
            <li><a href="/jupiter/ginger-sync/pages/meetings.php" class="<?= in_array($currentPage, ['meetings','meeting','meeting-new']) ? 'active' : '' ?>">Meetings</a></li>
            <li><a href="/jupiter/ginger-sync/pages/mappings.php" class="<?= $currentPage === 'mappings' ? 'active' : '' ?>">Mappings</a></li>
            <li><a href="/jupiter/ginger-sync/pages/items.php" class="<?= $currentPage === 'items' ? 'active' : '' ?>">Items</a></li>
            <li><a href="/jupiter/ginger-sync/pages/logs.php" class="<?= $currentPage === 'logs' ? 'active' : '' ?>">Logs</a></li>
            <li><a href="/jupiter/ginger-sync/pages/settings.php" class="<?= $currentPage === 'settings' ? 'active' : '' ?>">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="/jupiter/ginger-sync/api/logout.php" class="logout">Sign out</a>
        </div>
    </nav>
    <main class="content">
        <header class="topbar">
            <h1><?= htmlspecialchars($pageTitle) ?></h1>
        </header>
        <div class="page">
