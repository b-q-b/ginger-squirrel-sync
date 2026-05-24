<?php
$pageTitle = 'Hot Plate';
require_once __DIR__ . '/../includes/header.php';
$csrf = csrfToken();
?>
<style>
.hp-page { max-width: 1200px; }
.hp-toolbar {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 18px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 16px;
}
.hp-toolbar .filters { display: flex; gap: 6px; flex-wrap: wrap; flex: 1; }
.hp-toolbar .cat-btn {
    padding: 5px 12px; border-radius: 999px; border: 1px solid var(--border);
    background: var(--bg); color: var(--muted); cursor: pointer;
    font-size: 12px; font-weight: 500;
}
.hp-toolbar .cat-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }
.hp-toolbar .btn {
    padding: 7px 14px; border-radius: 8px; border: 1px solid var(--accent);
    background: var(--accent); color: #fff; cursor: pointer;
    font-size: 13px; font-weight: 500;
}
.hp-toolbar .btn.secondary { background: transparent; color: var(--accent); }
.hp-toolbar .btn:hover { background: var(--accent-hover); border-color: var(--accent-hover); }
.hp-toolbar .btn.secondary:hover { background: var(--accent-soft); }

.hp-board {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
@media (max-width: 1020px) { .hp-board { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px)  { .hp-board { grid-template-columns: 1fr; } }

.hp-col {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 10px;
    min-height: 200px;
    display: flex; flex-direction: column; gap: 8px;
}
.hp-col.drag-over { background: var(--accent-soft); }
.hp-col h3 {
    font-size: 12px; font-weight: 600; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.5px;
    padding: 0 4px 6px; display: flex; justify-content: space-between; align-items: baseline;
    border-bottom: 1px solid var(--border);
}
.hp-col h3 .count {
    background: var(--bg); border: 1px solid var(--border);
    color: var(--text); padding: 1px 7px; border-radius: 999px;
    font-size: 11px;
}

.hp-card {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    cursor: grab;
    transition: box-shadow 0.15s, transform 0.15s;
}
.hp-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.hp-card.dragging { opacity: 0.4; transform: rotate(2deg); }
.hp-card .title { font-weight: 600; font-size: 13.5px; margin-bottom: 4px; }
.hp-card .desc {
    font-size: 12px; color: var(--muted); line-height: 1.4;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    margin-bottom: 6px;
}
.hp-card .meta { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; font-size: 11px; }

.hp-tag {
    padding: 1px 8px; border-radius: 999px; font-weight: 500; font-size: 10.5px;
}
.hp-tag.prio-1 { background: #f1f5f9; color: #475569; }
.hp-tag.prio-2 { background: #dbeafe; color: #1e40af; }
.hp-tag.prio-3 { background: #fef3c7; color: #92400e; }
.hp-tag.prio-4 { background: #fee2e2; color: #991b1b; }

.hp-tag.cat-blue   { background: #dbeafe; color: #1e40af; }
.hp-tag.cat-green  { background: #dcfce7; color: #166534; }
.hp-tag.cat-purple { background: #f3e8ff; color: #6b21a8; }
.hp-tag.cat-orange { background: #ffedd5; color: #9a3412; }
.hp-tag.cat-amber  { background: #fef3c7; color: #92400e; }
.hp-tag.cat-red    { background: #fee2e2; color: #991b1b; }
.hp-tag.cat-pink   { background: #fce7f3; color: #9d174d; }
.hp-tag.cat-cyan   { background: #cffafe; color: #155e75; }

.hp-due { color: var(--muted); }
.hp-due.overdue { color: var(--danger); font-weight: 600; }
.hp-due.today   { color: var(--warn); font-weight: 600; }

/* Modal */
.modal-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,0.4);
    z-index: 100; display: none; align-items: center; justify-content: center;
}
.modal-backdrop.open { display: flex; }
.modal {
    background: var(--surface); border-radius: 14px;
    width: 92%; max-width: 540px; max-height: 90vh; overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.modal h2 { font-size: 18px; padding: 18px 22px; border-bottom: 1px solid var(--border); }
.modal .body { padding: 18px 22px; }
.modal .field { margin-bottom: 14px; }
.modal .field label {
    display: block; font-size: 11px; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; font-weight: 500;
}
.modal .field input[type=text],
.modal .field textarea,
.modal .field select,
.modal .field input[type=date] {
    width: 100%; padding: 8px 12px; border: 1px solid var(--border);
    border-radius: 8px; background: var(--bg); font-size: 14px;
    font-family: inherit;
}
.modal .field textarea { resize: vertical; min-height: 60px; }
.modal .field input:focus, .modal .field textarea:focus, .modal .field select:focus {
    outline: 2px solid var(--accent); outline-offset: -1px;
}
.modal .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.modal .pill-row { display: flex; gap: 6px; flex-wrap: wrap; }
.modal .pill-row .pill {
    padding: 6px 12px; border-radius: 8px; border: 1px solid var(--border);
    background: var(--bg); cursor: pointer; font-size: 12px; font-weight: 500;
}
.modal .pill-row .pill.active {
    background: var(--accent); border-color: var(--accent); color: #fff;
}
.modal .footer {
    display: flex; justify-content: space-between; align-items: center;
    padding: 14px 22px; border-top: 1px solid var(--border);
}
.modal .footer .btn-danger {
    background: transparent; color: var(--danger);
    border: 1px solid var(--border); padding: 7px 12px; border-radius: 8px;
    cursor: pointer; font-size: 13px;
}
.modal .footer .btn-danger:hover { background: #fef2f2; border-color: var(--danger); }
.modal .footer .actions { display: flex; gap: 10px; }
.modal .footer .btn {
    padding: 8px 16px; border-radius: 8px; border: 1px solid var(--accent);
    background: var(--accent); color: #fff; cursor: pointer; font-size: 13px; font-weight: 500;
}
.modal .footer .btn.secondary { background: transparent; color: var(--muted); border-color: var(--border); }

.empty-col { color: var(--muted); font-size: 12px; text-align: center; padding: 20px; font-style: italic; }

.cat-row {
    display: flex; align-items: center; gap: 10px; padding: 8px 0;
    border-bottom: 1px solid var(--border);
}
.cat-row:last-child { border-bottom: 0; }
.cat-row input[type=text] { flex: 1; padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg); font-size: 13px; }
.cat-row select { padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg); font-size: 13px; }
.cat-row .x { background: transparent; border: 0; cursor: pointer; color: var(--muted); padding: 4px 8px; }
.cat-row .x:hover { color: var(--danger); }
.cat-add { padding: 6px 12px; background: var(--accent-soft); border: 1px dashed var(--accent); color: var(--accent); border-radius: 8px; font-size: 12px; cursor: pointer; margin-top: 8px; }
</style>

<div class="hp-page">
    <div class="hp-toolbar">
        <div class="filters" id="cat-filters">
            <button class="cat-btn active" data-cat="">All</button>
        </div>
        <button class="btn secondary" onclick="openCategoryModal()">Categories</button>
        <button class="btn" onclick="openItemModal()">+ New task</button>
    </div>

    <div class="hp-board" id="board">
        <div class="hp-col" data-col="todo"><h3>To do <span class="count" id="count-todo">0</span></h3></div>
        <div class="hp-col" data-col="in_progress"><h3>In progress <span class="count" id="count-in_progress">0</span></h3></div>
        <div class="hp-col" data-col="waiting"><h3>Waiting <span class="count" id="count-waiting">0</span></h3></div>
        <div class="hp-col" data-col="done"><h3>Done <span class="count" id="count-done">0</span></h3></div>
    </div>
</div>

<!-- Item Modal -->
<div class="modal-backdrop" id="item-modal" onclick="if(event.target===this)closeItemModal()">
    <div class="modal">
        <h2 id="item-modal-title">New task</h2>
        <div class="body">
            <input type="hidden" id="item-id" value="">
            <div class="field"><label>Title</label><input type="text" id="item-title" autofocus></div>
            <div class="field"><label>Description</label><textarea id="item-description"></textarea></div>
            <div class="grid">
                <div class="field">
                    <label>Column</label>
                    <select id="item-column">
                        <option value="todo">To do</option>
                        <option value="in_progress">In progress</option>
                        <option value="waiting">Waiting</option>
                        <option value="done">Done</option>
                    </select>
                </div>
                <div class="field">
                    <label>Category</label>
                    <select id="item-category"><option value="">None</option></select>
                </div>
                <div class="field">
                    <label>Priority</label>
                    <div class="pill-row" id="prio-pills">
                        <button class="pill" data-prio="1">Low</button>
                        <button class="pill active" data-prio="2">Medium</button>
                        <button class="pill" data-prio="3">High</button>
                        <button class="pill" data-prio="4">Critical</button>
                    </div>
                </div>
                <div class="field"><label>Due date</label><input type="date" id="item-due"></div>
                <div class="field" style="grid-column: span 2;">
                    <label>Energy required</label>
                    <div class="pill-row" id="energy-pills">
                        <button class="pill active" data-energy="">Any</button>
                        <button class="pill" data-energy="quick">Quick</button>
                        <button class="pill" data-energy="social">Social</button>
                        <button class="pill" data-energy="deep">Deep</button>
                        <button class="pill" data-energy="creative">Creative</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="footer">
            <button class="btn-danger" id="item-delete" onclick="deleteItem()" style="display:none;">Delete</button>
            <div class="actions">
                <button class="btn secondary" onclick="closeItemModal()">Cancel</button>
                <button class="btn" onclick="saveItem()">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Category Modal -->
<div class="modal-backdrop" id="cat-modal" onclick="if(event.target===this)closeCategoryModal()">
    <div class="modal">
        <h2>Categories</h2>
        <div class="body" id="cat-list"></div>
        <div class="footer">
            <div></div>
            <div class="actions">
                <button class="btn" onclick="closeCategoryModal()">Done</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';
const COLORS = ['blue','green','purple','orange','amber','red','pink','cyan'];
let STATE = { items: [], categories: [], filter: '', currentPrio: 2, currentEnergy: '' };

async function load() {
    const r = await fetch('/jupiter/ginger-sync/api/hot-plate.php');
    const j = await r.json();
    if (!j.ok) return alert('Failed to load: ' + (j.error || ''));
    STATE.items = j.items;
    STATE.categories = j.categories;
    renderFilters();
    renderBoard();
}

function renderFilters() {
    const root = document.getElementById('cat-filters');
    const buttons = ['<button class="cat-btn ' + (STATE.filter === '' ? 'active' : '') + '" data-cat="">All</button>'];
    for (const c of STATE.categories) {
        buttons.push(`<button class="cat-btn ${STATE.filter===c.id?'active':''}" data-cat="${c.id}">${escapeHtml(c.name)}</button>`);
    }
    root.innerHTML = buttons.join('');
    root.querySelectorAll('.cat-btn').forEach(b => {
        b.onclick = () => { STATE.filter = b.dataset.cat; renderFilters(); renderBoard(); };
    });
}

function renderBoard() {
    const cols = { todo: [], in_progress: [], waiting: [], done: [] };
    const filtered = STATE.filter ? STATE.items.filter(i => i.category_id === STATE.filter) : STATE.items;
    filtered.sort((a, b) => (a.position||0) - (b.position||0));
    for (const i of filtered) (cols[i.column_key] || cols.todo).push(i);

    for (const col of Object.keys(cols)) {
        const el = document.querySelector('[data-col="' + col + '"]');
        document.getElementById('count-' + col).textContent = cols[col].length;
        // Reset (keep heading)
        const heading = el.querySelector('h3');
        el.innerHTML = '';
        el.appendChild(heading);

        if (cols[col].length === 0) {
            const empty = document.createElement('div');
            empty.className = 'empty-col';
            empty.textContent = '—';
            el.appendChild(empty);
        }
        for (const item of cols[col]) el.appendChild(renderCard(item));
    }

    // Wire column drop targets
    document.querySelectorAll('.hp-col').forEach(col => {
        col.ondragover = (e) => { e.preventDefault(); col.classList.add('drag-over'); };
        col.ondragleave = () => col.classList.remove('drag-over');
        col.ondrop = (e) => {
            e.preventDefault();
            col.classList.remove('drag-over');
            const id = e.dataTransfer.getData('text/plain');
            if (id) moveItemToColumn(id, col.dataset.col);
        };
    });
}

function renderCard(item) {
    const cat = STATE.categories.find(c => c.id === item.category_id);
    const due = item.due_date ? parseDateLocal(item.due_date) : null;
    const today = new Date(); today.setHours(0,0,0,0);
    const dueStatus = due ? (due < today ? 'overdue' : (due.toDateString() === today.toDateString() ? 'today' : '')) : '';
    const dueStr = due ? due.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '';
    const prioLabel = ['', 'Low', 'Med', 'High', 'Crit'][item.priority] || '';

    const div = document.createElement('div');
    div.className = 'hp-card';
    div.draggable = true;
    div.dataset.id = item.id;
    div.ondragstart = (e) => { e.dataTransfer.setData('text/plain', item.id); div.classList.add('dragging'); };
    div.ondragend = () => div.classList.remove('dragging');
    div.onclick = () => openItemModal(item);

    div.innerHTML = `
        <div class="title">${escapeHtml(item.title)}</div>
        ${item.description ? `<div class="desc">${escapeHtml(item.description)}</div>` : ''}
        <div class="meta">
            <span class="hp-tag prio-${item.priority}">${prioLabel}</span>
            ${cat ? `<span class="hp-tag cat-${cat.color}">${escapeHtml(cat.name)}</span>` : ''}
            ${due ? `<span class="hp-due ${dueStatus}">${dueStr}</span>` : ''}
            ${item.energy_level ? `<span class="hp-tag" style="background:var(--bg);color:var(--muted);">${item.energy_level}</span>` : ''}
        </div>
    `;
    return div;
}

async function moveItemToColumn(id, col) {
    const item = STATE.items.find(i => i.id === id);
    if (!item || item.column_key === col) return;
    item.column_key = col;
    // Optimistic local update
    renderBoard();
    await postAction({ action: 'update', id, column_key: col });
}

// ===== Item modal =====
function openItemModal(item) {
    const m = document.getElementById('item-modal');
    document.getElementById('item-id').value = item ? item.id : '';
    document.getElementById('item-modal-title').textContent = item ? 'Edit task' : 'New task';
    document.getElementById('item-title').value = item ? (item.title || '') : '';
    document.getElementById('item-description').value = item ? (item.description || '') : '';
    document.getElementById('item-column').value = item ? item.column_key : 'todo';
    document.getElementById('item-due').value = item ? (item.due_date || '') : '';

    // Categories dropdown
    const catSel = document.getElementById('item-category');
    catSel.innerHTML = '<option value="">None</option>' + STATE.categories.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
    catSel.value = item ? (item.category_id || '') : '';

    // Priority pills
    STATE.currentPrio = item ? (item.priority || 2) : 2;
    document.querySelectorAll('#prio-pills .pill').forEach(p => {
        p.classList.toggle('active', parseInt(p.dataset.prio) === STATE.currentPrio);
        p.onclick = () => {
            STATE.currentPrio = parseInt(p.dataset.prio);
            document.querySelectorAll('#prio-pills .pill').forEach(q => q.classList.toggle('active', parseInt(q.dataset.prio) === STATE.currentPrio));
        };
    });

    // Energy pills
    STATE.currentEnergy = item ? (item.energy_level || '') : '';
    document.querySelectorAll('#energy-pills .pill').forEach(p => {
        p.classList.toggle('active', p.dataset.energy === STATE.currentEnergy);
        p.onclick = () => {
            STATE.currentEnergy = p.dataset.energy;
            document.querySelectorAll('#energy-pills .pill').forEach(q => q.classList.toggle('active', q.dataset.energy === STATE.currentEnergy));
        };
    });

    document.getElementById('item-delete').style.display = item ? 'inline-block' : 'none';
    m.classList.add('open');
}
function closeItemModal() { document.getElementById('item-modal').classList.remove('open'); }

async function saveItem() {
    const id = document.getElementById('item-id').value;
    const body = {
        action: id ? 'update' : 'create',
        title: document.getElementById('item-title').value.trim(),
        description: document.getElementById('item-description').value,
        column_key: document.getElementById('item-column').value,
        priority: STATE.currentPrio,
        due_date: document.getElementById('item-due').value || null,
        category_id: document.getElementById('item-category').value || null,
        energy_level: STATE.currentEnergy || null,
    };
    if (id) body.id = id;
    if (!body.title) return alert('Title is required');

    const j = await postAction(body);
    if (j.ok) { closeItemModal(); await load(); }
    else alert(j.error || 'Save failed');
}

async function deleteItem() {
    const id = document.getElementById('item-id').value;
    if (!id) return;
    if (!confirm('Delete this task?')) return;
    const j = await postAction({ action: 'delete', id });
    if (j.ok) { closeItemModal(); await load(); }
    else alert(j.error || 'Delete failed');
}

// ===== Category modal =====
function openCategoryModal() {
    renderCategoryList();
    document.getElementById('cat-modal').classList.add('open');
}
function closeCategoryModal() {
    document.getElementById('cat-modal').classList.remove('open');
    load();
}
function renderCategoryList() {
    const root = document.getElementById('cat-list');
    root.innerHTML = STATE.categories.map(c => `
        <div class="cat-row" data-id="${c.id}">
            <input type="text" value="${escapeAttr(c.name)}" onblur="updateCategory('${c.id}', this.value, null)">
            <select onchange="updateCategory('${c.id}', null, this.value)">
                ${COLORS.map(col => `<option value="${col}" ${col===c.color?'selected':''}>${col}</option>`).join('')}
            </select>
            <button class="x" onclick="deleteCategory('${c.id}', '${escapeAttr(c.name)}')">✕</button>
        </div>
    `).join('') + '<div class="cat-add" onclick="addCategory()">+ New category</div>';
}
async function addCategory() {
    const name = prompt('Category name:');
    if (!name) return;
    const j = await postAction({ action: 'category_create', name, color: 'blue' });
    if (j.ok) { await load(); renderCategoryList(); }
    else alert(j.error);
}
async function updateCategory(id, name, color) {
    const patch = { action: 'category_update', id };
    if (name !== null) patch.name = name;
    if (color !== null) patch.color = color;
    const j = await postAction(patch);
    if (j.ok) await load();
}
async function deleteCategory(id, name) {
    if (!confirm(`Delete category "${name}"? Tasks in this category will be uncategorized.`)) return;
    const j = await postAction({ action: 'category_delete', id });
    if (j.ok) { await load(); renderCategoryList(); }
    else alert(j.error);
}

// ===== Utils =====
async function postAction(body) {
    const r = await fetch('/jupiter/ginger-sync/api/hot-plate.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
        body: JSON.stringify(body),
    });
    return r.json();
}
function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function escapeAttr(s) { return escapeHtml(s); }
function parseDateLocal(yyyy_mm_dd) {
    const [y,m,d] = String(yyyy_mm_dd).split('-').map(Number);
    return new Date(y, (m||1)-1, d||1);
}

load();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
