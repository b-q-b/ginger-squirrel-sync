<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';

$db = new Supabase('service');

// Pull counts + recent events in parallel-ish (3 fast queries).
$settings = $db->from('settings', 'select=key,value');
$settingsMap = [];
foreach (($settings['data'] ?? []) as $s) {
    $settingsMap[(string)($s['key'] ?? '')] = $s['value'] ?? null;
}

$hasClickupToken = !empty($_ENV['CLICKUP_TOKEN']);
$hasTrelloKey    = !empty($_ENV['TRELLO_KEY']) && !empty($_ENV['TRELLO_TOKEN']);

$mappings = $db->from('mappings', 'select=id,is_active');
$mappingCount = is_array($mappings['data'] ?? null) ? count($mappings['data']) : 0;
$activeMappingCount = 0;
foreach (($mappings['data'] ?? []) as $m) {
    if (!empty($m['is_active'])) $activeMappingCount++;
}

$events = $db->from('sync_events', 'select=id,created_at,source,action,status,error&order=created_at.desc&limit=10');
$eventList = $events['data'] ?? [];
$eventCount24h = 0;
$errorCount24h = 0;
foreach ($eventList as $e) {
    $ts = strtotime((string)($e['created_at'] ?? ''));
    if ($ts && $ts > time() - 86400) {
        $eventCount24h++;
        if (($e['status'] ?? '') === 'error') $errorCount24h++;
    }
}

$webhooks = $db->from('webhook_registrations', 'select=platform,status');
$clickupWh = 'none';
$trelloWh  = 'none';
foreach (($webhooks['data'] ?? []) as $w) {
    if (($w['platform'] ?? '') === 'clickup') $clickupWh = (string)($w['status'] ?? 'none');
    if (($w['platform'] ?? '') === 'trello')  $trelloWh  = (string)($w['status'] ?? 'none');
}

// Cron health
$cronRecentRes = $db->from('sync_events', 'select=created_at&source=eq.reconcile_cron&order=created_at.desc&limit=1');
$cronLastTs = $cronRecentRes['data'][0]['created_at'] ?? null;
$cronAgeSec = $cronLastTs ? (time() - strtotime($cronLastTs)) : null;
$cronOk = $cronAgeSec !== null && $cronAgeSec < 900;
$realtimeOn = $clickupWh === 'active' || $trelloWh === 'active';
$modeLabel = $realtimeOn ? 'real-time (webhooks)' : ($cronOk ? 'polling (cron 5m)' : 'idle');
$modeClass = $realtimeOn ? 'ok' : ($cronOk ? 'idle' : 'bad');

// Step completion heuristic.
$stepTokens   = $hasClickupToken && $hasTrelloKey;
$stepMapping  = $activeMappingCount > 0;
$stepWebhooks = $clickupWh === 'active' && $trelloWh === 'active';

function stepIcon(bool $done): string {
    return $done ? '✓' : '○';
}
function stepClass(bool $done): string {
    return $done ? 'done' : 'pending';
}
?>
<section class="grid stat-grid">
    <div class="stat">
        <div class="stat-label">Active mappings</div>
        <div class="stat-value"><?= $activeMappingCount ?><span class="stat-suffix">/ <?= $mappingCount ?> total</span></div>
    </div>
    <div class="stat">
        <div class="stat-label">Sync events (24h)</div>
        <div class="stat-value"><?= $eventCount24h ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Errors (24h)</div>
        <div class="stat-value <?= $errorCount24h > 0 ? 'warn' : '' ?>"><?= $errorCount24h ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Sync mode</div>
        <div class="stat-value health">
            <span class="dot <?= $modeClass ?>"></span><?= htmlspecialchars($modeLabel) ?>
        </div>
        <?php if ($cronLastTs): ?>
            <div style="font-size:11px;color:var(--muted);margin-top:4px;">
                last cron: <?= $cronAgeSec < 60 ? "{$cronAgeSec}s ago" : floor($cronAgeSec/60) . 'm ago' ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="two-col">
    <div class="panel">
        <h2>Getting started</h2>
        <ol class="checklist">
            <li class="<?= stepClass($stepTokens) ?>">
                <span class="check"><?= stepIcon($stepTokens) ?></span>
                <div>
                    <strong>Connect ClickUp &amp; Trello</strong>
                    <p>Paste your ClickUp personal token and Trello API key + token in <a href="/jupiter/ginger-sync/pages/settings.php">Settings</a>. Both are free to generate.</p>
                </div>
            </li>
            <li class="<?= stepClass($stepMapping) ?>">
                <span class="check"><?= stepIcon($stepMapping) ?></span>
                <div>
                    <strong>Add your first mapping</strong>
                    <p>Pair a Trello board with a ClickUp list on the <a href="/jupiter/ginger-sync/pages/mappings.php">Mappings</a> page. Choose which statuses map to which Trello lists.</p>
                </div>
            </li>
            <li class="<?= stepClass($stepWebhooks) ?>">
                <span class="check"><?= stepIcon($stepWebhooks) ?></span>
                <div>
                    <strong>Register webhooks</strong>
                    <p>One click registers our endpoint with both platforms. From that moment, changes on either side propagate in seconds.</p>
                </div>
            </li>
        </ol>
    </div>

    <div class="panel">
        <h2>Recent sync events</h2>
        <?php if (empty($eventList)): ?>
            <p class="muted">No events yet. Once webhooks are registered you'll see every create / update / delete here.</p>
        <?php else: ?>
            <ul class="event-list">
                <?php foreach ($eventList as $e):
                    $status = (string)($e['status'] ?? 'unknown');
                    $action = (string)($e['action'] ?? '—');
                    $source = (string)($e['source'] ?? '—');
                    $when = date('H:i:s M j', strtotime((string)($e['created_at'] ?? '')));
                ?>
                    <li>
                        <span class="dot <?= $status === 'ok' ? 'ok' : ($status === 'skipped' ? 'idle' : 'bad') ?>"></span>
                        <span class="ev-action"><?= htmlspecialchars($action) ?></span>
                        <span class="ev-source"><?= htmlspecialchars($source) ?></span>
                        <span class="ev-time"><?= htmlspecialchars($when) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a class="more" href="/jupiter/ginger-sync/pages/logs.php">View all logs →</a>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
