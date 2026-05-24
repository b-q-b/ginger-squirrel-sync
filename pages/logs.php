<?php
$pageTitle = 'Sync Logs';
require_once __DIR__ . '/../includes/header.php';

$db = new Supabase('service');

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$source = $_GET['source'] ?? '';
$status = $_GET['status'] ?? '';
$action = $_GET['action'] ?? '';

$filters = [];
if ($source) $filters[] = 'source=eq.' . urlencode($source);
if ($status) $filters[] = 'status=eq.' . urlencode($status);
if ($action) $filters[] = 'action=eq.' . urlencode($action);
$filterQuery = $filters ? '&' . implode('&', $filters) : '';

$query = "select=*&order=created_at.desc&limit={$perPage}&offset={$offset}{$filterQuery}";
$res = $db->from('sync_events', $query);
$rows = $res['data'] ?? [];

// Distinct values for filter dropdowns
$srcRes = $db->from('sync_events', 'select=source');
$sources = array_unique(array_filter(array_map(fn($r) => $r['source'] ?? null, $srcRes['data'] ?? [])));

$mappings = $db->from('mappings', 'select=id,label');
$mappingLabels = [];
foreach (($mappings['data'] ?? []) as $m) {
    $mappingLabels[(string)($m['id'] ?? '')] = (string)($m['label'] ?? '');
}
?>
<style>
.logs-page { max-width: 1100px; }
.log-filters {
    display: flex; gap: 10px; margin-bottom: 16px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 12px;
    padding: 14px 18px; align-items: center;
}
.log-filters select {
    padding: 7px 28px 7px 10px;
    border: 1px solid var(--border); border-radius: 8px;
    background: var(--bg); font-size: 13px;
}
.log-filters .label { font-size: 12px; color: var(--muted); }
.log-table {
    width: 100%; border-collapse: collapse;
    background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden;
}
.log-table th, .log-table td { padding: 10px 14px; text-align: left; font-size: 12.5px; }
.log-table th { background: var(--bg); color: var(--muted); font-weight: 500; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
.log-table tr { border-top: 1px solid var(--border); }
.log-table tr:first-child { border-top: 0; }
.log-table .ts { font-family: ui-monospace, monospace; color: var(--muted); }
.log-table .id { font-family: ui-monospace, monospace; font-size: 11px; color: var(--muted); }
.log-table .err { color: var(--danger); font-size: 11px; }

.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }
.dot.ok { background: var(--ok); }
.dot.bad { background: var(--danger); }
.dot.idle { background: var(--muted); }

