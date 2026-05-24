<?php
$pageTitle = 'Meeting';
require_once __DIR__ . '/../includes/header.php';

$id = $_GET['id'] ?? '';
$db = new Supabase('service');

$row = null;
if ($id) {
    $r = $db->from('meetings', 'select=*&id=eq.' . urlencode($id) . '&limit=1');
    $row = $r['data'][0] ?? null;
}

$csrf = csrfToken();

$hpItems = $db->from('hot_plate_items', 'select=id,title&deleted_at=is.null&order=position.asc');
$hpById = [];
foreach (($hpItems['data'] ?? []) as $i) $hpById[(string)$i['id']] = (string)$i['title'];

function statusBadge(string $s): array {
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
.m-page { max-width: 880px; }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 22px 26px; margin-bottom: 18px; }
.card h2 { font-size: 16px; font-weight: 600; margin-bottom: 12px; }
.card .sub { color: var(--muted); font-size: 13px; margin-bottom: 14px; }

.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 18px; }
.head .title-edit { font-size: 22px; font-weight: 700; background: transparent; border: 0; color: var(--text); flex: 1; padding: 2px 0; border-bottom: 1px dashed transparent; }
.head .title-edit:hover, .head .title-edit:focus { border-bottom-color: var(--border); outline: none; }
.head .meta { color: var(--muted); font-size: 12px; text-align: right; }

.pill { padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 500; display: inline-block; }
.pill-ok   { background: #ecfdf5; color: #166534; }
.pill-bad  { background: #fef2f2; color: #991b1b; }
.pill-warn { background: #fef3c7; color: #92400e; }
.pill-idle { background: #f3f4f6; color: var(--muted); }

audio { width: 100%; }

.ai-section ul { padding-left: 22px; margin: 0; }
.ai-section li { margin-bottom: 6px; line-height: 1.5; }
.ai-section .item-title { font-weight: 600; color: var(--text); }
.ai-section .item-meta { font-size: 12px; color: var(--muted); margin-left: 6px; }

.transcript-wrap { max-height: 380px; overflow-y: auto; padding: 14px 16px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; font-size: 13.5px; line-height: 1.55; white-space: pre-wrap; }
.transcript-collapsed { max-height: 80px; overflow: hidden; position: relative; }
.transcript-collapsed::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 40px; background: linear-gradient(transparent, var(--bg)); pointer-events: none; }
.expand-btn { display: inline-block; margin-top: 8px; color: var(--accent); background: transparent; border: 0; padding: 0; cursor: pointer; font-size: 13px; }

.error-box { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }

.actions-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
.btn {
    padding: 7px 14px; border-radius: 8px; border: 1px solid var(--accent);
    background: var(--accent); color: #fff; cursor: pointer; font-size: 13px; font-weight: 500;
    text-decoration: none;
}
.btn:hover { background: var(--accent-hover); }
.btn.secondary { background: transparent; color: var(--accent); }
.btn.secondary:hover { background: var(--accent-soft); }
.btn.danger { background: transparent; color: var(--danger); border-color: var(--border); }
.btn.danger:hover { background: #fef2f2; border-color: var(--danger); }

select { padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg); font-size: 13px; }
</style>

<div class="m-page">

<?php if (!$row): ?>
    <div class="card"><h2>Meeting not found</h2><p class="sub"><a href="/jupiter/ginger-sync/pages/meetings.php" style="color:var(--accent);">Back to meetings →</a></p></div>
<?php else:
    [$badge, $cls] = statusBadge((string)($row['status'] ?? ''));
    $title = (string)($row['title'] ?? 'Untitled');
    $when = $row['recorded_at'] ? date('M j, Y · H:i', strtotime((string)$row['recorded_at'])) : '—';
    $dur = $row['duration_ms'] ? gmdate(((int)$row['duration_ms']) >= 3600000 ? 'H:i:s' : 'i:s', (int)floor(((int)$row['duration_ms'])/1000)) : null;
    $analysis = is_array($row['analysis'] ?? null) ? $row['analysis'] : null;
    $transcript = (string)($row['transcript'] ?? '');
    $isProcessing = in_array($row['status'] ?? '', ['uploaded', 'transcribing', 'analyzing'], true);
    $hpId = (string)($row['hot_plate_item_id'] ?? '');
?>

<div class="card">
    <div class="head">
        <input class="title-edit" id="title-input" value="<?= htmlspecialchars($title) ?>">
        <div class="meta">
            <span class="pill <?= $cls ?>" id="status-pill"><?= htmlspecialchars($badge) ?></span><br>
            <span style="margin-top:4px;display:inline-block;"><?= htmlspecialchars($when) ?><?= $dur ? ' · ' . htmlspecialchars($dur) : '' ?></span>
        </div>
    </div>

    <?php if (!empty($row['error_message']) && ($row['status'] ?? '') === 'error'): ?>
        <div class="error-box">
            <strong>Pipeline error:</strong> <?= htmlspecialchars((string)$row['error_message']) ?>
            <div style="margin-top:8px;"><button class="btn" onclick="retry()">Retry</button></div>
        </div>
    <?php endif; ?>

    <audio controls preload="metadata" src="/jupiter/ginger-sync/api/meetings-audio.php?id=<?= htmlspecialchars((string)$row['id']) ?>"></audio>

    <div class="actions-row">
        <label style="font-size:12px;color:var(--muted);">Linked Hot Plate task:</label>
        <select id="hp_item" onchange="saveLink()">
            <option value="">— none —</option>
            <?php foreach ($hpById as $hpid => $hptitle): ?>
                <option value="<?= htmlspecialchars($hpid) ?>" <?= $hpid === $hpId ? 'selected' : '' ?>><?= htmlspecialchars($hptitle) ?></option>
            <?php endforeach; ?>
        </select>
        <a class="btn danger" onclick="del()" style="margin-left:auto;">Delete</a>
    </div>
</div>

<?php if ($isProcessing): ?>
    <div class="card">
        <h2>Working on it…</h2>
        <p class="sub" id="proc-msg">Status: <strong><?= htmlspecialchars($badge) ?></strong>. This page will refresh automatically.</p>
    </div>
<?php endif; ?>

<?php if ($analysis): ?>
    <?php if (!empty($analysis['summary'])): ?>
    <div class="card ai-section">
        <h2>Summary</h2>
        <ul>
        <?php foreach ($analysis['summary'] as $line): ?>
            <li><?= htmlspecialchars((string)$line) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($analysis['decisions'])): ?>
    <div class="card ai-section">
        <h2>Decisions</h2>
        <ul>
        <?php foreach ($analysis['decisions'] as $d):
            $t = is_array($d) ? (string)($d['text'] ?? '') : (string)$d;
            if ($t === '') continue;
        ?>
            <li><?= htmlspecialchars($t) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($analysis['action_items'])): ?>
    <div class="card ai-section">
        <h2>Action items</h2>
        <ul>
        <?php foreach ($analysis['action_items'] as $a):
            if (!is_array($a)) continue;
        ?>
            <li>
                <span class="item-title"><?= htmlspecialchars((string)($a['title'] ?? '')) ?></span>
                <?php if (!empty($a['owner'])): ?><span class="item-meta">· <?= htmlspecialchars((string)$a['owner']) ?></span><?php endif; ?>
                <?php if (!empty($a['due'])): ?><span class="item-meta">· due <?= htmlspecialchars((string)$a['due']) ?></span><?php endif; ?>
                <?php if (!empty($a['context'])): ?><div class="item-meta" style="margin-top:2px;margin-left:0;"><?= htmlspecialchars((string)$a['context']) ?></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($analysis['questions'])): ?>
    <div class="card ai-section">
        <h2>Open questions</h2>
        <ul>
        <?php foreach ($analysis['questions'] as $q):
            if (!is_array($q)) continue;
        ?>
            <li>
                <span class="item-title"><?= htmlspecialchars((string)($q['question'] ?? '')) ?></span>
                <?php if (!empty($q['context'])): ?><div class="item-meta" style="margin-top:2px;"><?= htmlspecialchars((string)$q['context']) ?></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($transcript): ?>
    <div class="card">
        <h2>Transcript</h2>
        <div class="transcript-wrap transcript-collapsed" id="transcript"><?= htmlspecialchars($transcript) ?></div>
        <button class="expand-btn" onclick="document.getElementById('transcript').classList.toggle('transcript-collapsed'); this.textContent = this.textContent.includes('Expand') ? 'Collapse ▲' : 'Expand transcript ▼'">Expand transcript ▼</button>
    </div>
