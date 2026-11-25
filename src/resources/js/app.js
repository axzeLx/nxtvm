import Alpine from 'alpinejs';
import './sortable';

/*
 * Appearance: colour palette (data-theme on <html>) + mode 'light' | 'dark' | 'system' (.dark class).
 * The initial state is rendered server-side for signed-in users (stored on the user) and applied
 * from localStorage on the login page. An inline script in <head> applies it before first paint.
 */
const media = window.matchMedia('(prefers-color-scheme: dark)');
const root = document.documentElement;

function store(key, value) {
    try { localStorage.setItem(key, value); } catch { /* storage unavailable */ }
}

function applyMode(mode) {
    root.classList.toggle('dark', mode === 'dark' || (mode === 'system' && media.matches));
}

media.addEventListener('change', () => applyMode(root.dataset.mode || 'system'));

function persist(data) {
    const url = root.dataset.appearanceUrl;
    if (!url) return;
    fetch(url, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify(data),
    }).catch(() => { /* cosmetic only */ });
}

Alpine.data('appearance', () => ({
    open: false,
    mode: root.dataset.mode || 'system',
    theme: root.dataset.theme || 'teal',
    setMode(mode) {
        this.mode = mode;
        root.dataset.mode = mode;
        store('mode', mode);
        applyMode(mode);
        persist({ mode });
    },
    setTheme(theme) {
        this.theme = theme;
        root.dataset.theme = theme;
        store('theme', theme);
        persist({ theme });
    },
}));

/*
 * vCenter connection test. url: endpoint; form: optional <form> whose fields are sent (unsaved values).
 * Result: { ok, message, details{}, warnings[] }
 */
Alpine.data('vcTest', (url, form = null) => ({
    busy: false,
    result: null,
    async run() {
        this.busy = true;
        this.result = null;
        const body = form ? new FormData(document.getElementById(form)) : new FormData();
        body.delete('_method');
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body,
            });
            const data = await res.json();
            this.result = res.ok ? data : { ok: false, message: data.message || res.statusText };
        } catch (e) {
            this.result = { ok: false, message: String(e) };
        } finally {
            this.busy = false;
        }
    },
}));

/*
 * "Collect now" button. Queues a collection, then polls the status endpoint until the worker is done
 * and reloads the page so the new inventory shows. initial: the state rendered server-side.
 * All buttons on a page share one poller (one request every 2 s, whatever the number of buttons).
 */
const collectPoll = { timer: null, listeners: new Set(), url: null };

function pollCollect() {
    if (collectPoll.timer) return;
    collectPoll.timer = setInterval(async () => {
        try {
            const res = await fetch(collectPoll.url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            collectPoll.listeners.forEach((fn) => fn(data));
        } catch { /* keep polling */ }
    }, 2000);
}

Alpine.data('vcCollect', (url, statusUrl, id, initial = null) => ({
    state: initial, // null | 'queued' | 'running' | 'done' | 'failed'
    error: null,
    labels: {},
    init() {
        this.labels = { ...this.$root.dataset };
        collectPoll.url = statusUrl;
        collectPoll.listeners.add((data) => this.update(data[id]));
        if (this.state) pollCollect();
    },
    get busy() { return this.state === 'queued' || this.state === 'running'; },
    get label() {
        const l = this.labels;
        return { queued: l.lQueued, running: l.lRunning, done: l.lDone, failed: l.lFailed }[this.state] || l.lIdle;
    },
    update(s) {
        if (!s || !this.busy) return;
        if (s.state) { this.state = s.state; return; }
        this.error = s.error;
        this.state = s.error ? 'failed' : 'done';
        // reload once nothing on the page is still collecting
        setTimeout(() => {
            if (![...document.querySelectorAll('[data-collect-state]')].some((el) => ['queued', 'running'].includes(el.dataset.collectState))) {
                window.location.reload();
            }
        }, 800);
    },
    async run() {
        if (this.busy) return;
        this.error = null;
        this.state = 'queued';
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || res.statusText);
            this.state = data.state || 'queued';
            pollCollect();
        } catch (e) {
            this.state = 'failed';
            this.error = String(e.message || e);
        }
    },
}));

// "Collect all": clicks every collect button on the page (each one tracks its own vCenter)
document.addEventListener('click', (e) => {
    if (!e.target.closest('[data-collect-all]')) return;
    document.querySelectorAll('[data-collect-button]').forEach((b) => b.click());
});

/*
 * Table filtering. Two sources, combined with AND:
 *  - quick filter: <input data-filter="tableId"> outside the table; every word must appear in the row text
 *    or its data-search attribute. Tables with data-groups filter whole <tbody> groups.
 *  - column filters: a <tr data-filters> in the table's <thead>; each control filters the column it sits in.
 *    Controls carry data-cf = text | num | select | check. A cell's value is its data-f attribute, else its text.
 *      text   substring, case-insensitive
 *      num    "8" (equal), ">4", ">=4", "<16", "<=16", "4-8" (range); several separated by spaces = AND
 *      select exact value ("" = any)
 *      check  checkboxes in one cell (value = data-f token); none checked = any, otherwise any checked matches
 */
