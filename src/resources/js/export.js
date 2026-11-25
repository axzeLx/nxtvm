/*
 * Table export (loaded on demand): what the user sees — the rows left by the filters, in the current sort order.
 *  - header: the last <thead> row that is not the column-filter row (multi-row headers keep their bottom row)
 *  - columns without a header text are dropped when all their cells are empty (bars, dots); th[data-noexport] always
 *  - numbers: integers become numbers; in right-aligned (numeric) columns decimals too. Versions like "8.0" stay text.
 */
import { zipSync, strToU8 } from 'fflate';

function headerRow(table) {
    const rows = [...(table.tHead?.rows ?? [])].filter((r) => !r.hasAttribute('data-filters'));
    return rows[rows.length - 1];
}

const text = (cell) => (cell?.dataset.export ?? cell?.textContent ?? '').replace(/\s+/g, ' ').trim();

export function tableData(table) {
    const head = headerRow(table);
    const ths = head ? [...head.cells] : [];
    const body = [...table.tBodies].flatMap((tb) => [...tb.rows]).filter((r) => r.getClientRects().length > 0);
    const raw = body.map((r) => [...r.cells].map(text));

    const cols = ths
        .map((th, i) => ({ i, title: text(th), numeric: th.classList.contains('text-right'), skip: th.hasAttribute('data-noexport') }))
        .filter((c) => !c.skip && (c.title || raw.some((r) => r[c.i])));

    const value = (v, numeric) => {
        if (/^-?(0|[1-9]\d{0,14})$/.test(v) || (numeric && /^-?\d+\.\d+$/.test(v))) return Number(v);
        return v;
    };

    return {
        header: cols.map((c) => c.title),
        rows: raw.map((r) => cols.map((c) => value(r[c.i] ?? '', c.numeric))),
    };
}

function download(blob, name) {
    const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: name });
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

/* CSV for Excel with a Turkish locale: ';' separator, decimal comma, UTF-8 BOM so ç/ğ/ş show correctly */
export function toCsv({ header, rows }) {
    const q = (v) => (typeof v === 'number' ? String(v).replace('.', ',') : /[";\n\r]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v);
    return '﻿' + [header, ...rows].map((r) => r.map(q).join(';')).join('\r\n');
}

const esc = (s) => String(s).replace(/[<>&"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' })[c])
    // characters XML 1.0 does not allow
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '');

function colName(n) {
    let s = '';
    for (n += 1; n > 0; n = Math.floor((n - 1) / 26)) s = String.fromCharCode(65 + ((n - 1) % 26)) + s;
    return s;
}

/* Minimal .xlsx: one sheet, inline strings, bold frozen header row with autofilter, column widths from content */
export function toXlsx({ header, rows }, sheetName = 'Sheet1') {
    const all = [header, ...rows];
    const widths = header.map((_, c) => Math.min(60, Math.max(6, ...all.map((r) => String(r[c] ?? '').length)) + 2));
    const cell = (v, ref, style) => typeof v === 'number'
        ? `<c r="${ref}"${style}><v>${v}</v></c>`
        : v === '' ? '' : `<c r="${ref}" t="inlineStr"${style}><is><t xml:space="preserve">${esc(v)}</t></is></c>`;
    const sheetRows = all.map((r, ri) => `<row r="${ri + 1}">${r.map((v, ci) => cell(v, colName(ci) + (ri + 1), ri === 0 ? ' s="1"' : '')).join('')}</row>`).join('');
    const lastCol = colName(Math.max(0, header.length - 1));
    const last = lastCol + all.length;
    const safeName = sheetName.replace(/[\\/?*[\]:']/g, ' ').slice(0, 31) || 'Sheet1';

    const sheet = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>
<cols>${widths.map((w, i) => `<col min="${i + 1}" max="${i + 1}" width="${w}" customWidth="1"/>`).join('')}</cols>
<sheetData>${sheetRows}</sheetData>
<autoFilter ref="A1:${last}"/>
</worksheet>`;

    const files = {
        '[Content_Types].xml': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>`,
        '_rels/.rels': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>`,
        'xl/workbook.xml': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="${esc(safeName)}" sheetId="1" r:id="rId1"/></sheets>
<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'${esc(safeName)}'!$A$1:$${lastCol}$${all.length}</definedName></definedNames>
</workbook>`,
        'xl/_rels/workbook.xml.rels': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>`,
        'xl/worksheets/sheet1.xml': sheet,
        'xl/styles.xml': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>
<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>
</styleSheet>`,
    };

    return zipSync(Object.fromEntries(Object.entries(files).map(([k, v]) => [k, strToU8(v)])));
}

export function exportTable(table, format, name) {
    const data = tableData(table);
    const stamp = new Date().toISOString().slice(0, 16).replace(/[-:]/g, '').replace('T', '-');
    const file = `${name}-${stamp}`;
    if (format === 'csv') {
        download(new Blob([toCsv(data)], { type: 'text/csv;charset=utf-8' }), `${file}.csv`);
    } else {
        download(new Blob([toXlsx(data, name)], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), `${file}.xlsx`);
    }
}
