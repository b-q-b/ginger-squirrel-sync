<?php
$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/header.php';

$clickupSet = !empty($_ENV['CLICKUP_TOKEN']);
$trelloSet  = !empty($_ENV['TRELLO_KEY']) && !empty($_ENV['TRELLO_TOKEN']);
$webhookSet = !empty($_ENV['WEBHOOK_SECRET']);

// Sync mode detection
$db = new Supabase('service');
$wReg = $db->from('webhook_registrations', 'select=platform,status&status=eq.active');
$activeWebhooks = $wReg['data'] ?? [];
$wByPlatform = [];
foreach ($activeWebhooks as $w) $wByPlatform[(string)$w['platform']] = true;
$cronRecent = $db->from('sync_events', 'select=created_at&source=eq.reconcile_cron&order=created_at.desc&limit=1');
$cronLast = $cronRecent['data'][0]['created_at'] ?? null;
$cronAge = $cronLast ? (time() - strtotime($cronLast)) : null;
$cronAlive = $cronAge !== null && $cronAge < 900; // <15 min = healthy
$webhooksAlive = !empty($wByPlatform['clickup']) || !empty($wByPlatform['trello']);

$syncMode = $webhooksAlive
    ? 'realtime'
    : ($cronAlive ? 'polling' : 'idle');

function maskToken(string $t): string {
    if (strlen($t) < 8) return '••••';
    return substr($t, 0, 4) . str_repeat('•', 8) . substr($t, -4);
}

