/*
 * Click-to-sort for every <table class="table"> with a <thead> (opt out with data-nosort).
 * A cell's sort value is its data-sort attribute when present (raw bytes, seconds, timestamps, percent),
 * otherwise its text. Numbers compare numerically, text naturally; empty values ("—") always go last.
 * Rows whose cells don't match the header (colspan notes) stay at the bottom.
 * Tables with data-groups keep each <tbody> together and order the groups by their first row.
 */
const EMPTY = new Set(['', '—', '-', '…']);

function value(row, idx) {
    const cell = row.cells[idx];
    if (!cell) return null;
    const raw = (cell.dataset.sort ?? cell.innerText).trim();
    if (EMPTY.has(raw)) return null;
    const n = Number(raw.replace(/[%\s]/g, '').replace(',', '.'));
    return Number.isFinite(n) && /^-?[\d.,]+\s*%?$/.test(raw) ? n : raw.toLowerCase();
}

// the header row that carries the column titles (filter rows marked data-filters are skipped)
function headRow(table) {
    return [...table.tHead.rows].filter((r) => r.dataset.filters === undefined).pop();
}

function compare(a, b) {
    if (a === null && b === null) return 0;
    if (a === null) return 1;
    if (b === null) return -1;
    if (typeof a === 'number' && typeof b === 'number') return a - b;
    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
}

function sortGroups(table, idx, dir) {
    [...table.tBodies]
        .map((b, i) => ({ b, v: value(b.rows[0], idx), i }))
        .sort((x, y) => {
            const c = compare(x.v, y.v);
            if (x.v === null || y.v === null) return c || x.i - y.i;
            return (dir === 'asc' ? c : -c) || x.i - y.i;
        })
        .forEach(({ b }) => table.appendChild(b));
}

function sortTable(table, idx, dir) {
    if (table.dataset.groups !== undefined) return sortGroups(table, idx, dir);
    const body = table.tBodies[0];
    if (!body) return;
    const cols = headRow(table).cells.length;
    const rows = [...body.rows];
    const sortable = rows.filter((r) => r.cells.length === cols);
    const rest = rows.filter((r) => r.cells.length !== cols);
    sortable
        .map((r, i) => ({ r, v: value(r, idx), i }))
        .sort((x, y) => {
            const c = compare(x.v, y.v);
            // empty values stay last in both directions; ties keep the original order
            if (x.v === null || y.v === null) return c || x.i - y.i;
            return (dir === 'asc' ? c : -c) || x.i - y.i;
        })
        .forEach(({ r }) => body.appendChild(r));
    rest.forEach((r) => body.appendChild(r));
}

function enhance(table) {
    if (table.dataset.nosort !== undefined || table.dataset.sortReady || !table.tHead) return;
    table.dataset.sortReady = '1';
    const headers = [...headRow(table).cells];
    headers.forEach((th, idx) => {
        if (!th.innerText.trim() || th.dataset.nosort !== undefined) return;
        th.classList.add('th-sort');
        th.setAttribute('role', 'button');
        th.tabIndex = 0;
        const run = () => {
            const dir = th.dataset.dir === 'asc' ? 'desc' : 'asc';
            headers.forEach((h) => { delete h.dataset.dir; h.removeAttribute('aria-sort'); });
            th.dataset.dir = dir;
            th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
            sortTable(table, idx, dir);
        };
        th.addEventListener('click', run);
        th.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); run(); } });
    });
}

export function initSortable(root = document) {
    root.querySelectorAll('table.table').forEach(enhance);
}

document.addEventListener('DOMContentLoaded', () => initSortable());
