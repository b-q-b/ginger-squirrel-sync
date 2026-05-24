<?php
$pageTitle = 'Items';
require_once __DIR__ . '/../includes/header.php';

$db = new Supabase('service');
$mappings = $db->from('mappings', 'select=id,label,is_active&order=created_at.desc');
$mList = $mappings['data'] ?? [];
$selected = $_GET['mapping_id'] ?? '';
?>
<style>
.items-page { max-width: 1200px; }
.toolbar {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 18px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 18px;
}
.toolbar select {
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg);
    font-size: 14px;
    min-width: 220px;
}
.toolbar .label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
.toolbar .meta { margin-left: auto; font-size: 13px; color: var(--muted); }

.tabs {
    display: flex;
    gap: 0;
    border-bottom: 1px solid var(--border);
    margin-bottom: 16px;
}
.tab {
    padding: 10px 18px;
    border: 0;
    background: transparent;
    cursor: pointer;
    font-size: 13.5px;
    font-weight: 500;
    color: var(--muted);
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
}
.tab.active { color: var(--accent); border-bottom-color: var(--accent); }
.tab .count {
    background: var(--accent-soft);
    color: var(--accent-hover);
    padding: 1px 7px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 6px;
}
.tab.active .count { background: var(--accent); color: #fff; }

.items-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
}
.items-table th, .items-table td { padding: 11px 14px; text-align: left; font-size: 13px; vertical-align: top; }
.items-table th {
    background: var(--bg);
    color: var(--muted);
    font-weight: 500;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
}
.items-table tr { border-top: 1px solid var(--border); }
.items-table tr:first-child { border-top: 0; }
.items-table .name { font-weight: 500; }
.items-table .name a { color: var(--text); text-decoration: none; }
.items-table .name a:hover { color: var(--accent); }
.items-table .name.deleted { color: var(--muted); font-style: italic; }
.items-table .meta { font-size: 11px; color: var(--muted); font-family: ui-monospace, monospace; }
.items-table .arrow { font-family: ui-monospace, monospace; color: var(--accent); font-weight: 600; }