$baseUrl = $_ENV['PUBLIC_BASE_URL'] ?? 'https://bqbstudio.com/jupiter/ginger-sync';
$csrf = csrfToken();
?>
<style>
.settings-page { max-width: 820px; }
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px 28px;
    margin-bottom: 18px;
}
.card h2 {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.card .sub { color: var(--muted); font-size: 13px; margin-bottom: 18px; }
.row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 0;
    border-top: 1px solid var(--border);
}
.row:first-of-type { border-top: 0; }
.row .label { color: var(--muted); font-size: 13px; }
.row .val { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 13px; color: var(--text); }
.row .val.empty { color: var(--danger); font-style: italic; font-family: inherit; }
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 8px; border: 1px solid var(--accent);
    background: var(--accent); color: #fff; cursor: pointer;
    font-size: 13px; font-weight: 500;
    text-decoration: none;
}
.btn:hover { background: var(--accent-hover); border-color: var(--accent-hover); }
.btn.secondary { background: transparent; color: var(--accent); }
.btn.secondary:hover { background: var(--accent-soft); color: var(--accent-hover); }
.test-result { font-size: 13px; padding: 10px 12px; margin-top: 10px; border-radius: 8px; display: none; }
.test-result.ok { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; display: block; }
.test-result.bad { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; display: block; }
.kbd {
    font-family: ui-monospace, monospace;
    background: var(--bg);
    border: 1px solid var(--border);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
    color: var(--text);
    word-break: break-all;
}
.form-row { display: flex; gap: 10px; margin-top: 6px; }
.form-row input {
    flex: 1; padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px;
    font-size: 14px; background: var(--bg);
}
.form-row input:focus { outline: 2px solid var(--accent); outline-offset: -1px; }
.muted { color: var(--muted); font-size: 12px; }
.pill { padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 500; }
.pill.ok { background: #ecfdf5; color: #166534; }
.pill.bad { background: #fef2f2; color: #991b1b; }
.pill.warn-pill { background: #fef3c7; color: #92400e; }
.mode-card { border-left: 3px solid var(--border); }
.mode-card.mode-realtime { border-left-color: var(--ok); }
.mode-card.mode-polling { border-left-color: var(--warn); }
.mode-card.mode-idle { border-left-color: var(--danger); }
.mode-card .kbd { display: inline-block; margin: 0 4px; }
</style>
<div class="settings-page">

    <div class="card mode-card mode-<?= $syncMode ?>">
        <h2>Sync mode
            <?php if ($syncMode === 'realtime'): ?>
                <span class="pill ok">real-time</span>
            <?php elseif ($syncMode === 'polling'): ?>
                <span class="pill warn-pill">polling only</span>
            <?php else: ?>
                <span class="pill bad">idle</span>
            <?php endif; ?>
        </h2>
        <?php if ($syncMode === 'realtime'): ?>
            <p class="sub">Webhooks active on:
                <?php if (!empty($wByPlatform['clickup'])): ?><span class="kbd">ClickUp</span><?php endif; ?>
                <?php if (!empty($wByPlatform['trello'])): ?><span class="kbd">Trello</span><?php endif; ?>
                · changes propagate in seconds.
            </p>
        <?php elseif ($syncMode === 'polling'): ?>
            <p class="sub">No webhooks registered yet — running in <strong>polling-only mode</strong>. The cron job catches changes every 5 minutes; latency is &le; 5 min.</p>
            <p class="sub" style="margin-top:6px;">
                <small>This is normal if your account is a guest on the boards (ClickUp may block webhook registration for guest tokens). The platform works perfectly in this mode.</small>
            </p>
        <?php else: ?>
            <p class="sub">Neither webhooks nor cron are active. Add the cron line to your server, or click "Register webhooks" below.</p>
        <?php endif; ?>
        <?php if ($cronLast): ?>
            <p class="sub" style="margin-top:6px;">
                <small>Last cron run: <?= htmlspecialchars(date('M j, H:i', strtotime($cronLast))) ?> (<?= $cronAge < 60 ? "{$cronAge}s ago" : floor($cronAge/60) . 'm ago' ?>)</small>
            </p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>ClickUp <span class="pill <?= $clickupSet ? 'ok' : 'bad' ?>"><?= $clickupSet ? 'connected' : 'not configured' ?></span></h2>
        <p class="sub">Personal API token used to read your spaces, lists, and tasks.</p>
        <div class="row">
            <span class="label">Token</span>
            <span class="val<?= $clickupSet ? '' : ' empty' ?>"><?= $clickupSet ? maskToken($_ENV['CLICKUP_TOKEN']) : 'not set' ?></span>
        </div>
        <?php if ($clickupSet): ?>
        <div style="margin-top:14px;">
            <button class="btn secondary" onclick="testConn('clickup', this)">Test connection</button>
            <div class="test-result" id="result-clickup"></div>
        </div>
        <?php else: ?>
        <p class="muted" style="margin-top:14px;">Set <code>CLICKUP_TOKEN</code> in your <code>.env</code> on the server. Get a token at clickup.com → Apps → API.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Trello <span class="pill <?= $trelloSet ? 'ok' : 'bad' ?>"><?= $trelloSet ? 'connected' : 'not configured' ?></span></h2>
        <p class="sub">API key + user token used to read your boards, lists, and cards.</p>
        <div class="row">
            <span class="label">Key</span>
            <span class="val<?= !empty($_ENV['TRELLO_KEY']) ? '' : ' empty' ?>"><?= !empty($_ENV['TRELLO_KEY']) ? maskToken($_ENV['TRELLO_KEY']) : 'not set' ?></span>
        </div>
        <div class="row">
            <span class="label">Token</span>
            <span class="val<?= !empty($_ENV['TRELLO_TOKEN']) ? '' : ' empty' ?>"><?= !empty($_ENV['TRELLO_TOKEN']) ? maskToken($_ENV['TRELLO_TOKEN']) : 'not set' ?></span>
        </div>
        <?php if ($trelloSet): ?>
        <div style="margin-top:14px;">
            <button class="btn secondary" onclick="testConn('trello', this)">Test connection</button>
            <div class="test-result" id="result-trello"></div>
        </div>
        <?php else: ?>
        <p class="muted" style="margin-top:14px;">Set <code>TRELLO_KEY</code> and <code>TRELLO_TOKEN</code> in your <code>.env</code>. Get the key at trello.com/power-ups/admin, then mint a user token via the authorize URL on that page.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Webhook endpoints</h2>
        <p class="sub">URLs that ClickUp and Trello will POST to when changes happen. We'll register these automatically once mappings are configured.</p>
        <div class="row">
            <span class="label">ClickUp receiver</span>
            <span class="kbd"><?= htmlspecialchars($baseUrl) ?>/api/webhook-clickup.php</span>
        </div>
        <div class="row">
            <span class="label">Trello receiver</span>
            <span class="kbd"><?= htmlspecialchars($baseUrl) ?>/api/webhook-trello.php</span>
        </div>
        <div class="row">
            <span class="label">Signing secret</span>
            <span class="val"><?= $webhookSet ? maskToken($_ENV['WEBHOOK_SECRET']) : 'not set' ?></span>
        </div>
        <div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button class="btn" onclick="registerWebhooks(this)">Register / refresh webhooks</button>
            <span class="muted">Run once after creating mappings, or whenever you add new boards/lists.</span>
        </div>
        <div class="test-result" id="result-webhooks"></div>
    </div>

    <div class="card">
        <h2>Change dashboard password</h2>
        <p class="sub">Initial password is <code>OpenShakra!</code> — change it now.</p>
        <form id="pw-form" onsubmit="return changePw(event)">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <div class="form-row">
                <input type="password" name="current" placeholder="Current password" required>
                <input type="password" name="next" placeholder="New password (8+ chars)" required>
                <button type="submit" class="btn">Update</button>
            </div>
            <div class="test-result" id="result-pw"></div>
        </form>
    </div>

</div>

<script>
async function testConn(platform, btn) {
    const out = document.getElementById('result-' + platform);
    btn.disabled = true; btn.textContent = 'Testing…';
    try {
        const r = await fetch('/jupiter/ginger-sync/api/test-connection.php?platform=' + platform);
        const j = await r.json();
        if (j.ok) {
            const u = j.user || {};
            const detail = platform === 'clickup'
                ? `${u.username || u.email || u.id} · ${j.teams} workspace${j.teams === 1 ? '' : 's'}`
                : `${u.fullName || u.username || u.id} · ${j.boards} board${j.boards === 1 ? '' : 's'}`;
            out.className = 'test-result ok';
            out.textContent = '✓ Connected as ' + detail;
        } else {
            out.className = 'test-result bad';
            out.textContent = '✗ ' + (j.error || 'Connection failed');
        }
    } catch (e) {
        out.className = 'test-result bad';
        out.textContent = '✗ ' + e.message;
    }
    btn.disabled = false; btn.textContent = 'Test connection';
}

async function registerWebhooks(btn) {
    const out = document.getElementById('result-webhooks');
    btn.disabled = true; btn.textContent = 'Registering…';
    try {
        const r = await fetch('/jupiter/ginger-sync/api/register-webhooks.php', {
            method: 'POST',
            headers: { 'X-CSRF': '<?= htmlspecialchars($csrf) ?>' },
        });
        const j = await r.json();
        if (j.ok) {
            const summary = (j.results || []).map(x => `${x.platform} · ${x.status}${x.error ? ' (' + x.error + ')' : ''}`).join(' · ');
            out.className = 'test-result ok';
            out.textContent = '✓ ' + (summary || 'no mappings yet — create one first');
        } else {
            out.className = 'test-result bad';
            out.textContent = '✗ ' + (j.error || 'Registration failed');
        }
    } catch (e) {
        out.className = 'test-result bad';
        out.textContent = '✗ ' + e.message;
    }
    btn.disabled = false; btn.textContent = 'Register / refresh webhooks';
}

async function changePw(e) {
    e.preventDefault();
    const form = e.target;
    const out = document.getElementById('result-pw');
    const fd = new FormData(form);
    try {
        const r = await fetch('/jupiter/ginger-sync/api/change-password.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.ok) {
            out.className = 'test-result ok';
            out.textContent = '✓ Password updated';
            form.reset();
            // keep csrf token (was reset by form.reset)
            form.querySelector('[name=csrf]').value = '<?= htmlspecialchars($csrf) ?>';
        } else {
            out.className = 'test-result bad';
            out.textContent = '✗ ' + (j.error || 'Update failed');
        }
    } catch (e) {
        out.className = 'test-result bad';
        out.textContent = '✗ ' + e.message;
    }
    return false;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