function cellValue(row, idx) {
    const cell = row.cells[idx];
    if (!cell) return '';
    return (cell.dataset.f ?? cell.textContent).trim().toLowerCase();
}

function numMatch(expr, raw) {
    const v = Number(raw);
    if (raw === '' || !Number.isFinite(v)) return false;
    return expr.split(/\s+/).filter(Boolean).every((t) => {
        let m = t.match(/^(>=|<=|>|<|=)?(-?[\d.,]+)$/);
        if (m) {
            const n = Number(m[2].replace(',', '.'));
            switch (m[1]) {
                case '>': return v > n;
                case '>=': return v >= n;
                case '<': return v < n;
                case '<=': return v <= n;
                default: return v === n;
            }
        }
        m = t.match(/^(-?[\d.,]+)-(-?[\d.,]+)$/);
        if (m) return v >= Number(m[1].replace(',', '.')) && v <= Number(m[2].replace(',', '.'));
        return true; // unparsable token: ignore rather than hide everything
    });
}

function columnTests(table) {
    const row = table.tHead?.querySelector('tr[data-filters]');
    if (!row) return [];
    const tests = [];
    [...row.cells].forEach((cell) => {
        const idx = cell.cellIndex;
        const checks = [...cell.querySelectorAll('[data-cf="check"]')];
        if (checks.length) {
            const on = checks.filter((c) => c.checked).map((c) => c.value.toLowerCase());
            if (on.length) tests.push((r) => on.includes(cellValue(r, idx)));
            return;
        }
        const el = cell.querySelector('[data-cf]');
        if (!el) return;
        const q = el.value.trim().toLowerCase();
        if (!q) return;
        if (el.dataset.cf === 'num') tests.push((r) => numMatch(q, cellValue(r, idx)));
        else if (el.dataset.cf === 'select') tests.push((r) => cellValue(r, idx) === q);
        else tests.push((r) => cellValue(r, idx).includes(q));
    });
    return tests;
}

function markActive(table) {
    table.tHead?.querySelectorAll('tr[data-filters] > *').forEach((cell) => {
        const active = [...cell.querySelectorAll('[data-cf]')].some((c) => (c.type === 'checkbox' ? c.checked : c.value.trim() !== ''));
        cell.classList.toggle('cf-active', active);
    });
}

function applyFilters(table) {
    const quick = document.querySelector(`[data-filter="${table.id}"]`);
    const words = (quick?.value || '').toLowerCase().split(/\s+/).filter(Boolean);
    const grouped = table.dataset.groups !== undefined;
    const items = grouped ? [...table.tBodies] : [...(table.tBodies[0]?.rows || [])];
    const tests = grouped ? [] : columnTests(table);
    // <input type="checkbox" data-show-local="tableId">: unchecked hides rows marked data-local
    const localToggle = document.querySelector(`[data-show-local="${table.id}"]`);
    const hideLocal = localToggle && !localToggle.checked;
    let shown = 0;
    items.forEach((item) => {
        const text = (item.textContent + ' ' + (item.dataset.search || '')).toLowerCase();
        const hit = words.every((w) => text.includes(w)) && tests.every((t) => t(item))
            && !(hideLocal && item.hasAttribute('data-local'));
        item.style.display = hit ? '' : 'none';
        if (hit) shown++;
    });
    markActive(table);
    document.querySelectorAll(`[data-count-for="${table.id}"]`).forEach((c) => { c.textContent = shown; });
    if (!grouped) updateSums(table, items.filter((r) => r.style.display !== 'none'));
}

