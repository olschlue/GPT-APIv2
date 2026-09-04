<?php
/** @var int $maxUploadMb */
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GPT-APIv2 – Audio-Transkription</title>
<style>
    :root {
        --bg: #0f172a; --panel: #1e293b; --border: #334155;
        --text: #e2e8f0; --muted: #94a3b8; --accent: #38bdf8;
        --ok: #4ade80; --warn: #fbbf24; --err: #f87171;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; padding: 2rem 1rem;
        font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        background: var(--bg); color: var(--text); line-height: 1.5;
    }
    main { max-width: 860px; margin: 0 auto; }
    h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
    .sub { color: var(--muted); margin: 0 0 1.5rem; }
    .panel {
        background: var(--panel); border: 1px solid var(--border);
        border-radius: .75rem; padding: 1.25rem; margin-bottom: 1.25rem;
    }
    .badge {
        display: inline-block; padding: .15rem .6rem; border-radius: 999px;
        font-size: .8rem; border: 1px solid var(--border); color: var(--muted);
        margin-right: .4rem;
    }
    .badge.ok { color: var(--ok); border-color: var(--ok); }
    .badge.err { color: var(--err); border-color: var(--err); }
    .dropzone {
        border: 2px dashed var(--border); border-radius: .75rem;
        padding: 2.5rem 1rem; text-align: center; cursor: pointer;
        transition: border-color .15s;
    }
    .dropzone:hover, .dropzone.drag { border-color: var(--accent); }
    .dropzone input { display: none; }
    .dropzone .hint { color: var(--muted); font-size: .9rem; }
    .fileinfo { margin-top: .75rem; font-size: .9rem; color: var(--muted); }
    button {
        background: var(--accent); color: #082f49; border: 0; border-radius: .5rem;
        padding: .7rem 1.4rem; font-size: 1rem; font-weight: 600; cursor: pointer;
    }
    button:disabled { opacity: .5; cursor: wait; }
    .spinner {
        display: inline-block; width: 1rem; height: 1rem; margin-right: .5rem;
        border: 2px solid var(--muted); border-top-color: var(--accent);
        border-radius: 50%; animation: spin 1s linear infinite; vertical-align: -2px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .error-box {
        background: rgba(248,113,113,.1); border: 1px solid var(--err);
        color: var(--err); border-radius: .5rem; padding: .8rem 1rem; margin-top: 1rem;
    }
    h2 { font-size: 1.1rem; margin: 0 0 .6rem; color: var(--accent); }
    ul { margin: .25rem 0 .75rem 1.2rem; padding: 0; }
    table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    th, td { text-align: left; padding: .5rem .6rem; border-bottom: 1px solid var(--border); }
    th { color: var(--muted); font-weight: 600; }
    .prio { padding: .1rem .5rem; border-radius: 999px; font-size: .78rem; font-weight: 600; }
    .prio.high { background: rgba(248,113,113,.15); color: var(--err); }
    .prio.medium { background: rgba(251,191,36,.15); color: var(--warn); }
    .prio.low { background: rgba(74,222,128,.15); color: var(--ok); }
    details summary { cursor: pointer; color: var(--muted); }
    pre {
        background: #0b1220; border: 1px solid var(--border); border-radius: .5rem;
        padding: 1rem; overflow-x: auto; font-size: .82rem; white-space: pre-wrap;
    }
    .transcript { white-space: pre-wrap; }
    .hidden { display: none; }
</style>
</head>
<body>
<main>
    <h1>GPT-APIv2</h1>
    <p class="sub">Audio hochladen → transkribieren → strukturierte Auswertung</p>

    <div class="panel">
        <span id="health" class="badge">Status wird geladen …</span>
        <span class="badge">Limit: <?= (int) $maxUploadMb ?> MB</span>
        <span class="badge">Formate: MP3, M4A, WAV, WEBM</span>
    </div>

    <div class="panel">
        <form id="upload-form">
            <label class="dropzone" id="dropzone">
                <input type="file" id="file" name="file" accept=".mp3,.m4a,.wav,.webm,audio/*">
                <strong>Audiodatei auswählen</strong> oder hierher ziehen
                <div class="hint">MP3, M4A, WAV oder WEBM – max. <?= (int) $maxUploadMb ?> MB</div>
            </label>
            <div class="fileinfo" id="fileinfo"></div>
            <div style="margin-top: 1rem;">
                <button type="submit" id="submit-btn" disabled>Transkribieren &amp; auswerten</button>
            </div>
        </form>
        <div id="loading" class="hidden" style="margin-top: 1rem;">
            <span class="spinner"></span>
            <span id="loading-text">Wird verarbeitet …</span>
        </div>
        <div id="error" class="error-box hidden"></div>
    </div>

    <div id="result" class="hidden">
        <div class="panel"><h2>Zusammenfassung</h2><p id="r-summary"></p></div>
        <div class="panel"><h2>Gliederung</h2><div id="r-outline"></div></div>
        <div class="panel"><h2>Aufgaben</h2><div id="r-tasks"></div></div>
        <div class="panel"><h2>Entscheidungen</h2><div id="r-decisions"></div></div>
        <div class="panel">
            <details>
                <summary><h2 style="display:inline;">Transkript</h2></summary>
                <p class="transcript" id="r-transcript"></p>
            </details>
        </div>
        <div class="panel">
            <details>
                <summary>Rohes JSON</summary>
                <pre id="r-json"></pre>
            </details>
        </div>
    </div>
</main>

<script>
const MAX_BYTES = <?= (int) $maxUploadMb ?> * 1024 * 1024;
const ALLOWED = ['mp3', 'm4a', 'wav', 'webm'];

const $ = (id) => document.getElementById(id);
const fileInput = $('file'), dropzone = $('dropzone'), submitBtn = $('submit-btn');

// API-Basis: index.php liegt im gleichen Verzeichnis → PATH_INFO-URLs,
// funktionieren ohne .htaccess und ohne mod_rewrite.
const API_BASE = 'index.php/api';

async function loadHealth() {
    try {
        const res = await fetch(API_BASE + '/health');
        const data = await res.json();
        const el = $('health');
        if (data.status === 'ok' && data.config && data.config.openai_key_configured) {
            el.textContent = 'API bereit (' + data.config.transcribe_model + ' / ' + data.config.analysis_model + ')';
            el.classList.add('ok');
        } else {
            el.textContent = 'API läuft, aber OPENAI_API_KEY fehlt';
            el.classList.add('err');
        }
    } catch {
        $('health').textContent = 'API nicht erreichbar';
        $('health').classList.add('err');
    }
}

function fmtSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
}