.action-pill {
    padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 500;
    display: inline-block;
}
.action-pill.create { background: #ecfdf5; color: #166534; }
.action-pill.update { background: #eff6ff; color: #1e40af; }
.action-pill.delete { background: #fef2f2; color: #991b1b; }
.action-pill.skip { background: #f3f4f6; color: var(--muted); }
.action-pill.fetch_failed { background: #fef2f2; color: #991b1b; }

.dir-arrow { font-size: 11px; color: var(--muted); font-family: ui-monospace, monospace; }
.empty { padding: 30px; text-align: center; color: var(--muted); }

.pager { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; font-size: 13px; color: var(--muted); }
.pager a {
    padding: 6px 12px; border: 1px solid var(--border); border-radius: 6px;
    background: var(--surface); color: var(--text); text-decoration: none;
    margin-left: 4px; font-size: 13px;
}
.pager a.disabled { opacity: 0.3; pointer-events: none; }
.pager a:hover { background: var(--accent-soft); border-color: var(--accent); color: var(--accent-hover); }
</style>

<div class="logs-page">

<form class="log-filters" method="GET">
    <span class="label">Filter:</span>
    <select name="source">
        <option value="">All sources</option>
        <?php foreach ($sources as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>" <?= $source === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status">
        <option value="">All statuses</option>
        <option value="ok" <?= $status === 'ok' ? 'selected' : '' ?>>ok</option>
        <option value="error" <?= $status === 'error' ? 'selected' : '' ?>>error</option>
        <option value="skipped" <?= $status === 'skipped' ? 'selected' : '' ?>>skipped</option>
    </select>
    <select name="action">
        <option value="">All actions</option>
        <option value="create" <?= $action === 'create' ? 'selected' : '' ?>>create</option>
        <option value="update" <?= $action === 'update' ? 'selected' : '' ?>>update</option>
        <option value="delete" <?= $action === 'delete' ? 'selected' : '' ?>>delete</option>
        <option value="skip_hash" <?= $action === 'skip_hash' ? 'selected' : '' ?>>skip_hash</option>
        <option value="skip_echo" <?= $action === 'skip_echo' ? 'selected' : '' ?>>skip_echo</option>
        <option value="fetch_failed" <?= $action === 'fetch_failed' ? 'selected' : '' ?>>fetch_failed</option>
    </select>
    <button type="submit" class="btn" style="padding:7px 14px;border:1px solid var(--accent);background:var(--accent);color:#fff;border-radius:8px;font-size:13px;cursor:pointer;">Apply</button>
    <a href="/jupiter/ginger-sync/pages/logs.php" style="margin-left:auto;font-size:12px;color:var(--muted);text-decoration:none;">Reset</a>
</form>

<?php if (!$rows): ?>
    <div class="log-table">
        <div class="empty">
            No events match the filter.<br>
            <small>Trigger a sync from the <a href="/jupiter/ginger-sync/pages/mappings.php" style="color:var(--accent);">Mappings</a> page or wait for webhooks.</small>
        </div>
    </div>
<?php else: ?>
    <table class="log-table">
        <thead><tr>
            <th>When</th>
            <th>Source</th>
            <th>Action</th>
            <th>Direction</th>
            <th>Mapping</th>
            <th>Trello</th>
            <th>ClickUp</th>
            <th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $e):
            $ts = (string)($e['created_at'] ?? '');
            $when = $ts ? date('M j H:i:s', strtotime($ts)) : '';
            $src = (string)($e['source'] ?? '');
            $act = (string)($e['action'] ?? '');
            $dir = (string)($e['direction'] ?? '');
            $statusVal = (string)($e['status'] ?? '');
            $mid = (string)($e['mapping_id'] ?? '');
            $mapLabel = $mappingLabels[$mid] ?? '';
            $tCard = (string)($e['trello_card_id'] ?? '');
            $cTask = (string)($e['clickup_task_id'] ?? '');
            $err = (string)($e['error'] ?? '');

            $actClass = match (true) {
                str_starts_with($act, 'skip') => 'skip',
                $act === 'create' => 'create',
                $act === 'update' => 'update',
                $act === 'delete' => 'delete',
                default => 'fetch_failed',
            };
            $dotClass = match ($statusVal) {
                'ok' => 'ok',
                'error' => 'bad',
                'skipped' => 'idle',
                default => 'idle',
            };
        ?>
            <tr>
                <td class="ts"><?= htmlspecialchars($when) ?></td>
                <td><?= htmlspecialchars($src) ?></td>
                <td><span class="action-pill <?= $actClass ?>"><?= htmlspecialchars($act) ?></span></td>
                <td class="dir-arrow"><?= $dir === 'trello_to_clickup' ? 'T → CU' : ($dir === 'clickup_to_trello' ? 'CU → T' : '—') ?></td>
                <td><?= htmlspecialchars($mapLabel ?: ($mid ? substr($mid, 0, 8) : '—')) ?></td>
                <td class="id"><?= $tCard ? htmlspecialchars(substr($tCard, 0, 10)) . '…' : '—' ?></td>
                <td class="id"><?= $cTask ? htmlspecialchars(substr($cTask, 0, 10)) . '…' : '—' ?></td>
                <td>
                    <span class="dot <?= $dotClass ?>"></span><?= htmlspecialchars($statusVal) ?>
                    <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="pager">
        <span>Page <?= $page ?></span>
        <div>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>">← Prev</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="<?= count($rows) < $perPage ? 'disabled' : '' ?>">Next →</a>
        </div>
    </div>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
