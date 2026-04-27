'use strict';

// ── Signature Pad ─────────────────────────────────────────────────────────────

const canvas      = document.getElementById('signature-canvas');
const sigWrapper  = document.getElementById('sig-wrapper');
const sigPlaceholder = document.getElementById('sig-placeholder');
const sigError    = document.getElementById('sig-error');
const sigInput    = document.getElementById('signature-data');

const signaturePad = new SignaturePad(canvas, {
    backgroundColor: 'rgba(255,255,255,0)',
    penColor: '#1a1a1a',
    minWidth: 0.8,
    maxWidth: 2.8,
    velocityFilterWeight: 0.7,
});

function resizeCanvas() {
    const ratio  = Math.max(window.devicePixelRatio || 1, 1);
    const rect   = canvas.getBoundingClientRect();
    canvas.width  = rect.width  * ratio;
    canvas.height = rect.height * ratio;
    canvas.getContext('2d').scale(ratio, ratio);
    signaturePad.clear();
}

window.addEventListener('resize', resizeCanvas);
resizeCanvas();

signaturePad.addEventListener('beginStroke', () => {
    sigPlaceholder.style.opacity = '0';
});

document.getElementById('clear-signature').addEventListener('click', () => {
    signaturePad.clear();
    sigPlaceholder.style.opacity = '1';
});

// ── Ausgaben Rows ─────────────────────────────────────────────────────────────

let rowCount = 1;

function addRow() {
    rowCount++;
    const container = document.getElementById('ausgaben-rows');
    const row = document.createElement('div');
    row.className = 'ausgaben-row';
    row.innerHTML = `
        <span class="row-nr">${rowCount}.</span>
        <input type="text" name="ausgaben_beschreibung[]" placeholder="Beschreibung der Ausgabe">
        <input type="number" name="ausgaben_preis[]" placeholder="0.00" step="0.01" min="0"
               class="price-input">
        <button type="button" class="btn-row-del" onclick="removeRow(this)"
                aria-label="Zeile entfernen">×</button>
    `;
    container.appendChild(row);
    row.querySelector('input[type="text"]').focus();
    updateTotal();
    updateRowNumbers();
}

function removeRow(btn) {
    const rows = document.querySelectorAll('.ausgaben-row');
    if (rows.length <= 1) return;
    btn.closest('.ausgaben-row').remove();
    updateTotal();
    updateRowNumbers();
}

function updateRowNumbers() {
    document.querySelectorAll('.ausgaben-row').forEach((row, i) => {
        const nr = row.querySelector('.row-nr');
        if (nr) nr.textContent = (i + 1) + '.';
    });
    rowCount = document.querySelectorAll('.ausgaben-row').length;
}

function updateTotal() {
    let total = 0;
    document.querySelectorAll('.price-input').forEach(input => {
        total += parseFloat(input.value) || 0;
    });
    document.getElementById('gesamt-display').textContent =
        total.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
}

document.addEventListener('input', e => {
    if (e.target.classList.contains('price-input')) updateTotal();
});

// ── Zahlungsart → Konto section ───────────────────────────────────────────────

const kontoSection  = document.getElementById('konto-section');
const kontoInputs   = ['kontoinhaber', 'iban', 'bank'].map(id => document.getElementById(id));

document.querySelectorAll('input[name="zahlungsart"]').forEach(radio => {
    radio.addEventListener('change', () => {
        const show = radio.value === 'rueckueberweisung' && radio.checked;
        kontoSection.hidden = !show;
        kontoInputs.forEach(inp => { if (inp) inp.required = show; });
    });
});

// ── File Upload ───────────────────────────────────────────────────────────────

const uploadArea  = document.getElementById('upload-area');
const fileInput   = document.getElementById('file-input');
const filePreviews = document.getElementById('file-previews');

let fileList = new DataTransfer();

uploadArea.addEventListener('click', () => fileInput.click());
uploadArea.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') fileInput.click(); });

uploadArea.addEventListener('dragover', e => {
    e.preventDefault();
    uploadArea.classList.add('drag-over');
});
uploadArea.addEventListener('dragleave', e => {
    if (!uploadArea.contains(e.relatedTarget)) uploadArea.classList.remove('drag-over');
});
uploadArea.addEventListener('drop', e => {
    e.preventDefault();
    uploadArea.classList.remove('drag-over');
    addFiles(e.dataTransfer.files);
});

fileInput.addEventListener('change', () => {
    addFiles(fileInput.files);
    fileInput.value = '';
});