function validate(file) {
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    if (!ALLOWED.includes(ext)) return 'Ungültiges Format. Erlaubt: ' + ALLOWED.join(', ').toUpperCase();
    if (file.size > MAX_BYTES) return 'Datei zu groß (' + fmtSize(file.size) + ').';
    return null;
}

function onFile(file) {
    $('error').classList.add('hidden');
    if (!file) { submitBtn.disabled = true; $('fileinfo').textContent = ''; return; }
    const problem = validate(file);
    if (problem) {
        showError(problem);
        submitBtn.disabled = true;
    } else {
        $('fileinfo').textContent = file.name + ' (' + fmtSize(file.size) + ')';
        submitBtn.disabled = false;
    }
}

fileInput.addEventListener('change', () => onFile(fileInput.files[0]));
['dragover', 'dragleave', 'drop'].forEach(evt => dropzone.addEventListener(evt, (e) => {
    e.preventDefault();
    dropzone.classList.toggle('drag', evt === 'dragover');
    if (evt === 'drop' && e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        onFile(e.dataTransfer.files[0]);
    }
}));

function showError(msg) {
    $('error').textContent = msg;
    $('error').classList.remove('hidden');
}

function setLoading(on) {
    $('loading').classList.toggle('hidden', !on);
    submitBtn.disabled = on || !fileInput.files.length;
    if (on) {
        let seconds = 0;
        $('loading-text').textContent = 'Transkription läuft … (0 s)';
        window._timer = setInterval(() => {
            seconds++;
            $('loading-text').textContent = 'Transkription & Analyse laufen … (' + seconds + ' s)';
        }, 1000);
    } else {
        clearInterval(window._timer);
    }
}

function el(tag, text, cls) {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (cls) node.className = cls;
    return node;
}

function render(data) {
    $('r-summary').textContent = data.summary || '–';
    $('r-transcript').textContent = data.transcript || '';
    $('r-json').textContent = JSON.stringify(data, null, 2);

    const outline = $('r-outline'); outline.textContent = '';
    if (Array.isArray(data.outline) && data.outline.length) {
        data.outline.forEach(item => {
            outline.appendChild(el('strong', item.topic));
            const ul = el('ul');
            (item.points || []).forEach(p => ul.appendChild(el('li', p)));
            outline.appendChild(ul);
        });
    } else outline.textContent = 'Keine Themen erkannt.';

    const tasks = $('r-tasks'); tasks.textContent = '';
    if (Array.isArray(data.tasks) && data.tasks.length) {
        const table = el('table');
        table.innerHTML = '<thead><tr><th>Aufgabe</th><th>Owner</th><th>Deadline</th><th>Priorität</th></tr></thead>';
        const tbody = el('tbody');
        data.tasks.forEach(t => {
            const tr = el('tr');
            tr.appendChild(el('td', t.task || ''));
            tr.appendChild(el('td', t.owner || '–'));
            tr.appendChild(el('td', t.deadline || '–'));
            const prio = el('td'); prio.appendChild(el('span', t.priority || 'medium', 'prio ' + (t.priority || 'medium')));
            tr.appendChild(prio);
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        tasks.appendChild(table);
    } else tasks.textContent = 'Keine Aufgaben erkannt.';

    const decisions = $('r-decisions'); decisions.textContent = '';
    if (Array.isArray(data.decisions) && data.decisions.length) {
        const ul = el('ul');
        data.decisions.forEach(d => ul.appendChild(el('li', d)));
        decisions.appendChild(ul);
    } else decisions.textContent = 'Keine Entscheidungen erkannt.';

    $('result').classList.remove('hidden');
    $('result').scrollIntoView({ behavior: 'smooth' });
}

$('upload-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const file = fileInput.files[0];
    if (!file || validate(file)) return;

    $('error').classList.add('hidden');
    $('result').classList.add('hidden');
    setLoading(true);

    const body = new FormData();
    body.append('file', file);

    try {
        const res = await fetch(API_BASE + '/transcribe', { method: 'POST', body });
        const data = await res.json().catch(() => null);
        if (!res.ok) {
            showError(data && data.error ? '[' + data.error.code + '] ' + data.error.message
                : 'Fehler ' + res.status);
        } else {
            render(data);
        }
    } catch (err) {
        showError('Netzwerkfehler: ' + err.message);
    } finally {
        setLoading(false);
    }
});

loadHealth();
</script>
</body>
</html>
