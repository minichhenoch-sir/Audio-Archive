/**
 * Kleiner Text-Editor mit Knopfleiste fuer den "Text ueber den Aufnahmen"
 * (ab 0.37.0, Vikunja #49): Schriftart, Groesse, fett, kursiv, unterstrichen,
 * durchgestrichen, Farbe, Ausrichtung, Listen, Link, Formatierung entfernen.
 *
 * Ohne Bibliothek (die App kommt ohne Aufbauwerkzeuge aus). Das Ergebnis ist
 * HTML; der Server laesst davon nur eine feste Auswahl durch (RichText.php).
 *
 * Verwendung:
 *   const ed = AARichText.attach(textareaOderInput);  // ersetzt das Feld
 *   const ed = AARichText.create();                     // neues Feld, ed.el einhaengen
 *   ed.value = '<p>…</p>';  ed.value  // lesen/schreiben wie bei einem Eingabefeld
 *   ed.placeholder = '…';
 *
 * Wichtig: Den Editor nicht in ein <label> setzen - ein Klick irgendwo im
 * Label wuerde sonst den ersten Knopf der Leiste ausloesen.
 */
(() => {
  'use strict';

  const FONTS = [
    ['', 'Schriftart'],
    ['Georgia, "Times New Roman", serif', 'Mit Serifen'],
    ['Arial, Helvetica, sans-serif', 'Ohne Serifen'],
    ['"Trebuchet MS", "Segoe UI", sans-serif', 'Modern'],
    ['"Brush Script MT", "Segoe Script", "Apple Chancery", cursive', 'Schreibschrift'],
    ['"Courier New", Courier, monospace', 'Schreibmaschine'],
  ];
  const SIZES = [
    ['', 'Größe'],
    ['0.8em', 'Klein'],
    ['1em', 'Normal'],
    ['1.25em', 'Groß'],
    ['1.6em', 'Sehr groß'],
    ['2.2em', 'Riesig'],
  ];

  function h(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  /** Ist im Editor sichtbar etwas eingetragen? */
  function isEmpty(area) {
    if (area.querySelector('hr')) return false;
    return (area.textContent || '').replace(/ /g, ' ').trim() === '';
  }

  function create(options = {}) {
    const root = h('div', 'aa-rt');
    const bar = h('div', 'aa-rt-bar');
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Formatierung');
    const area = h('div', 'aa-rt-area');
    area.contentEditable = 'true';
    area.setAttribute('role', 'textbox');
    area.setAttribute('aria-multiline', 'true');
    if (options.label) area.setAttribute('aria-label', options.label);
    if (options.id) area.id = options.id;
    root.append(bar, area);

    // ---------- Auswahl merken (Klappmenues nehmen dem Feld den Fokus) ----------
    let saved = null;
    function remember() {
      if (!root.isConnected && saved !== null && !document.body.contains(root)) {
        // Editor wurde entfernt (Fenster geschlossen) - nicht mehr lauschen
        document.removeEventListener('selectionchange', remember);
        return;
      }
      const sel = window.getSelection();
      if (sel && sel.rangeCount && area.contains(sel.getRangeAt(0).commonAncestorContainer)) {
        saved = sel.getRangeAt(0).cloneRange();
      }
    }
    function restore() {
      area.focus({ preventScroll: true });
      if (!saved) return;
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(saved);
    }
    document.addEventListener('selectionchange', remember);
    area.addEventListener('keyup', remember);
    area.addEventListener('mouseup', remember);

    function exec(cmd, value) {
      restore();
      try { document.execCommand('styleWithCSS', false, true); } catch (e) { /* aelterer Browser */ }
      document.execCommand(cmd, false, value);
      /*
       * Chrome schreibt beim Formatieren gern die aktuelle Textfarbe des
       * Editors (#222) mit in den Stil. Auf einem dunklen Hintergrund waere
       * der Text dann kaum lesbar - solche "geerbten" Farben entfernen.
       */
      if (cmd !== 'foreColor') {
        const base = getComputedStyle(area).color;
        area.querySelectorAll('[style]').forEach((n) => {
          if (n.style.color && n.style.color === base) n.style.removeProperty('color');
          if (n.style.backgroundColor) n.style.removeProperty('background-color');
          if (!n.getAttribute('style')) n.removeAttribute('style');
        });
      }
      remember();
      area.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /*
     * Groesse: execCommand kennt nur die Stufen 1-7. Wir setzen Stufe 7 und
     * ersetzen sie danach durch die gewaehlte Groesse in em - so passt sie
     * sich der Grundschrift der jeweiligen Gestaltung an.
     */
    function applySize(size) {
      exec('fontSize', '7');
      area.querySelectorAll('font[size="7"]').forEach((f) => {
        const span = document.createElement('span');
        span.style.fontSize = size;
        while (f.firstChild) span.appendChild(f.firstChild);
        f.replaceWith(span);
      });
      area.querySelectorAll('[style*="xxx-large"], [style*="-webkit-xxx-large"]').forEach((n) => {
        n.style.fontSize = size;
      });
    }

    function applyFont(face) {
      exec('fontName', face);
      // Ohne CSS-Modus entsteht <font face>, das der Server ebenfalls kennt
    }

    // ---------- Knopfleiste ----------
    function btn(label, title, onClick, cls) {
      const b = h('button', 'aa-rt-btn' + (cls ? ' ' + cls : ''));
      b.type = 'button';
      b.innerHTML = label;
      b.title = title;
      b.setAttribute('aria-label', title);
      // mousedown verhindern: sonst verliert das Feld die Markierung
      b.addEventListener('mousedown', (e) => e.preventDefault());
      b.addEventListener('click', (e) => { e.preventDefault(); onClick(); });
      return b;
    }
    function select(list, title, onPick) {
      const s = h('select', 'aa-rt-select');
      s.title = title;
      s.setAttribute('aria-label', title);
      list.forEach(([value, text]) => {
        const o = h('option', '', text);
        o.value = value;
        s.appendChild(o);
      });
      s.addEventListener('change', () => {
        if (s.value !== '') onPick(s.value);
        s.value = '';
      });
      return s;
    }
    function group(...items) {
      const g = h('span', 'aa-rt-group');
      g.append(...items);
      return g;
    }

    const color = h('input', 'aa-rt-color');
    color.type = 'color';
    color.value = '#b0302a';
    color.title = 'Schriftfarbe';
    color.setAttribute('aria-label', 'Schriftfarbe');
    color.addEventListener('input', () => exec('foreColor', color.value));
    const colorWrap = h('label', 'aa-rt-btn aa-rt-colorbtn');
    colorWrap.title = 'Schriftfarbe';
    colorWrap.innerHTML = '<span aria-hidden="true">A</span>';
    colorWrap.appendChild(color);

    // Link: kleines Feld statt Browser-Dialog
    const linkRow = h('div', 'aa-rt-linkrow');
    linkRow.hidden = true;
    const linkInput = h('input', 'aa-rt-linkinput');
    linkInput.type = 'url';
    linkInput.placeholder = 'https://…';
    const linkOk = h('button', 'aa-rt-btn aa-rt-linkok', 'Übernehmen');
    linkOk.type = 'button';
    const linkCancel = h('button', 'aa-rt-btn', 'Abbrechen');
    linkCancel.type = 'button';
    linkRow.append(linkInput, linkOk, linkCancel);
    root.insertBefore(linkRow, area);
    function openLink() {
      remember();
      linkRow.hidden = false;
      linkInput.value = '';
      linkInput.focus();
    }
    function applyLink() {
      let url = linkInput.value.trim();
      linkRow.hidden = true;
      if (url === '') return;
      if (!/^(https?:|mailto:)/i.test(url)) url = (url.includes('@') && !url.includes('/') ? 'mailto:' : 'https://') + url;
      const sel = saved;
      if (sel && sel.collapsed) {
        // Nichts markiert: Adresse als Text einfuegen
        exec('insertHTML', '<a href="' + url.replace(/"/g, '&quot;') + '">' + url.replace(/</g, '&lt;') + '</a>');
      } else {
        exec('createLink', url);
      }
    }
    linkOk.addEventListener('click', applyLink);
    linkCancel.addEventListener('click', () => { linkRow.hidden = true; restore(); });
    linkInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); applyLink(); }
      if (e.key === 'Escape') { e.preventDefault(); linkRow.hidden = true; restore(); }
    });

    bar.append(
      group(select(FONTS, 'Schriftart', applyFont), select(SIZES, 'Schriftgröße', applySize)),
      group(
        btn('<b>F</b>', 'Fett', () => exec('bold')),
        btn('<i>K</i>', 'Kursiv', () => exec('italic')),
        btn('<u>U</u>', 'Unterstrichen', () => exec('underline')),
        btn('<s>S</s>', 'Durchgestrichen', () => exec('strikeThrough')),
        colorWrap,
      ),
      group(
        btn(icon('left'), 'Links ausrichten', () => exec('justifyLeft')),
        btn(icon('center'), 'Zentrieren', () => exec('justifyCenter')),
        btn(icon('right'), 'Rechts ausrichten', () => exec('justifyRight')),
      ),
      group(
        btn(icon('ul'), 'Aufzählung', () => exec('insertUnorderedList')),
        btn(icon('ol'), 'Nummerierte Liste', () => exec('insertOrderedList')),
        btn(icon('link'), 'Link einfügen', openLink),
        btn(icon('clear'), 'Formatierung entfernen', () => { exec('removeFormat'); exec('unlink'); }),
      ),
    );

    // Einfuegen nur als reiner Text - Kopien aus Word & Co. bringen sonst
    // seitenweise unsichtbare Formatierung mit
    area.addEventListener('paste', (e) => {
      const text = e.clipboardData && e.clipboardData.getData('text/plain');
      if (typeof text !== 'string') return;
      e.preventDefault();
      document.execCommand('insertText', false, text);
    });
    // Enter erzeugt Absaetze statt <div>
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* egal */ }

    const api = {
      el: root,
      area,
      get value() {
        return isEmpty(area) ? '' : area.innerHTML.trim();
      },
      set value(html) {
        const v = String(html || '').trim();
        if (v !== '' && !v.includes('<')) {
          // Alter, reiner Text (bis 0.36.0): Zeilen als Absaetze
          area.textContent = '';
          v.split(/\n/).forEach((line, i) => {
            if (i > 0) area.appendChild(document.createElement('br'));
            area.appendChild(document.createTextNode(line));
          });
        } else {
          area.innerHTML = v;
        }
        area.classList.toggle('is-empty', isEmpty(area));
      },
      get placeholder() { return area.dataset.placeholder || ''; },
      set placeholder(text) {
        // Vorgabe kann HTML sein - als Platzhalter nur der reine Text
        const tmp = document.createElement('div');
        tmp.innerHTML = String(text || '');
        area.dataset.placeholder = (tmp.textContent || '').replace(/\s+/g, ' ').trim();
      },
      focus() { area.focus(); },
    };
    area.addEventListener('input', () => area.classList.toggle('is-empty', isEmpty(area)));
    api.value = options.value || '';
    if (options.placeholder) api.placeholder = options.placeholder;
    return api;
  }

  /** Vorhandenes Eingabefeld (textarea/input) durch den Editor ersetzen. */
  function attach(field, options = {}) {
    const ed = create({
      value: field.value,
      placeholder: field.placeholder,
      label: field.getAttribute('aria-label') || options.label || '',
      ...options,
    });
    const id = field.id;
    field.replaceWith(ed.el);
    if (id) ed.area.id = id;
    return ed;
  }

  function icon(name) {
    const p = {
      left: '<path d="M4 6h16M4 10h10M4 14h16M4 18h10"/>',
      center: '<path d="M4 6h16M7 10h10M4 14h16M7 18h10"/>',
      right: '<path d="M4 6h16M10 10h10M4 14h16M10 18h10"/>',
      ul: '<circle cx="5" cy="7" r="1.3" fill="currentColor"/><circle cx="5" cy="12" r="1.3" fill="currentColor"/><circle cx="5" cy="17" r="1.3" fill="currentColor"/><path d="M9 7h11M9 12h11M9 17h11"/>',
      ol: '<path d="M9 7h11M9 12h11M9 17h11"/><text x="2.5" y="9" font-size="6" fill="currentColor" stroke="none">1</text><text x="2.5" y="14" font-size="6" fill="currentColor" stroke="none">2</text><text x="2.5" y="19" font-size="6" fill="currentColor" stroke="none">3</text>',
      link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
      clear: '<path d="M6 5h12M12 5l-3 14"/><path d="M15 15l5 5M20 15l-5 5"/>',
    }[name];
    return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">' + p + '</svg>';
  }

  window.AARichText = { create, attach };
})();
