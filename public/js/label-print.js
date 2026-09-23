/*
 * Label print station (API-038) — scan a box uuid, list the contents,
 * touch a row to print its DYMO sticker. All printing goes through the
 * shared window.Dymo service (public/js/dymo.js); item data comes from
 * the existing session endpoint GET /kanban/item/{itemId} (item +
 * children). Plain global JS, same conventions as kanban.js.
 */

/* ---------- tiny helpers ---------- */

/* id -> display name for the currently loaded box (print handlers pass the
   uuid only; user data never travels through inline onclick strings) */
let currentItems = {};

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

let toastTimer = null;
function notify(message, type) {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.textContent = message;
    toast.className = 'toast px-4 py-2 rounded-lg shadow-lg text-sm font-medium text-white ' +
        (type === 'error' ? 'bg-red-600' : 'bg-green-600');
    toast.classList.remove('hidden');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.add('hidden'); }, 2500);
}

/* ---------- DYMO status + prefs ---------- */

async function initDymoStation() {
    if (!window.Dymo) {
        setDymoStatus(false, 'DYMO service script failed to load');
        return;
    }
    const det = await Dymo.detect();
    setDymoStatus(det.ready, det.ready
        ? 'Ready — ' + det.printers.length + ' LabelWriter printer' + (det.printers.length > 1 ? 's' : '')
        : Dymo.reasonText(det.reason));

    if (det.ready) {
        populatePrinterSelect(det.printers);
        populateTemplateSelect();
        const prefs = document.getElementById('dymoPrefs');
        prefs.classList.remove('hidden');
        prefs.classList.add('flex');
    }
}

function setDymoStatus(ok, text) {
    const el = document.getElementById('dymoStatus');
    if (!el) return;
    if (ok) {
        el.innerHTML = '<i class="fas fa-circle-check text-green-600"></i>' +
            '<span class="text-green-700 font-medium">' + escapeHtml(text) + '</span>';
    } else {
        el.innerHTML = '<i class="fas fa-circle-exclamation text-amber-500"></i>' +
            '<span class="text-amber-700">' + escapeHtml(text) + ' — printing disabled</span>';
    }
}

function populatePrinterSelect(printers) {
    const select = document.getElementById('printerSelect');
    if (!select) return;
    select.innerHTML = printers.map(function (name) {
        return '<option value="' + escapeHtml(name) + '">' + escapeHtml(name) + '</option>';
    }).join('');
    const saved = Dymo.getPrinter();
    select.value = printers.indexOf(saved) !== -1 ? saved : printers[0];
    Dymo.setPrinter(select.value);
    select.addEventListener('change', function () { Dymo.setPrinter(select.value); });
}

function populateTemplateSelect() {
    const select = document.getElementById('templateSelect');
    if (!select) return;
    select.innerHTML = Object.keys(Dymo.TEMPLATES).map(function (id) {
        const t = Dymo.TEMPLATES[id];
        return '<option value="' + id + '">' + t.paper + '</option>';
    }).join('');
    select.value = Dymo.getTemplate();
    select.addEventListener('change', function () { Dymo.setTemplate(select.value); });
}

/* ---------- scan + contents ---------- */

async function scanBox() {
    const input = document.getElementById('labelScanInput');
    const value = (input.value || '').trim();
    if (!value) return;

    try {
        const response = await axios.get('/kanban/item/' + encodeURIComponent(value));
        renderBox(response.data);
    } catch (error) {
        hideBox();
        notify(error.response && error.response.status === 404
            ? 'Item not found in this team'
            : 'Could not load the item', 'error');
    }
    input.value = '';
    input.focus();
}

function hideBox() {
    document.getElementById('boxPanel').classList.add('hidden');
    document.getElementById('emptyHint').classList.remove('hidden');
}

