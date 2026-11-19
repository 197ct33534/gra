#!/usr/bin/env node
/**
 * PCL csv -> html table, for COPY-PASTING into Google Sheets / Excel.
 *
 *   node <this> path/to/sheet.csv
 *
 * WHY THIS EXISTS
 * ---------------
 * The .csv is correct, but pasting its text can never work: the clipboard's plain-text path splits
 * only on TAB and NEWLINE and has no CSV parser, so a newline inside a quoted field ends the row
 * there and every multi-line cell explodes down column A. That is not fixable by quoting more
 * carefully -- it is what plain text is.
 *
 * The clipboard's HTML path DOES carry table structure, and a <br> inside a <td> arrives as a real
 * in-cell line break. So: open this output in a browser, click Copy, paste. Cells and line breaks
 * survive intact.
 *
 * The .csv stays the single source of truth; this is only a renderer.
 */

const fs = require('fs');
const path = require('path');

/** Minimal RFC4180 reader: quoted fields, doubled quotes, newlines inside a field. */
function parseCsv(text) {
  const rows = [];
  let row = [];
  let field = '';
  let inQuotes = false;

  for (let i = 0; i < text.length; i++) {
    const ch = text[i];

    if (inQuotes) {
      if (ch === '"') {
        if (text[i + 1] === '"') { field += '"'; i++; } else { inQuotes = false; }
      } else {
        field += ch;
      }
      continue;
    }

    if (ch === '"') { inQuotes = true; continue; }
    if (ch === ',') { row.push(field); field = ''; continue; }
    if (ch === '\r') continue;

    if (ch === '\n') {
      row.push(field);
      field = '';
      if (row.length > 1 || row[0] !== '') rows.push(row);
      row = [];
      continue;
    }

    field += ch;
  }

  if (field !== '' || row.length) { row.push(field); rows.push(row); }

  return rows;
}

const esc = (s) => String(s)
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/\n/g, '<br>');

const arg = process.argv[2];

if (!arg) {
  console.error('usage: node csv_to_html.cjs path/to/sheet.csv');
  process.exit(1);
}

const file = path.resolve(process.cwd(), arg);

if (!fs.existsSync(file)) {
  console.error('csv not found: ' + file);
  process.exit(1);
}

const raw = fs.readFileSync(file, 'utf8').replace(/^﻿/, '');
const rows = parseCsv(raw);

const [header, ...body] = rows;
const colCount = header.length;

if (colCount < 5) {
  console.error(`header has ${colCount} columns (expected at least 5)`);
  process.exit(1);
}

const bad = body.findIndex((r) => r.length !== colCount);
if (bad !== -1) {
  // A missing quote swallows every following row into one cell. Invisible once styled, so refuse.
  console.error(`record ${bad + 2} has ${body[bad].length} columns (expected ${colCount}) - fix the csv first`);
  process.exit(1);
}

const title = path.basename(file, '.csv');
// Count column is second-to-last; confirmation/URL last columns get monospace on the final one.
const countIdx = colCount >= 5 ? colCount - 1 : colCount; // 1-based nth-child of count = colCount-1
const urlIdx = colCount; // last column

const html = `<!doctype html>
<meta charset="utf-8">
<title>${esc(title)}</title>
<style>
  body { font-family: "Segoe UI", Meiryo, sans-serif; margin: 24px; color: #202124; background: #fff; }
  .bar { margin-bottom: 16px; }
  button { font-size: 15px; padding: 9px 18px; cursor: pointer; border: 1px solid #4a7; background: #e6f4ea; border-radius: 6px; }
  .hint { font-size: 13px; color: #5f6368; margin-top: 8px; line-height: 1.6; }
  table { border-collapse: collapse; font-size: 12.5px; }
  th, td { border: 1px solid #9BA7B0; padding: 6px 9px; vertical-align: top; text-align: left; }
  th { background: #D9EAD3; font-weight: 700; }
  th:last-child { background: #FFF2CC; }
  td { background: #DDEBF7; }
  td:nth-child(1) { min-width: 130px; max-width: 160px; }
  td:nth-child(2) { min-width: 180px; max-width: 240px; }
  td:nth-child(${countIdx}) { width: 50px; text-align: center; }
  td:nth-child(${urlIdx}) { min-width: 280px; font-family: Consolas, monospace; font-size: 11.5px; }
</style>

<div class="bar">
  <button id="copy">Copy toàn bộ bảng</button>
  <div class="hint">
    Bấm nút → dán thẳng vào Google Sheets / Excel. Ô nhiều dòng giữ nguyên xuống dòng.<br>
    Nếu nút không chạy: bôi đen từ ô đầu đến ô cuối <b>của bảng</b> (đừng Ctrl+A cả trang) rồi Ctrl+C.
  </div>
</div>

<table id="pcl">
<thead><tr>${header.map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead>
<tbody>
${body.map((r) => `<tr>${r.map((c) => `<td>${esc(c)}</td>`).join('')}</tr>`).join('\n')}
</tbody>
</table>

<script>
document.getElementById('copy').addEventListener('click', function () {
  // Range + execCommand, not navigator.clipboard: a page opened from file:// is not a secure
  // context in Chrome, so clipboard.write() is unavailable there. Selecting the node and copying
  // puts text/html on the clipboard, which is what carries the cell structure.
  var range = document.createRange();
  range.selectNode(document.getElementById('pcl'));
  var sel = window.getSelection();
  sel.removeAllRanges();
  sel.addRange(range);

  var ok = false;
  try { ok = document.execCommand('copy'); } catch (e) { ok = false; }

  sel.removeAllRanges();
  this.textContent = ok ? 'Đã copy — dán vào Sheets/Excel' : 'Không copy được — bôi đen bảng rồi Ctrl+C';
});
</script>
`;

const out = file.replace(/\.csv$/, '.html');
fs.writeFileSync(out, html, 'utf8');

console.log('source :', path.basename(file));
console.log('cases  :', body.length);
console.log('html   :', out);