.platform-tag {
    padding: 1px 7px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.platform-tag.trello { background: #dbeafe; color: #1e40af; }
.platform-tag.clickup { background: #f3e8ff; color: #7c3aed; }

.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }
.dot.ok { background: var(--ok); }
.dot.warn { background: var(--warn); }
.dot.idle { background: var(--muted); }

.empty {
    padding: 30px;
    text-align: center;
    color: var(--muted);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
}
.empty small { font-size: 12px; }
.empty a { color: var(--accent); }

.btn-mini {
    padding: 5px 11px;
    border-radius: 6px;
    font-size: 12px;
    border: 1px solid var(--accent);
    background: transparent;
    color: var(--accent);
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}
.btn-mini:hover { background: var(--accent-soft); }

.refresh-btn {
    padding: 7px 14px;
    border-radius: 8px;
    background: var(--accent);
    color: #fff;
    border: 0;
    font-size: 13px;
    cursor: pointer;
}
.refresh-btn:disabled { opacity: 0.5; }
.refresh-btn:hover { background: var(--accent-hover); }

.loading { color: var(--muted); padding: 20px; text-align: center; font-size: 13px; }
.mapping-block { margin-bottom: 30px; }
.mapping-block h3 {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--text);
}
.mapping-block .label-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.mapping-block .pill {
    padding: 2px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 500;
    background: #ecfdf5;
    color: #166534;
}
.mapping-block .pill.off { background: #f3f4f6; color: var(--muted); }
</style>

<div class="items-page">

<div class="toolbar">
    <span class="label">Mapping</span>
    <select id="mapping-select" onchange="loadItems()">
        <option value="">All active mappings</option>
        <?php foreach ($mList as $m): ?>
            <option value="<?= htmlspecialchars($m['id']) ?>" <?= $selected === $m['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($m['label']) ?><?= empty($m['is_active']) ? ' (paused)' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button class="refresh-btn" onclick="loadItems(true)">↻ Refresh from APIs</button>
    <div class="meta" id="meta">Loading…</div>
</div>

<div id="content">
    <div class="loading">Loading items…</div>
</div>

</div>

<script>
let currentTab = 'synced';

async function loadItems(force) {
    const sel = document.getElementById('mapping-select');
    const meta = document.getElementById('meta');
    const content = document.getElementById('content');
    content.innerHTML = '<div class="loading">Fetching live data from Trello & ClickUp…</div>';
    meta.textContent = 'Loading…';

    const params = new URLSearchParams();
    if (sel.value) params.set('mapping_id', sel.value);
    if (force) params.set('live', '1');
    const url = '/jupiter/ginger-sync/api/items.php' + (params.toString() ? '?' + params.toString() : '');
    try {
        const r = await fetch(url, { cache: force ? 'reload' : 'default' });
        const j = await r.json();
        if (!j.ok) {
            content.innerHTML = `<div class="empty">✗ ${escapeHtml(j.error || 'Failed to load')}</div>`;
            meta.textContent = '';
            return;
        }
        if (!j.mappings.length) {
            content.innerHTML = `<div class="empty">No mappings yet. <a href="/jupiter/ginger-sync/pages/mappings.php">Create one</a> to start syncing.</div>`;
            meta.textContent = '';
            return;
        }

        // If a single mapping, show tabs. If "All", show stacked blocks.
        if (j.mappings.length === 1) {
            renderSingleMapping(j.mappings[0]);
        } else {
            renderAllMappings(j.mappings);
        }
        const totals = j.mappings.reduce((acc, m) => {
            acc.synced += m.counts.synced;
            acc.trello += m.counts.trello_only;
            acc.clickup += m.counts.clickup_only;
            return acc;
        }, { synced: 0, trello: 0, clickup: 0 });
        meta.textContent = `${totals.synced} synced · ${totals.trello} Trello-only · ${totals.clickup} ClickUp-only`;
    } catch (e) {
        content.innerHTML = `<div class="empty">✗ ${escapeHtml(e.message)}</div>`;
    }
}

function renderSingleMapping(m) {
    const c = m.counts;
    document.getElementById('content').innerHTML = `
        <div class="tabs">
            <button class="tab ${currentTab==='synced'?'active':''}" onclick="switchTab('synced')">Synced pairs<span class="count">${c.synced}</span></button>
            <button class="tab ${currentTab==='trello_only'?'active':''}" onclick="switchTab('trello_only')">Trello-only<span class="count">${c.trello_only}</span></button>
            <button class="tab ${currentTab==='clickup_only'?'active':''}" onclick="switchTab('clickup_only')">ClickUp-only<span class="count">${c.clickup_only}</span></button>
        </div>
        <div id="tab-content"></div>
    `;
    window._currentMapping = m;
    renderTab();
}

function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.textContent.toLowerCase().includes(tab.replace('_only', '-only').replace('_', ' '))));
    document.querySelectorAll('.tab').forEach((t, i) => {
        const tabs = ['synced', 'trello_only', 'clickup_only'];
        t.classList.toggle('active', tabs[i] === tab);
    });
    renderTab();
}

function renderTab() {
    const m = window._currentMapping;
    const root = document.getElementById('tab-content');
    if (currentTab === 'synced') {
        root.innerHTML = renderSyncedTable(m.synced);
    } else if (currentTab === 'trello_only') {
        root.innerHTML = renderSinglePlatformTable(m.trello_only, 'trello');
    } else {
        root.innerHTML = renderSinglePlatformTable(m.clickup_only, 'clickup');
    }
}

