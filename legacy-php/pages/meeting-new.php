<?php
$pageTitle = 'New Meeting';
require_once __DIR__ . '/../includes/header.php';
$csrf = csrfToken();

$db = new Supabase('service');
$hp = $db->from('hot_plate_items', 'select=id,title&deleted_at=is.null&order=position.asc');
$hpItems = $hp['data'] ?? [];
?>
<style>
.mn-page { max-width: 700px; }
.mn-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 28px 32px; }
.mn-card h2 { font-size: 18px; margin-bottom: 4px; }
.mn-card .sub { color: var(--muted); font-size: 13px; margin-bottom: 22px; }

.drop {
    border: 2px dashed var(--border); border-radius: 12px;
    padding: 36px 20px; text-align: center; cursor: pointer;
    background: var(--bg);
    transition: border-color 0.15s, background 0.15s;
}
.drop.dragging { border-color: var(--accent); background: var(--accent-soft); }
.drop .icon { font-size: 36px; margin-bottom: 8px; opacity: 0.7; }
.drop .hint { color: var(--muted); font-size: 13px; }
.drop .formats { font-size: 11px; color: var(--muted); margin-top: 6px; }
.drop input[type=file] { display: none; }

.selected {
    background: var(--accent-soft); border: 1px solid var(--accent);
    border-radius: 8px; padding: 12px 14px; margin-top: 14px;
    display: flex; justify-content: space-between; align-items: center; gap: 12px;
}
.selected .fname { font-weight: 600; color: var(--accent-hover); font-size: 13px; word-break: break-all; }
.selected .fsize { color: var(--muted); font-size: 11px; font-family: ui-monospace, monospace; white-space: nowrap; }
.selected .x { background: transparent; border: 0; cursor: pointer; color: var(--muted); padding: 4px 8px; }

.field { margin-top: 18px; }
.field label {
    display: block; font-size: 11px; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; font-weight: 500;
}
.field input[type=text], .field select, .field input[type=number] {
    width: 100%; padding: 8px 12px; border: 1px solid var(--border);
    border-radius: 8px; background: var(--bg); font-size: 14px;
}
.field input:focus, .field select:focus { outline: 2px solid var(--accent); outline-offset: -1px; }

.actions { display: flex; justify-content: space-between; align-items: center; margin-top: 22px; }
.btn {
    padding: 9px 18px; border-radius: 8px; border: 1px solid var(--accent);
    background: var(--accent); color: #fff; cursor: pointer; font-size: 13px; font-weight: 500;
    text-decoration: none;
}
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn:hover:not(:disabled) { background: var(--accent-hover); border-color: var(--accent-hover); }
.btn.secondary { background: transparent; color: var(--muted); border-color: var(--border); }

.progress {
    margin-top: 18px; padding: 14px 16px;
    background: var(--bg); border: 1px solid var(--border); border-radius: 8px;
    font-size: 13px; color: var(--text-sec);
    display: none;
}
.progress.visible { display: block; }
.progress .bar {
    height: 4px; background: var(--border); border-radius: 4px; overflow: hidden;
    margin-top: 8px;
}
.progress .bar .fill { height: 100%; background: var(--accent); transition: width 0.3s; }
.progress .stage { font-weight: 500; }
</style>

