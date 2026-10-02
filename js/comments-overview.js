/**
 * Kommentare einsehen und exportieren (ab 0.35.0, Vikunja #5).
 *
 * Wird in den persoenlichen Einstellungen (Kommentare zu den eigenen
 * Freigaben) und in der Verwaltung (alle Kommentare) eingebunden: Jedes
 * Element mit der Klasse "aa-comments-overview" wird zu einer Tabelle mit
 * Suchfeld, CSV-Download (fuer Excel) und Druckansicht (auch "Als PDF
 * sichern" im Druckfenster).
 *
 * data-scope="mine" | "all"; data-switch="1" zeigt fuer Berechtigte eine
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
    const blob = new Blob([text], { type: 'text/csv;charset=utf-8' });
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
    const csvBtn = h('button', '', 'Für Excel herunterladen (CSV)');
    csvBtn.type = 'button';
    csvBtn.addEventListener('click', () => download('audioarchive-kommentare-' + today() + '.csv', toCsv(visible())));
    const printBtn = h('button', '', 'Drucken / als PDF');
    printBtn.type = 'button';
    printBtn.title = 'Im Druckfenster „Als PDF sichern“ wählen, um eine PDF-Datei zu erhalten';
    printBtn.addEventListener('click', () => {
      document.body.classList.add('aa-co-printing');
      root.classList.add('aa-co-print-target');
      const done = () => {
        document.body.classList.remove('aa-co-printing');
        root.classList.remove('aa-co-print-target');
        window.removeEventListener('afterprint', done);
      };
      window.addEventListener('afterprint', done);
      window.print();
      setTimeout(done, 1000); // falls 'afterprint' fehlt
    });
    bar.append(select, search, csvBtn, printBtn);

    const status = h('p', 'settings-hint aa-co-status', 'Kommentare werden geladen …');
    const printTitle = h('h3', 'aa-co-print-title');
    const table = h('table', 'aa-co-table');
    table.hidden = true;
    const thead = h('thead');
    const headRow = h('tr');
    COLUMNS.forEach((col) => headRow.appendChild(h('th', '', col[0])));
    thead.appendChild(headRow);
    const tbody = h('tbody');
    table.append(thead, tbody);
    root.append(bar, printTitle, status, table);

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
        COLUMNS.forEach((col, i) => {
          const td = h('td', i === COLUMNS.length - 1 ? 'aa-co-text' : '', col[1](c));
          tr.appendChild(td);
        });
        tbody.appendChild(tr);
      });
      table.hidden = list.length === 0;
      csvBtn.disabled = printBtn.disabled = list.length === 0;
      printTitle.textContent = 'Audio Archive – ' + (scope === 'all' ? 'Alle Kommentare' : 'Kommentare zu meinen Freigaben')
        + ' (Stand ' + formatDate(Date.now() / 1000) + ')';
      if (comments.length === 0) {
        status.textContent = scope === 'all'
          ? 'Es gibt noch keine Kommentare zu Aufnahmen.'
          : 'Zu Aufnahmen in deinen Freigaben gibt es noch keine Kommentare.';
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