function renderSyncedTable(rows) {
    if (!rows.length) {
        return '<div class="empty">No synced pairs yet for this mapping.<br><small>Click <a href="/jupiter/ginger-sync/pages/mappings.php">Sync now</a> to populate.</small></div>';
    }
    return `
        <table class="items-table">
            <thead><tr>
                <th>Trello card</th>
                <th></th>
                <th>ClickUp task</th>
                <th>Last synced</th>
                <th>Direction</th>
            </tr></thead>
            <tbody>
                ${rows.map(r => {
                    const t = r.trello || {};
                    const c = r.clickup || {};
                    const ts = r.last_synced_at ? new Date(r.last_synced_at).toLocaleString() : '—';
                    const dir = r.last_direction === 'trello_to_clickup' ? 'T → CU'
                              : r.last_direction === 'clickup_to_trello' ? 'CU → T' : '—';
                    return `
                        <tr>
                            <td class="name ${t.name === '(deleted)' ? 'deleted' : ''}">
                                ${t.url ? `<a href="${escapeAttr(t.url)}" target="_blank">${escapeHtml(t.name||'—')}</a>` : escapeHtml(t.name||'—')}
                                ${t.id ? `<div class="meta">${escapeHtml((t.id||'').slice(0,12))}…</div>` : ''}
                            </td>
                            <td class="arrow">↔</td>
                            <td class="name ${c.name === '(deleted)' ? 'deleted' : ''}">
                                ${c.url ? `<a href="${escapeAttr(c.url)}" target="_blank">${escapeHtml(c.name||'—')}</a>` : escapeHtml(c.name||'—')}
                                ${c.status ? `<div class="meta">status: ${escapeHtml(c.status)}</div>` : ''}
                            </td>
                            <td class="meta">${escapeHtml(ts)}</td>
                            <td class="meta">${dir}</td>
                        </tr>
                    `;
                }).join('')}
            </tbody>
        </table>
    `;
}

function renderSinglePlatformTable(rows, platform) {
    if (!rows.length) {
        const other = platform === 'trello' ? 'ClickUp' : 'Trello';
        return `<div class="empty">All ${platform === 'trello' ? 'Trello cards' : 'ClickUp tasks'} are synced to ${other}. Nothing here. ✓</div>`;
    }
    return `
        <table class="items-table">
            <thead><tr>
                <th>Name</th>
                <th>${platform === 'trello' ? 'Due' : 'Status'}</th>
                <th>${platform === 'trello' ? 'Last activity' : 'Updated'}</th>
                <th>ID</th>
            </tr></thead>
            <tbody>
                ${rows.map(r => {
                    const due = platform === 'trello'
                        ? (r.due ? new Date(r.due).toLocaleDateString() : '—')
                        : (r.status || '—');
                    const upd = platform === 'trello'
                        ? (r.last_activity ? new Date(r.last_activity).toLocaleString() : '—')
                        : (r.date_updated ? new Date(parseInt(r.date_updated)).toLocaleString() : '—');
                    return `
                        <tr>
                            <td class="name">
                                <span class="platform-tag ${platform}">${platform}</span>
                                ${r.url ? `<a href="${escapeAttr(r.url)}" target="_blank">${escapeHtml(r.name||'—')}</a>` : escapeHtml(r.name||'—')}
                            </td>
                            <td>${escapeHtml(due)}</td>
                            <td class="meta">${escapeHtml(upd)}</td>
                            <td class="meta">${escapeHtml((r.id||'').slice(0,14))}…</td>
                        </tr>
                    `;
                }).join('')}
            </tbody>
        </table>
    `;
}

function renderAllMappings(mappings) {
    const root = document.getElementById('content');
    root.innerHTML = mappings.map(m => `
        <div class="mapping-block">
            <div class="label-row">
                <h3>${escapeHtml(m.mapping.label)}</h3>
                <span class="pill ${m.mapping.is_active ? '' : 'off'}">${m.mapping.is_active ? 'active' : 'paused'}</span>
                <span class="meta" style="font-size:12px;color:var(--muted);">
                    ${m.counts.synced} synced · ${m.counts.trello_only} Trello-only · ${m.counts.clickup_only} ClickUp-only
                </span>
                <a class="btn-mini" href="/jupiter/ginger-sync/pages/items.php?mapping_id=${m.mapping.id}">View →</a>
            </div>
            ${renderSyncedTable(m.synced.slice(0, 5))}
            ${m.synced.length > 5 ? `<div style="font-size:12px;color:var(--muted);margin-top:6px;">…and ${m.synced.length - 5} more synced item(s).</div>` : ''}
        </div>
    `).join('');
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function escapeAttr(s) { return escapeHtml(s); }

loadItems();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
