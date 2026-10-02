/**
 * Kommentare einsehen und exportieren (ab 0.35.0, Vikunja #5).
 *
 * Wird in den persoenlichen Einstellungen (eigene Kommentare und die ueber
 * die eigenen Freigaben) und in der Verwaltung (alle Kommentare)
 * eingebunden: Jedes Element mit der Klasse "aa-comments-overview" wird zu
 * einer Tabelle mit Suchfeld, Sprung zur Aufnahme (ab 0.36.0), Excel-Datei
 * (.xlsx, auch direkt in Nextcloud ablegen und mit Office oeffnen, ab
 * 0.36.0), CSV und Druckansicht (eigenes Fenster mit Raendern, Querformat).
 *
 * data-scope="mine" | "all"; data-switch="1" zeigt dem Administrator eine
 * Auswahl zwischen beidem.
 */
(function () {
  'use strict';

  const APP_ID = 'audioarchive';

  function h(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  function formatDate(ts) {
    const d = new Date(ts * 1000);
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
      + ', ' + d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
  }

  function stars(n) {
    return n > 0 ? '★'.repeat(n) + '☆'.repeat(5 - n) : '';
  }

  const COLUMNS = [
    ['Datum', (c) => formatDate(c.created)],
    ['Aufnahme', (c) => c.file],
    ['Ordner', (c) => c.folder],
    ['Bereich', (c) => c.source],
    ['Von', (c) => c.author + (c.reply ? ' (Antwort)' : '')],
    ['Bewertung', (c) => stars(c.rating)],
    ['Kommentar', (c) => c.text],
  ];

  const APP_URL = () => OC.generateUrl('/apps/' + APP_ID + '/');

  /** Adresse der Aufnahme in der App (#open=, ab 0.36.0), oder null. */
  function openUrl(c) {
    if (!c.open || !c.open.source || !c.open.path) return null;
    return APP_URL() + '#open=' + encodeURIComponent(c.open.source) + '|' + encodeURIComponent(c.open.path);
  }

  function esc(s) {
    return String(s === undefined || s === null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /** CSV fuer Excel: UTF-8 mit BOM, Semikolon, Zeilenende CRLF. */
  function toCsv(list) {
    const cell = (v) => '"' + String(v === undefined || v === null ? '' : v).replace(/"/g, '""') + '"';
    const lines = [COLUMNS.map((col) => cell(col[0])).join(';')];
    list.forEach((c) => {
      lines.push(COLUMNS.map((col) => cell(col[0] === 'Bewertung' ? (c.rating || '') : col[1](c))).join(';'));
    });
    return '﻿' + lines.join('\r\n') + '\r\n';
  }

  function download(name, text) {
    downloadBlob(name, new Blob([text], { type: 'text/csv;charset=utf-8' }));
  }

  function downloadBlob(name, blob) {
    const url = URL.createObjectURL(blob);
    const a = h('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
  }

  function today() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function mount(root) {
    let scope = root.dataset.scope === 'all' ? 'all' : 'mine';
    let comments = [];
    let filter = '';

    const bar = h('div', 'aa-co-bar');
    const select = h('select', 'aa-co-scope');
    select.hidden = true;
    [['mine', 'Kommentare zu meinen Freigaben'], ['all', 'Alle Kommentare']].forEach(([v, label]) => {
      const o = h('option', '', label);
      o.value = v;
      select.appendChild(o);
    });
    select.addEventListener('change', () => { scope = select.value; load(); });
    const search = h('input', 'aa-co-search');
    search.type = 'search';
    search.placeholder = 'Filtern: Aufnahme, Name, Text …';
    search.addEventListener('input', () => { filter = search.value.trim().toLowerCase(); render(); });
    // Excel-Datei vom Server (ab 0.36.0): herunterladen oder in Nextcloud ablegen
    async function exportXlsx(save) {
      const res = await fetch(OC.generateUrl('/apps/' + APP_ID + '/api/comments/export'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
        body: JSON.stringify({ scope, q: filter, save }),
      });
      if (!res.ok) {
        let msg = 'Fehler ' + res.status;
        try { msg = (await res.json()).error || msg; } catch (e) { /* kein JSON */ }
        throw new Error(msg);
      }
      return save ? res.json() : res.blob();
    }
    const xlsxBtn = h('button', '', 'Excel-Datei (.xlsx)');
    xlsxBtn.type = 'button';
    xlsxBtn.addEventListener('click', async () => {
      xlsxBtn.disabled = true;
      try {
        const blob = await exportXlsx(false);
        downloadBlob('audioarchive-kommentare-' + today() + '.xlsx', blob);
      } catch (err) {
        status.textContent = 'Excel-Datei ging nicht: ' + err.message;
      }
      xlsxBtn.disabled = false;
    });
    const officeBtn = h('button', '', 'In Nextcloud öffnen (Office)');
    officeBtn.type = 'button';
    officeBtn.title = 'Legt die Excel-Datei in deinen Dateien unter „Audio Archive“ ab und öffnet sie mit dem Office-Programm der Nextcloud';
    officeBtn.addEventListener('click', async () => {
      officeBtn.disabled = true;
      // Fenster sofort oeffnen - nach dem Warten blockiert der Browser es sonst
      const win = window.open('', '_blank');
      try {
        const data = await exportXlsx(true);
        if (win) {
          win.location.href = data.url;
        } else {
          window.location.href = data.url;
        }
        status.textContent = 'Gespeichert in deinen Dateien: ' + data.path;
      } catch (err) {
        if (win) win.close();
        status.textContent = 'Ablegen in Nextcloud ging nicht: ' + err.message;
      }
      officeBtn.disabled = false;
    });
    const csvBtn = h('button', '', 'CSV');
    csvBtn.type = 'button';
    csvBtn.title = 'Einfache Textdatei mit Semikolons – öffnet sich ebenfalls in Excel';
    csvBtn.addEventListener('click', () => download('audioarchive-kommentare-' + today() + '.csv', toCsv(visible())));
    const printBtn = h('button', '', 'Drucken / als PDF');
    printBtn.type = 'button';
    printBtn.title = 'Öffnet eine Druckansicht – im Druckfenster „Als PDF sichern“ wählen, um eine PDF-Datei zu erhalten';
    printBtn.addEventListener('click', () => printView(visible()));
    bar.append(select, search, xlsxBtn, officeBtn, csvBtn, printBtn);

    const status = h('p', 'settings-hint aa-co-status', 'Kommentare werden geladen …');
    const table = h('table', 'aa-co-table');
    table.hidden = true;
    const thead = h('thead');
    const headRow = h('tr');
    headRow.appendChild(h('th', 'aa-co-go', ''));
    COLUMNS.forEach((col) => headRow.appendChild(h('th', '', col[0])));
    thead.appendChild(headRow);
    const tbody = h('tbody');
    table.append(thead, tbody);
    root.append(bar, status, table);

    /**
     * Druckansicht (ab 0.36.0, Vikunja #5): eigenes Fenster nur mit der
     * Tabelle - Querformat, Seitenraender, Kopfzeile auf jeder Seite, lange
     * Texte umbrochen, so dass alle Spalten aufs Blatt passen.
     */
    function printView(list) {
      const win = window.open('', '_blank');
      if (!win) {
        status.textContent = 'Das Druckfenster wurde vom Browser blockiert – bitte Pop-ups für diese Seite erlauben.';
        return;
      }
      const title = 'Audio Archive – ' + (scope === 'all' ? 'Alle Kommentare' : 'Meine Kommentare und Kommentare über meine Freigaben');
      const head = '<tr>' + COLUMNS.map((col) => '<th>' + esc(col[0]) + '</th>').join('') + '</tr>';
      const body = list.map((c) => '<tr>' + COLUMNS.map((col, i) => '<td class="c' + i + '">' + esc(col[1](c)) + '</td>').join('') + '</tr>').join('');
      win.document.open();
      win.document.write('<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>' + esc(title) + '</title><style>'
        + '@page{size:A4 landscape;margin:14mm 12mm 16mm}'
        + 'body{font:10pt/1.35 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#111;margin:0;padding:16px}'
        + '@media print{body{padding:0}}'
        + 'h1{font-size:15pt;margin:0 0 2px}.sub{color:#555;font-size:9pt;margin:0 0 12px}'
        + 'table{width:100%;border-collapse:collapse;table-layout:fixed}'
        + 'thead{display:table-header-group}tr{break-inside:avoid;page-break-inside:avoid}'
        + 'th{background:#e9e9e9;text-align:left;font-weight:600;padding:5px 6px;border-bottom:1.5px solid #888}'
        + 'td{padding:5px 6px;border-bottom:1px solid #ccc;vertical-align:top;overflow-wrap:anywhere;word-break:break-word}'
        + 'tbody tr:nth-child(even) td{background:#f6f6f6}'
        + '.c0{width:13%}.c1{width:16%}.c2{width:14%}.c3{width:12%}.c4{width:11%}.c5{width:8%;white-space:nowrap}.c6{width:26%;white-space:pre-wrap}'
        + 'th:nth-child(1){width:13%}th:nth-child(2){width:16%}th:nth-child(3){width:14%}th:nth-child(4){width:12%}th:nth-child(5){width:11%}th:nth-child(6){width:8%}th:nth-child(7){width:26%}'
        + '</style></head><body><h1>' + esc(title) + '</h1><p class="sub">Stand ' + esc(formatDate(Date.now() / 1000))
        + ' · ' + list.length + (list.length === 1 ? ' Kommentar' : ' Kommentare') + (filter ? ' · gefiltert nach „' + esc(filter) + '“' : '')
        + '</p><table><thead>' + head + '</thead><tbody>' + body + '</tbody></table></body></html>');
      win.document.close();
      win.focus();
      setTimeout(() => win.print(), 300);
    }

    function visible() {
      if (!filter) return comments;
      return comments.filter((c) => [c.file, c.folder, c.source, c.author, c.text]
        .join(' ').toLowerCase().includes(filter));
    }

    function render() {
      const list = visible();
      tbody.innerHTML = '';
      list.forEach((c) => {
        const tr = h('tr');
        // Sprung zur Aufnahme (ab 0.36.0): Knopf links oder Klick auf die Zeile
        const go = h('td', 'aa-co-go');
        const url = openUrl(c);
        if (url) {
          const a = h('a', 'button aa-co-open', '▶');
          a.href = url;
          a.target = '_blank';
          a.rel = 'noopener';
          a.title = 'In Audio Archive öffnen (Ordner der Aufnahme)';
          a.setAttribute('aria-label', 'Aufnahme in Audio Archive öffnen');
          go.appendChild(a);
          tr.classList.add('is-openable');
          tr.addEventListener('click', (ev) => {
            if (ev.target.closest('a')) return;
            window.open(url, '_blank', 'noopener');
          });
        }
        if (c.filesUrl) {
          const f = h('a', 'aa-co-files', '📁');
          f.href = c.filesUrl;
          f.target = '_blank';
          f.rel = 'noopener';
          f.title = 'In „Dateien“ zeigen';
          f.setAttribute('aria-label', 'Datei in Nextcloud-Dateien zeigen');
          go.appendChild(f);
        }
        tr.appendChild(go);
        COLUMNS.forEach((col, i) => {
          const td = h('td', i === COLUMNS.length - 1 ? 'aa-co-text' : '', col[1](c));
          tr.appendChild(td);
        });
        tbody.appendChild(tr);
      });
      table.hidden = list.length === 0;
      csvBtn.disabled = printBtn.disabled = xlsxBtn.disabled = officeBtn.disabled = list.length === 0;

      if (comments.length === 0) {
        status.textContent = scope === 'all'
          ? 'Es gibt noch keine Kommentare zu Aufnahmen.'
          : 'Es gibt noch keine Kommentare von dir oder über deine Freigaben.';
      } else if (list.length === 0) {
        status.textContent = 'Kein Kommentar passt zum Filter.';
      } else {
        status.textContent = list.length + (list.length === 1 ? ' Kommentar' : ' Kommentare')
          + (filter ? ' (gefiltert)' : '') + ', neueste zuerst.';
      }
    }

    async function load() {
      status.textContent = 'Kommentare werden geladen …';
      table.hidden = true;
      try {
        const res = await fetch(OC.generateUrl('/apps/' + APP_ID + '/api/comments/overview') + '?scope=' + scope, {
          headers: { requesttoken: OC.requestToken },
          credentials: 'same-origin',
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || ('Fehler ' + res.status));
        comments = Array.isArray(data.comments) ? data.comments : [];
        if (root.dataset.switch === '1' && data.mayAll) {
          select.hidden = false;
          select.value = scope;
        }
        render();
        if (data.truncated) status.textContent += ' Nur die neuesten werden angezeigt.';
      } catch (err) {
        status.textContent = 'Kommentare konnten nicht geladen werden (' + (err.message || err) + ').';
      }
    }

    load();
  }

  function start() {
    document.querySelectorAll('.aa-comments-overview').forEach(mount);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
