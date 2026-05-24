<?php
$pageTitle = 'Mappings';
require_once __DIR__ . '/../includes/header.php';
$csrf = csrfToken();
?>
<style>
.map-page { max-width: 980px; }
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 22px 26px;
    margin-bottom: 18px;
}
.card h2 { font-size: 16px; font-weight: 600; margin-bottom: 6px; }
.card .sub { color: var(--muted); font-size: 13px; margin-bottom: 16px; }

.fields { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; margin-bottom: 16px; }
.fields .full { grid-column: span 2; }
.fields label {
    font-size: 12px; font-weight: 500; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.5px;
    display: block; margin-bottom: 4px;
}
.fields select, .fields input[type=text] {
    width: 100%; padding: 9px 12px;
    border: 1px solid var(--border); border-radius: 8px;
    background: var(--bg); font-size: 14px;
    appearance: none;
    -webkit-appearance: none;
}
.fields select:focus, .fields input:focus { outline: 2px solid var(--accent); outline-offset: -1px; }
.fields select:disabled { opacity: 0.5; }

.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border-radius: 8px; border: 1px solid var(--accent);
    background: var(--accent); color: #fff; cursor: pointer;
    font-size: 13px; font-weight: 500;
    text-decoration: none;
}
.btn:hover { background: var(--accent-hover); border-color: var(--accent-hover); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn.secondary { background: transparent; color: var(--accent); }
.btn.secondary:hover { background: var(--accent-soft); }
.btn.danger { background: transparent; color: var(--danger); border-color: var(--border); }
.btn.danger:hover { background: #fef2f2; border-color: var(--danger); }

.status-map-block {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 14px;
    margin-top: 4px;
}
.status-map-block h4 {
    font-size: 12px; font-weight: 600; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;
}
.status-map-row {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 10px;
    padding: 4px 0;
}
.status-map-row .arrow { color: var(--accent); font-weight: 600; }
.status-map-row .trello {
    font-size: 13px; padding: 6px 10px;
    background: var(--accent-soft); color: var(--accent-hover);
    border-radius: 6px; font-weight: 500;
}
.status-map-row select { padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; background: var(--surface); }

.list-table {
    width: 100%; border-collapse: collapse;
    background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden;
}
.list-table th, .list-table td { padding: 12px 14px; text-align: left; font-size: 13px; }
.list-table th { background: var(--bg); color: var(--muted); font-weight: 500; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
.list-table tr { border-top: 1px solid var(--border); }
.list-table tr:first-child { border-top: 0; }
.list-table .label { font-weight: 600; color: var(--text); }
.list-table .pair { color: var(--muted); font-family: ui-monospace, monospace; font-size: 12px; }
.list-table .actions { text-align: right; white-space: nowrap; }
.list-table .pill {
    padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 500;
}
.pill.ok { background: #ecfdf5; color: #166534; }
.pill.off { background: #f3f4f6; color: var(--muted); }

.flash { padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; display: none; }
.flash.ok { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; display: block; }
.flash.bad { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; display: block; }
.empty { text-align: center; padding: 30px; color: var(--muted); }
</style>

<div class="map-page">
    <div id="flash" class="flash"></div>

    <div class="card" id="form-card">
        <h2 id="form-title">Add a mapping</h2>
        <p class="sub" id="form-sub">Pair a Trello board (and optional list) with a ClickUp list. Tasks created on either side will sync to the other.</p>

        <form id="add-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="editing_id" id="editing_id" value="">
            <div class="fields">
                <div class="full">
                    <label for="label">Label</label>
                    <input type="text" name="label" id="label" required placeholder="e.g. Marketing campaigns">
                </div>

                <div>
                    <label for="trello_board">Trello board</label>
                    <select name="trello_board_id" id="trello_board" required>
                        <option value="">Loading…</option>
                    </select>
                </div>

                <div>
                    <label for="trello_list">Trello list <span style="text-transform:none;color:var(--muted);font-weight:400;">(optional — leave blank to sync the whole board)</span></label>
                    <select name="trello_list_id" id="trello_list" disabled>
                        <option value="">— whole board —</option>
                    </select>
                </div>

                <div class="full">
                    <label for="clickup_list">ClickUp list</label>
                    <select name="clickup_list_id" id="clickup_list" required>
                        <option value="">Loading…</option>
                    </select>
                </div>
            </div>

            <div id="status-map-section" style="display:none;">
                <div class="status-map-block">
                    <h4>Status mapping (optional)</h4>
                    <p style="font-size:12px;color:var(--muted);margin-bottom:10px;">Map each Trello list to a ClickUp status. Cards moved between Trello lists will get the matching ClickUp status applied.</p>
                    <div id="status-rows"></div>
                </div>
            </div>

            <div style="margin-top:18px;display:flex;gap:10px;align-items:center;">
                <button type="submit" class="btn" id="submit-btn">Save mapping</button>
                <button type="button" class="btn secondary" id="cancel-btn" onclick="cancelEdit()" style="display:none;">Cancel</button>
                <label style="font-size:13px;color:var(--muted);">
                    <input type="checkbox" name="is_active" id="is_active" checked> Active
                </label>
            </div>
        </form>
    </div>

    <div>
        <h2 style="font-size:16px;font-weight:600;margin-bottom:10px;">Existing mappings</h2>
        <div id="mappings-list"></div>
    </div>
</div>

<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';
const flash = document.getElementById('flash');
function showFlash(msg, ok) {
    flash.className = 'flash ' + (ok ? 'ok' : 'bad');
    flash.textContent = (ok ? '✓ ' : '✗ ') + msg;
    setTimeout(() => { flash.className = 'flash'; flash.textContent = ''; }, 4000);
}

let trelloBoards = [];
let clickupLists = [];
let currentTrelloLists = [];

async function loadAll() {
    const [boards, tree, mappings] = await Promise.all([
        fetch('/jupiter/ginger-sync/api/trello-boards.php').then(r => r.json()),
        fetch('/jupiter/ginger-sync/api/clickup-tree.php').then(r => r.json()),
        fetch('/jupiter/ginger-sync/api/mappings.php').then(r => r.json()),
    ]);

    const trelloSel = document.getElementById('trello_board');
    if (boards.ok && boards.boards.length) {
        trelloBoards = boards.boards;
        trelloSel.innerHTML = '<option value="">— select —</option>' +
            boards.boards.map(b => `<option value="${b.id}">${escapeHtml(b.name)}</option>`).join('');
    } else {
        trelloSel.innerHTML = '<option value="">' + (boards.error || 'No boards found') + '</option>';
    }

    const cuSel = document.getElementById('clickup_list');
    if (tree.ok && tree.lists.length) {
        clickupLists = tree.lists;
        cuSel.innerHTML = '<option value="">— select —</option>' +
            tree.lists.map(l => `<option value="${l.list_id}" data-space="${l.space_id}" data-statuses='${escapeAttr(JSON.stringify(l.statuses))}'>${escapeHtml(l.label)}</option>`).join('');
    } else {
        cuSel.innerHTML = '<option value="">' + (tree.error || 'No lists found') + '</option>';
    }

    renderMappings(mappings.data || []);
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function escapeAttr(s) {
    return String(s).replace(/'/g, '&#39;').replace(/"/g, '&quot;');
}

document.getElementById('trello_board').addEventListener('change', async function() {
    await loadTrelloListsForBoard(this.value, '');
    rebuildStatusRows();
});

document.getElementById('clickup_list').addEventListener('change', rebuildStatusRows);

function rebuildStatusRows() {
    const cuSel = document.getElementById('clickup_list');
    const opt = cuSel.options[cuSel.selectedIndex];
    const statuses = opt && opt.dataset.statuses ? JSON.parse(opt.dataset.statuses) : [];
    const trelloLists = currentTrelloLists || [];

    const section = document.getElementById('status-map-section');
    const rows = document.getElementById('status-rows');

    if (!statuses.length || !trelloLists.length) {
        section.style.display = 'none';
        rows.innerHTML = '';
        return;
    }
    section.style.display = 'block';
    rows.innerHTML = trelloLists.map(tl => `
        <div class="status-map-row">
            <span class="trello">${escapeHtml(tl.name)}</span>
            <span class="arrow">→</span>
            <select data-trello-list="${tl.id}" data-trello-name="${escapeAttr(tl.name)}">
                <option value="">— don't map —</option>
                ${statuses.map(s => `<option value="${escapeAttr(s.status)}">${escapeHtml(s.status)}</option>`).join('')}
            </select>
        </div>
    `).join('');
}

document.getElementById('add-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    const editingId = (fd.get('editing_id') || '').toString();

    const status_map = {};
    document.querySelectorAll('#status-rows select').forEach(sel => {
        const v = sel.value;
        if (v) status_map[sel.dataset.trelloName] = v;
    });

    const cuSel = document.getElementById('clickup_list');
    const cuOpt = cuSel.options[cuSel.selectedIndex];
    const space_id = cuOpt ? cuOpt.dataset.space : '';

    const body = {
        label: fd.get('label'),
        trello_board_id: fd.get('trello_board_id'),
        trello_list_id: fd.get('trello_list_id') || null,
        clickup_space_id: space_id,
        clickup_list_id: fd.get('clickup_list_id'),
        status_map: status_map,
        is_active: !!fd.get('is_active'),
    };
    if (editingId) body.id = editingId;

    try {
        const r = await fetch('/jupiter/ginger-sync/api/mappings.php', {
            method: editingId ? 'PATCH' : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
            body: JSON.stringify(body),
        });
        const j = await r.json();
        if (j.ok) {
            showFlash(editingId ? 'Mapping updated' : 'Mapping added', true);
            cancelEdit();
            loadMappings();
        } else {
            showFlash(j.error || 'Save failed', false);
        }
    } catch (e) {
        showFlash(e.message, false);
    }
});

let allMappings = [];

async function editMapping(id) {
    const m = allMappings.find(x => x.id === id);
    if (!m) { showFlash('Mapping not found', false); return; }

    document.getElementById('form-title').textContent = 'Edit mapping';
    document.getElementById('form-sub').textContent = 'Update fields below, then click Update mapping.';
    document.getElementById('submit-btn').textContent = 'Update mapping';
    document.getElementById('cancel-btn').style.display = 'inline-flex';
    document.getElementById('editing_id').value = id;

    document.getElementById('label').value = m.label || '';
    document.getElementById('is_active').checked = !!m.is_active;
    document.getElementById('trello_board').value = m.trello_board_id || '';
    document.getElementById('clickup_list').value = m.clickup_list_id || '';

    // Trigger Trello list load for the board, then prefill the list selection
    await loadTrelloListsForBoard(m.trello_board_id || '', m.trello_list_id || '');

    // Rebuild status rows now that ClickUp list is selected (its statuses are in the option dataset)
    rebuildStatusRows();

    // Prefill status_map values
    const map = m.status_map || {};
    document.querySelectorAll('#status-rows select').forEach(sel => {
        const tname = sel.dataset.trelloName;
        if (tname && map[tname]) sel.value = map[tname];
    });

    // Scroll the form into view
    document.getElementById('form-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function cancelEdit() {
    const form = document.getElementById('add-form');
    form.reset();
    form.querySelector('[name=csrf]').value = CSRF;
    document.getElementById('editing_id').value = '';
    document.getElementById('form-title').textContent = 'Add a mapping';
    document.getElementById('form-sub').textContent = 'Pair a Trello board (and optional list) with a ClickUp list. Tasks created on either side will sync to the other.';
    document.getElementById('submit-btn').textContent = 'Save mapping';
    document.getElementById('cancel-btn').style.display = 'none';
    document.getElementById('trello_list').disabled = true;
    document.getElementById('status-map-section').style.display = 'none';
    document.getElementById('is_active').checked = true;
}

async function loadTrelloListsForBoard(boardId, preselectListId) {
    const listSel = document.getElementById('trello_list');
    listSel.innerHTML = '<option value="">— whole board —</option>';
    listSel.disabled = !boardId;
    if (!boardId) { currentTrelloLists = []; return; }
    try {
        const r = await fetch('/jupiter/ginger-sync/api/trello-lists.php?board=' + encodeURIComponent(boardId));
        const j = await r.json();
        if (j.ok) {
            currentTrelloLists = j.lists;
            listSel.innerHTML = '<option value="">— whole board —</option>' +
                j.lists.map(l => `<option value="${l.id}">${escapeHtml(l.name)}</option>`).join('');
            listSel.disabled = false;
            if (preselectListId) listSel.value = preselectListId;
        } else {
            listSel.innerHTML = '<option value="">' + (j.error || 'Failed') + '</option>';
        }
    } catch (e) {
        listSel.innerHTML = '<option value="">Error loading lists</option>';
    }
}

async function loadMappings() {
    const r = await fetch('/jupiter/ginger-sync/api/mappings.php');
    const j = await r.json();
    allMappings = j.data || [];
    renderMappings(allMappings);
}

function renderMappings(rows) {
    allMappings = rows;
    const root = document.getElementById('mappings-list');
    if (!rows.length) {
        root.innerHTML = '<div class="card empty">No mappings yet. Add one above to start syncing.</div>';
        return;
    }
    root.innerHTML = `
        <table class="list-table">
            <thead><tr>
                <th>Label</th><th>Trello</th><th>ClickUp</th><th>Status</th><th class="actions"></th>
            </tr></thead>
            <tbody>
            ${rows.map(m => `
                <tr>
                    <td class="label">${escapeHtml(m.label || '')}</td>
                    <td class="pair">${escapeHtml((m.trello_board_id || '').slice(0, 8))}…${m.trello_list_id ? ' / list' : ' / whole board'}</td>
                    <td class="pair">${escapeHtml((m.clickup_list_id || '').slice(0, 10))}…</td>
                    <td><span class="pill ${m.is_active ? 'ok' : 'off'}">${m.is_active ? 'active' : 'paused'}</span></td>
                    <td class="actions">
                        <button class="btn secondary" onclick="syncNow('${m.id}', this)" ${m.is_active ? '' : 'disabled'}>Sync now</button>
                        <button class="btn secondary" onclick="editMapping('${m.id}')">Edit</button>
                        <button class="btn secondary" onclick="toggleActive('${m.id}', ${!m.is_active})">${m.is_active ? 'Pause' : 'Resume'}</button>
                        <button class="btn danger" onclick="deleteMapping('${m.id}', '${escapeAttr(m.label)}')">Delete</button>
                    </td>
                </tr>
            `).join('')}
            </tbody>
        </table>
    `;
}

async function toggleActive(id, next) {
    const r = await fetch('/jupiter/ginger-sync/api/mappings.php', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
        body: JSON.stringify({ id, is_active: next }),
    });
    const j = await r.json();
    if (j.ok) { showFlash(next ? 'Resumed' : 'Paused', true); loadMappings(); }
    else showFlash(j.error || 'Update failed', false);
}

async function syncNow(id, btn) {
    btn.disabled = true; btn.textContent = 'Syncing…';
    try {
        const r = await fetch('/jupiter/ginger-sync/api/manual-sync.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
            body: JSON.stringify({ mapping_id: id }),
        });
        const j = await r.json();
        if (j.ok) {
            const s = j.stats || {};
            const total = (s.trello_to_clickup||0) + (s.clickup_to_trello||0);
            showFlash(`Synced ${total} item(s) · ${s.skipped||0} skipped · ${s.errors||0} error(s)`, !(s.errors||0));
        } else {
            showFlash(j.error || 'Sync failed', false);
        }
    } catch (e) {
        showFlash(e.message, false);
    }
    btn.disabled = false; btn.textContent = 'Sync now';
}

async function deleteMapping(id, label) {
    if (!confirm(`Delete mapping "${label}"?\n\nThis will not delete tasks on either platform — only stop syncing them.`)) return;
    const r = await fetch('/jupiter/ginger-sync/api/mappings.php?id=' + encodeURIComponent(id), {
        method: 'DELETE',
        headers: { 'X-CSRF': CSRF },
    });
    const j = await r.json();
    if (j.ok) { showFlash('Deleted', true); loadMappings(); }
    else showFlash(j.error || 'Delete failed', false);
}

loadAll();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