<div class="mn-page">
<div class="mn-card">
    <h2>New meeting</h2>
    <p class="sub">Upload an audio file. We'll transcribe with speaker labels, then summarise with AI.</p>

    <label class="drop" id="drop">
        <div class="icon">🎙️</div>
        <div><strong>Click to choose</strong> or drag an audio file here</div>
        <div class="formats">MP3 · M4A · WAV · WEBM · OGG · FLAC · MP4 · up to 200 MB</div>
        <input type="file" id="file" accept="audio/*,video/mp4,.m4a,.mp3,.wav,.webm,.ogg,.flac,.mp4">
    </label>

    <div id="selected" class="selected" style="display:none;">
        <div>
            <div class="fname" id="fname"></div>
            <div class="fsize" id="fsize"></div>
        </div>
        <button class="x" type="button" onclick="clearFile()">✕</button>
    </div>

    <div class="field">
        <label>Title (optional)</label>
        <input type="text" id="title" placeholder="Defaults to the filename">
    </div>
    <div class="field">
        <label>How many speakers? (optional, helps diarisation)</label>
        <input type="number" id="speakers" min="1" max="10" placeholder="leave blank to auto-detect">
    </div>
    <?php if ($hpItems): ?>
    <div class="field">
        <label>Link to a Hot Plate task (optional)</label>
        <select id="hp_item">
            <option value="">— none —</option>
            <?php foreach ($hpItems as $i): ?>
                <option value="<?= htmlspecialchars((string)$i['id']) ?>"><?= htmlspecialchars((string)$i['title']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <div class="actions">
        <a class="btn secondary" href="/jupiter/ginger-sync/pages/meetings.php">Cancel</a>
        <button class="btn" id="submit" disabled onclick="upload()">Upload &amp; transcribe</button>
    </div>

    <div class="progress" id="progress">
        <div class="stage" id="stage">Uploading…</div>
        <div class="bar"><div class="fill" id="fill" style="width:0%"></div></div>
        <div id="err" style="color:var(--danger);font-size:12px;margin-top:8px;display:none;"></div>
    </div>
</div>
</div>

<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';
let SELECTED = null;

const drop = document.getElementById('drop');
const fileInput = document.getElementById('file');
const sel = document.getElementById('selected');
const submit = document.getElementById('submit');

drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('dragging'); });
drop.addEventListener('dragleave', () => drop.classList.remove('dragging'));
drop.addEventListener('drop', e => {
    e.preventDefault();
    drop.classList.remove('dragging');
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        showFile(e.dataTransfer.files[0]);
    }
});
fileInput.addEventListener('change', () => fileInput.files[0] && showFile(fileInput.files[0]));

function showFile(f) {
    SELECTED = f;
    document.getElementById('fname').textContent = f.name;
    document.getElementById('fsize').textContent = formatBytes(f.size);
    sel.style.display = 'flex';
    submit.disabled = false;
}
function clearFile() {
    SELECTED = null;
    fileInput.value = '';
    sel.style.display = 'none';
    submit.disabled = true;
}
function formatBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1024*1024) return (b/1024).toFixed(0) + ' KB';
    return (b/(1024*1024)).toFixed(1) + ' MB';
}

async function upload() {
    if (!SELECTED) return;
    submit.disabled = true;
    document.getElementById('progress').classList.add('visible');
    setStage('Uploading audio…', 5);

    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('audio', SELECTED);
    fd.append('title', document.getElementById('title').value.trim());
    fd.append('speakers_expected', document.getElementById('speakers').value);
    const hp = document.getElementById('hp_item');
    if (hp) fd.append('hot_plate_item_id', hp.value);

    try {
        const res = await xhrUpload('/jupiter/ginger-sync/api/meetings-upload.php', fd, (pct) => setStage('Uploading audio…', 5 + Math.round(pct * 0.5)));
        if (!res.ok) throw new Error(res.error || 'upload failed');
        const meetingId = res.meeting_id;
        setStage('Sending to AssemblyAI…', 60);

        // Step through the pipeline by calling meetings-process repeatedly
        let lastStatus = 'uploaded';
        for (let i = 0; i < 90; i++) {  // ~3 min max client poll
            const r = await fetch('/jupiter/ginger-sync/api/meetings-process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
                body: JSON.stringify({ id: meetingId }),
            }).then(r => r.json());
            if (!r.ok && r.error && !r.status) throw new Error(r.error);
            lastStatus = r.status || lastStatus;
            if (lastStatus === 'transcribing') setStage('Transcribing…', 70);
            else if (lastStatus === 'analyzing') setStage('Generating summary…', 90);
            else if (lastStatus === 'ready' || lastStatus === 'audio_only') {
                setStage('Done', 100);
                window.location.href = '/jupiter/ginger-sync/pages/meeting.php?id=' + encodeURIComponent(meetingId);
                return;
            } else if (lastStatus === 'error') {
                throw new Error(r.error || 'processing error');
            }
            await sleep(3000);
        }
        // Time out client polling but keep the meeting in the queue — server can resume
        window.location.href = '/jupiter/ginger-sync/pages/meeting.php?id=' + encodeURIComponent(meetingId);
    } catch (e) {
        setStage('Failed', 0);
        document.getElementById('err').textContent = '✗ ' + e.message;
        document.getElementById('err').style.display = 'block';
        submit.disabled = false;
    }
}

function setStage(text, pct) {
    document.getElementById('stage').textContent = text;
    document.getElementById('fill').style.width = pct + '%';
}
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function xhrUpload(url, fd, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.upload.onprogress = e => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
        xhr.onload = () => {
            try { resolve(JSON.parse(xhr.responseText)); }
            catch (e) { reject(new Error('Bad response: ' + xhr.status)); }
        };
        xhr.onerror = () => reject(new Error('Network error'));
        xhr.open('POST', url);
        xhr.send(fd);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