<?php endif; ?>

<?php endif; ?>

</div>

<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';
const MEETING_ID = '<?= htmlspecialchars((string)($row['id'] ?? '')) ?>';
const IS_PROCESSING = <?= $row && in_array($row['status'] ?? '', ['uploaded','transcribing','analyzing'], true) ? 'true' : 'false' ?>;

if (MEETING_ID) {
    // Auto-save title on blur
    const ti = document.getElementById('title-input');
    if (ti) {
        let lastSent = ti.value;
        ti.addEventListener('blur', async () => {
            const v = ti.value.trim();
            if (v === lastSent || v === '') return;
            lastSent = v;
            await fetch('/jupiter/ginger-sync/api/meetings.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
                body: JSON.stringify({ action: 'update', id: MEETING_ID, title: v })
            });
        });
    }
}

async function saveLink() {
    const v = document.getElementById('hp_item').value;
    await fetch('/jupiter/ginger-sync/api/meetings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
        body: JSON.stringify({ action: 'update', id: MEETING_ID, hot_plate_item_id: v || null })
    });
}

async function del() {
    if (!confirm('Delete this meeting? This cannot be undone.')) return;
    const r = await fetch('/jupiter/ginger-sync/api/meetings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
        body: JSON.stringify({ action: 'delete', id: MEETING_ID })
    }).then(r => r.json());
    if (r.ok) window.location.href = '/jupiter/ginger-sync/pages/meetings.php';
    else alert(r.error || 'delete failed');
}

async function retry() {
    await fetch('/jupiter/ginger-sync/api/meetings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
        body: JSON.stringify({ action: 'retry', id: MEETING_ID })
    });
    window.location.reload();
}

// Auto-drive the pipeline if still processing
if (IS_PROCESSING && MEETING_ID) {
    (async function poll() {
        for (let i = 0; i < 80; i++) {
            await new Promise(r => setTimeout(r, 4000));
            const r = await fetch('/jupiter/ginger-sync/api/meetings-process.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
                body: JSON.stringify({ id: MEETING_ID })
            }).then(r => r.json()).catch(() => ({}));
            if (r.status === 'ready' || r.status === 'audio_only' || r.status === 'error') {
                window.location.reload();
                return;
            }
            if (r.status) {
                const labels = { uploaded: 'Queued', transcribing: 'Transcribing…', analyzing: 'Analysing…' };
                const procMsg = document.getElementById('proc-msg');
                if (procMsg) procMsg.innerHTML = 'Status: <strong>' + (labels[r.status] || r.status) + '</strong>. This page will refresh automatically.';
            }
        }
    })();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