const MAX_MB     = 10;
const ALLOWED    = new Set(['image/jpeg', 'image/png', 'application/pdf']);

function addFiles(incoming) {
    for (const file of incoming) {
        if (!ALLOWED.has(file.type)) {
            alert(`"${file.name}" hat kein erlaubtes Format (JPG, PNG, PDF).`);
            continue;
        }
        if (file.size > MAX_MB * 1024 * 1024) {
            alert(`"${file.name}" ist größer als ${MAX_MB} MB und wurde nicht hinzugefügt.`);
            continue;
        }
        fileList.items.add(file);
    }
    fileInput.files = fileList.files;
    renderPreviews();
}

function removeFile(idx) {
    const dt = new DataTransfer();
    Array.from(fileList.files).forEach((f, i) => { if (i !== idx) dt.items.add(f); });
    fileList = dt;
    fileInput.files = fileList.files;
    renderPreviews();
}

function renderPreviews() {
    filePreviews.innerHTML = '';
    Array.from(fileList.files).forEach((file, i) => {
        const item = document.createElement('div');
        item.className = 'file-item';

        const removeBtn = `<button type="button" class="file-remove"
            onclick="removeFile(${i})" title="Entfernen" aria-label="Datei entfernen">×</button>`;
        const name = `<span class="file-name" title="${file.name}">${truncate(file.name, 18)}</span>`;

        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = e => {
                item.innerHTML = `<img src="${e.target.result}" alt="${file.name}">${name}${removeBtn}`;
            };
            reader.readAsDataURL(file);
        } else {
            item.innerHTML = `<div class="pdf-icon">PDF</div>${name}${removeBtn}`;
        }
        filePreviews.appendChild(item);
    });
}

function truncate(str, n) {
    if (str.length <= n) return str;
    const ext = str.lastIndexOf('.');
    if (ext > 0 && str.length - ext <= 5) {
        return str.slice(0, n - (str.length - ext) - 1) + '…' + str.slice(ext);
    }
    return str.slice(0, n - 1) + '…';
}

// ── Form Submit ───────────────────────────────────────────────────────────────

const form    = document.getElementById('ausgaben-form');
const overlay = document.getElementById('loading-overlay');
const loadTxt = document.getElementById('loading-text');

let downloadPollInterval = null;

form.addEventListener('submit', function (e) {
    // Capture signature before submit
    if (!signaturePad.isEmpty()) {
        sigInput.value = signaturePad.toDataURL('image/png');
        sigError.style.display = 'none';
    } else {
        sigInput.value = '';
    }

    // Basic validation
    if (!validateForm()) {
        e.preventDefault();
        return;
    }

    const action = document.activeElement ? document.activeElement.value : 'download';

    // For downloads: set a unique token so we can detect when the file arrives
    if (action === 'download') {
        const token = Math.random().toString(36).slice(2) + Date.now().toString(36);
        document.getElementById('download-token').value = token;
        pollForDownload(token);
    }

    loadTxt.textContent = action === 'email'
        ? 'E-Mail wird versendet…'
        : 'PDF wird erstellt…';
    overlay.hidden = false;
});

function pollForDownload(token) {
    const cookieName = 'download_ready_' + token;
    clearInterval(downloadPollInterval);
    downloadPollInterval = setInterval(function () {
        if (document.cookie.indexOf(cookieName) !== -1) {
            clearInterval(downloadPollInterval);
            overlay.hidden = true;
            // Delete cookie
            document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
        }
    }, 400);
    // Fallback: hide after 30s no matter what
    setTimeout(function () {
        clearInterval(downloadPollInterval);
        overlay.hidden = true;
    }, 30000);
}

function validateForm() {
    let valid = true;

    // Check at least one ausgabe has description + price
    const descs  = [...document.querySelectorAll('input[name="ausgaben_beschreibung[]"]')];
    const prices = [...document.querySelectorAll('input[name="ausgaben_preis[]"]')];
    const hasAusgabe = descs.some((d, i) => d.value.trim() && parseFloat(prices[i]?.value) > 0);
    if (!hasAusgabe) {
        alert('Bitte mindestens eine Ausgabe mit Beschreibung und Preis angeben.');
        valid = false;
    }

    // Zahlungsart must be selected
    const zahlungsart = document.querySelector('input[name="zahlungsart"]:checked');
    if (!zahlungsart) {
        alert('Bitte eine Zahlungsart auswählen.');
        valid = false;
    }

    return valid;
}