function renderBox(data) {
    const item = data.item;
    const children = data.children || [];

    /* id -> name registry: print handlers pass the uuid only, names never
       travel through inline onclick strings (user data stays out of JS) */
    currentItems = {};
    currentItems[item.id] = item.name;
    children.forEach(function (child) { currentItems[child.id] = child.name; });

    /* Box header: identity + a print button for the box's own sticker */
    const locationName = item.location ? item.location.name : null;
    document.getElementById('boxHeader').innerHTML =
        '<div class="flex items-start justify-between gap-3 flex-wrap">' +
        '  <div>' +
        '    <div class="text-lg font-bold text-gray-800">' + escapeHtml(item.name) + '</div>' +
        '    <div class="text-xs text-gray-500 font-mono break-all mt-1">' + escapeHtml(item.id) + '</div>' +
        (locationName ? '<div class="mt-1"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><i class="fas fa-map-marker-alt mr-1"></i>' + escapeHtml(locationName) + '</span></div>' : '') +
        '  </div>' +
        '  <button onclick="printRow(\'' + item.id + '\', this)" ' +
        '          class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2.5 rounded-lg text-sm font-medium transition">' +
        '    <i class="fas fa-print mr-1"></i>Print this label' +
        '  </button>' +
        '</div>';

    const list = document.getElementById('contentsList');

    if (!children.length) {
        /* Not a box (or empty): still offer the scanned item's own label */
        list.innerHTML =
            '<div class="text-center text-gray-500 py-6 bg-white/60 rounded-xl mb-3">' +
            '  <i class="fas fa-inbox text-3xl mb-2 opacity-40"></i>' +
            '  <p>No contents — this is a single item.</p>' +
            '</div>' +
            rowMarkup(item);
    } else {
        list.innerHTML = children.map(rowMarkup).join('');
    }

    document.getElementById('emptyHint').classList.add('hidden');
    document.getElementById('boxPanel').classList.remove('hidden');
}

function rowMarkup(child) {
    return '<div class="print-row bg-white/90 rounded-xl shadow p-4 flex items-center justify-between gap-3" ' +
        'onclick="printRow(\'' + child.id + '\', this)">' +
        '  <div class="min-w-0">' +
        '    <div class="font-medium text-gray-800 truncate">' + escapeHtml(child.name) + '</div>' +
        '    <div class="text-xs text-gray-400 font-mono truncate">' + escapeHtml(child.id) + '</div>' +
        '  </div>' +
        '  <div class="row-state flex items-center gap-2 flex-shrink-0 text-sm font-medium text-indigo-600">' +
        '    <i class="fas fa-print"></i><span>Print</span>' +
        '  </div>' +
        '</div>';
}

/* ---------- touch-to-print ---------- */

/* itemId only (uuids are inline-safe); the display name comes from the
   renderBox registry, never from interpolated strings */
function printRow(itemId, el) {
    const row = el ? el.closest('.print-row') : null;
    const itemName = currentItems[itemId] || itemId;
    const stateEl = row ? row.querySelector('.row-state') : null;

    if (row) {
        row.classList.remove('state-printed', 'state-failed');
        row.classList.add('state-printing');
        if (stateEl) stateEl.innerHTML = '<i class="fas fa-spinner fa-spin text-amber-500"></i><span class="text-amber-600">Printing&hellip;</span>';
    }

    Dymo.printItemQueued(itemName, itemId).then(function () {
        if (stateEl) stateEl.innerHTML = '<i class="fas fa-circle-check text-green-600"></i><span class="text-green-700">Printed</span>';
        if (row) { row.classList.remove('state-printing'); row.classList.add('state-printed'); }
    }).catch(function (error) {
        if (stateEl) stateEl.innerHTML = '<i class="fas fa-circle-exclamation text-red-600"></i><span class="text-red-700">Failed</span>';
        if (row) { row.classList.remove('state-printing'); row.classList.add('state-failed'); }
        notify('Print failed: ' + (error && error.message ? error.message : 'unknown error'), 'error');
    });
}

/* ---------- bootstrap ---------- */

document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('labelScanInput');
    if (input) {
        input.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') scanBox();
        });
        input.focus();
    }
    initDymoStation();
});

/* globals used by inline onclick attributes */
window.scanBox = scanBox;
window.printRow = printRow;