// same as Fmt::bytes() in PHP
function fmtBytes(n) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(i < 3 ? 0 : 1)} ${units[i]}`;
}

/*
 * Totals: <tfoot> cells with data-sum hold the sum of their column over the visible rows
 * (cell value: data-f, else data-sort, else text). data-sum="bytes" formats the result as a size.
 */
function updateSums(table, rows) {
    const foot = table.tFoot?.rows[0];
    if (!foot) return;
    let col = 0;
    [...foot.cells].forEach((cell) => {
        if (cell.dataset.sum !== undefined) {
            const total = rows.reduce((acc, r) => {
                const c = r.cells[col];
                const v = Number((c?.dataset.f ?? c?.dataset.sort ?? c?.textContent ?? '').trim());
                return acc + (Number.isFinite(v) ? v : 0);
            }, 0);
            cell.textContent = cell.dataset.sum === 'bytes' ? fmtBytes(total) : String(total);
        }
        col += cell.colSpan || 1;
    });
}

function tableFor(el) {
    if (el.dataset.showLocal) return document.getElementById(el.dataset.showLocal);
    if (el.dataset.filter) return document.getElementById(el.dataset.filter);
    if (el.dataset.cf) return el.closest('table');
    return null;
}

['input', 'change'].forEach((type) => document.addEventListener(type, (e) => {
    const el = e.target.closest('[data-filter], [data-cf], [data-show-local]');
    const table = el && tableFor(el);
    if (table) applyFilters(table);
}));

// the browser may restore a checked "show local" box on back/forward: sync the table with it
window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-show-local]').forEach((el) => { const t = tableFor(el); if (t) applyFilters(t); });
});

// <button data-clear-filters="tableId"> resets the quick filter and all column filters of that table
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-clear-filters]');
    if (!btn) return;
    const table = document.getElementById(btn.dataset.clearFilters);
    if (!table) return;
    const quick = document.querySelector(`[data-filter="${table.id}"]`);
    if (quick) quick.value = '';
    table.querySelectorAll('[data-cf]').forEach((c) => { if (c.type === 'checkbox') c.checked = false; else c.value = ''; });
    applyFilters(table);
});

/*
 * "Ask AI" on a Checkup finding. The question (prompt) is built by the server; the assistant opens in the user's
 * own browser — prefilled through its URL, or the question is copied to the clipboard first. Config: window.nxtvmAi.
 */
const AI_LANG = { en: 'English', tr: 'Turkish', de: 'German', fr: 'French', es: 'Spanish', it: 'Italian', nl: 'Dutch' };

function aiLanguageLine(setting) {
    const code = (setting === 'system' ? (navigator.language || 'en') : setting).slice(0, 2).toLowerCase();
    if (code === 'en') return '';
    let name = AI_LANG[code];
    try { name ??= new Intl.DisplayNames(['en'], { type: 'language' }).of(code); } catch { /* ignore */ }
    return name ? `Please answer in ${name}.` : '';
}

function copyText(text) {
    if (navigator.clipboard?.writeText) {
        return navigator.clipboard.writeText(text).then(() => true, () => legacyCopy(text));
    }
    return Promise.resolve(legacyCopy(text));
}

function legacyCopy(text) {
    const ta = Object.assign(document.createElement('textarea'), { value: text });
    ta.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch { /* ignore */ }
    ta.remove();
    return ok;
}

Alpine.data('askAi', (prompt, fixed = false) => ({
    open: false,
    fixed,      // rows sit in a scrolling table: the popover is positioned against the viewport instead
    pos: '',
    showText: false,
    text: '',
    toast: '',
    init() {
        this.$watch('showText', () => this.$nextTick(() => this.place()));
        this.$watch('open', (v) => { if (!v) this.unfollow(); });
        const cfg = window.nxtvmAi || {};
        this.text = prompt.replace('{language}', aiLanguageLine(cfg.language || 'system')).trim();
    },
    toggle() {
        this.open = !this.open;
        if (!this.fixed) return;
        if (this.open) {
            this.$nextTick(() => this.place());
            // follow the button while the page scrolls; scrolling inside the popover itself (textarea) is ignored
            this._follow = (e) => {
                if (e?.target instanceof Node && this.$refs.panel?.contains(e.target)) return;
                this.place();
            };
            window.addEventListener('scroll', this._follow, true);
            window.addEventListener('resize', this._follow);
        } else {
            this.unfollow();
        }
    },
    unfollow() {
        if (!this._follow) return;
        window.removeEventListener('scroll', this._follow, true);
        window.removeEventListener('resize', this._follow);
        this._follow = null;
    },
    /* Position the fixed popover next to its button: below if it fits, otherwise above; never off-screen. */
    place() {
        if (!this.open || !this.fixed) return;
        const r = this.$refs.trigger.getBoundingClientRect();
        if (r.bottom < 0 || r.top > window.innerHeight) {   // button scrolled out of view
            this.open = false;
            this.unfollow();
            return;
        }
        const w = Math.min(416, window.innerWidth - 32);
        const h = this.$refs.panel?.offsetHeight || 300;
        const left = Math.max(16, Math.min(r.right - w, window.innerWidth - w - 16));
        let top = r.bottom + 8;
        if (top + h > window.innerHeight - 16 && r.top - 8 - h >= 16) top = r.top - 8 - h;
        top = Math.max(16, Math.min(top, window.innerHeight - h - 16));
        this.pos = `top:${top}px;left:${left}px`;
    },
    get providers() { return (window.nxtvmAi || {}).providers || []; },
    notify(msg) {
        this.toast = msg;
        clearTimeout(this._t);
        this._t = setTimeout(() => (this.toast = ''), 4500);
    },
    copy() {
        copyText(this.text).then((ok) => this.notify(ok ? this.$el.dataset.lCopied : this.$el.dataset.lCopyFailed));
    },
    ask(p) {
        const cfg = window.nxtvmAi || {};
        let url = p.url;
        const prefill = p.mode === 'prefill' && url.includes('{prompt}');
        if (prefill) url = url.replace('{prompt}', encodeURIComponent(this.text));
        if (!prefill || url.length > 7500) {
            // too long for a URL, or the assistant takes no prefilled question: copy, open its start page
            copyText(this.text).then((ok) => ok && this.notify(this.$el.dataset.lPaste.replace(':name', p.label)));
            url = p.url.replace(/[?&][^?&]*\{prompt\}.*$/, '').replace('{prompt}', '');
        }
        let win;
        if (cfg.popup) {
            const w = Math.min(cfg.width || 520, screen.availWidth);
            const left = (screen.availLeft ?? 0) + screen.availWidth - w;
            win = window.open(url, 'nxtvm-ai', `popup=yes,width=${w},height=${screen.availHeight},left=${left},top=${screen.availTop ?? 0}`);
        } else {
            win = window.open(url, '_blank', 'noopener');
        }
        win?.focus?.();
        this.open = false;
    },
}));

window.Alpine = Alpine;
Alpine.start();

/*
 * Crosshair on <table data-crosshair>: besides the row (CSS :hover), the hovered cell's column gets .xh-col in the
 * body and in the last header row. Rows of the body must not use colspan (column = cellIndex).
 */
let xhKey = null; // "tableId:column" currently highlighted
document.addEventListener('mouseover', (e) => {
    const cell = e.target.closest('table[data-crosshair] td, table[data-crosshair] thead tr:last-child th');
    const key = cell ? `${cell.closest('table').id}:${cell.cellIndex}` : null;
    if (key === xhKey) return;
    xhKey = key;
    document.querySelectorAll('.xh-col').forEach((c) => c.classList.remove('xh-col'));
    if (!cell) return;
    const table = cell.closest('table');
    const col = cell.cellIndex;
    [table.tHead?.rows[table.tHead.rows.length - 1], ...table.tBodies[0].rows].forEach((r) => r?.cells[col]?.classList.add('xh-col'));
});

/* Export buttons: <button data-export="tableId" data-format="xlsx|csv" data-name="file-name">; the exporter loads on first use */
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-export]');
    if (!btn) return;
    const table = document.getElementById(btn.dataset.export);
    if (!table) return;
    const { exportTable } = await import('./export');
    exportTable(table, btn.dataset.format || 'xlsx', btn.dataset.name || btn.dataset.export);
});

/*
 * Hover card: an element with data-hovercard and a <template> child shows the template's content in a floating card
 * while hovered. The card stays open while the pointer is on it (so its buttons can be used).
 */
const hovercard = Object.assign(document.createElement('div'), { className: 'card hovercard', hidden: true });
if (document.body) document.body.append(hovercard);
else document.addEventListener('DOMContentLoaded', () => document.body.append(hovercard));
let hcOwner = null;
let hcTimer = null;

function hcShow(owner) {
    clearTimeout(hcTimer);
    if (hcOwner === owner) return;
    hcOwner = owner;
    hovercard.replaceChildren(owner.querySelector(':scope > template').content.cloneNode(true));
    hovercard.hidden = false;
    const r = owner.getBoundingClientRect();
    const c = hovercard.getBoundingClientRect();
    let top = r.bottom + 6;
    if (top + c.height > window.innerHeight - 8) top = Math.max(8, r.top - c.height - 6);
    hovercard.style.top = `${top}px`;
    hovercard.style.left = `${Math.max(8, Math.min(r.left + r.width / 2 - c.width / 2, window.innerWidth - c.width - 8))}px`;
}

function hcHideSoon() {
    clearTimeout(hcTimer);
    hcTimer = setTimeout(() => { hovercard.hidden = true; hcOwner = null; }, 150);
}

document.addEventListener('mouseover', (e) => {
    const owner = e.target.closest('[data-hovercard]');
    if (owner && owner.querySelector(':scope > template')) hcShow(owner);
    else if (hovercard.contains(e.target)) clearTimeout(hcTimer);
    else if (hcOwner) hcHideSoon();
});
window.addEventListener('scroll', () => { if (hcOwner) { hovercard.hidden = true; hcOwner = null; } }, true);

/* <button data-copy="text">: copies the text; a [data-copy-label] inside briefly says so */
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    const ok = await copyText(btn.dataset.copy);
    const label = btn.querySelector('[data-copy-label]');
    if (!label) return;
    const before = label.dataset.text ??= label.textContent;
    label.textContent = ok === false ? '✗' : '✓';
    setTimeout(() => { label.textContent = before; }, 1200);
});
