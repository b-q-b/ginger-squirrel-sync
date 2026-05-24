<?php
$pageTitle = 'Meetings';
require_once __DIR__ . '/../includes/header.php';

$db = new Supabase('service');
$res = $db->from('meetings', 'select=id,title,recorded_at,duration_ms,status,error_message,hot_plate_item_id,created_at&deleted_at=is.null&order=recorded_at.desc&limit=200');
$rows = $res['data'] ?? [];

// Hot plate labels for the linked-item column
$hp = $db->from('hot_plate_items', 'select=id,title&deleted_at=is.null');
$hpById = [];
foreach (($hp['data'] ?? []) as $i) $hpById[(string)$i['id']] = (string)$i['title'];

function statusLabel(string $s): array {
    return [
        'uploaded'     => ['Queued', 'pill-idle'],
        'transcribing' => ['Transcribing…', 'pill-warn'],
        'analyzing'    => ['Analysing…', 'pill-warn'],
        'ready'        => ['Ready', 'pill-ok'],
        'error'        => ['Error', 'pill-bad'],
        'audio_only'   => ['Audio only', 'pill-idle'],
    ][$s] ?? [$s, 'pill-idle'];
}
?>
<style>
.mt-page { max-width: 1080px; }
.mt-toolbar {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    margin-bottom: 18px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 12px;
    padding: 14px 18px;
}
.mt-toolbar .label { font-size: 13px; color: var(--muted); }
.mt-toolbar .btn {
    padding: 8px 16px; border-radius: 8px; border: 1px solid var(--accent);
    background: var(--accent); color: #fff; cursor: pointer;
    font-size: 13px; font-weight: 500; text-decoration: none;
}
.mt-toolbar .btn:hover { background: var(--accent-hover); border-color: var(--accent-hover); }

.mt-table { width: 100%; border-collapse: collapse;
    background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.mt-table th, .mt-table td { padding: 12px 14px; text-align: left; font-size: 13px; }
.mt-table th { background: var(--bg); color: var(--muted); font-weight: 500; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
.mt-table tr { border-top: 1px solid var(--border); }
.mt-table tr:first-child { border-top: 0; }
.mt-table .title { font-weight: 600; color: var(--text); }
.mt-table .title a { color: var(--text); text-decoration: none; }
.mt-table .title a:hover { color: var(--accent); }
.mt-table .meta { font-size: 11px; color: var(--muted); }
.mt-table .err { font-size: 11px; color: var(--danger); }

.pill { padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 500; }
.pill-ok   { background: #ecfdf5; color: #166534; }
.pill-bad  { background: #fef2f2; color: #991b1b; }
.pill-warn { background: #fef3c7; color: #92400e; }
.pill-idle { background: #f3f4f6; color: var(--muted); }

.empty { padding: 30px; text-align: center; color: var(--muted); }
</style>

<div class="mt-page">
    <div class="mt-toolbar">
        <div>
            <strong style="font-size:14px;color:var(--text);"><?= count($rows) ?> meeting<?= count($rows) === 1 ? '' : 's' ?></strong>
            <span class="label" style="margin-left:8px;">listening, transcribing, analysing</span>
        </div>
        <a class="btn" href="/jupiter/ginger-sync/pages/meeting-new.php">+ New meeting</a>
    </div>

    <?php if (!$rows): ?>
        <div class="mt-table"><div class="empty">No meetings yet. <a href="/jupiter/ginger-sync/pages/meeting-new.php" style="color:var(--accent);">Upload one</a> to get a transcript + AI summary.</div></div>
    <?php else: ?>
        <table class="mt-table">
            <thead><tr>
                <th>Title</th>
                <th>Recorded</th>
                <th>Duration</th>
                <th>Hot Plate</th>
                <th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $m):
                [$label, $cls] = statusLabel((string)($m['status'] ?? ''));
                $when = $m['recorded_at'] ? date('M j, H:i', strtotime((string)$m['recorded_at'])) : '—';
                $dur = $m['duration_ms'] ? gmdate(((int)$m['duration_ms']) >= 3600000 ? 'H:i:s' : 'i:s', (int)floor(((int)$m['duration_ms'])/1000)) : '—';
                $hpId = (string)($m['hot_plate_item_id'] ?? '');
            ?>
                <tr>
                    <td class="title"><a href="/jupiter/ginger-sync/pages/meeting.php?id=<?= htmlspecialchars((string)$m['id']) ?>"><?= htmlspecialchars((string)($m['title'] ?? 'Untitled')) ?></a>
                        <?php if (!empty($m['error_message']) && ($m['status'] ?? '') === 'error'): ?><div class="err"><?= htmlspecialchars(mb_substr((string)$m['error_message'], 0, 120)) ?></div><?php endif; ?>
                    </td>
                    <td class="meta"><?= htmlspecialchars($when) ?></td>
                    <td class="meta"><?= htmlspecialchars($dur) ?></td>
                    <td class="meta"><?= $hpId && isset($hpById[$hpId]) ? htmlspecialchars($hpById[$hpId]) : '—' ?></td>
                    <td><span class="pill <?= $cls ?>"><?= htmlspecialchars($label) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
