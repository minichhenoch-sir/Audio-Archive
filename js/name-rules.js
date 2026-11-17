/*
 * Namensmuster fuer Freigaben (ab 1.2.0, Vikunja #67).
 *
 * Wer einen Ordner teilt, kann festlegen, wie Datei- und Ordnernamen in der
 * App ANGEZEIGT werden. Die Dateien selbst bleiben unveraendert. Eine Regel
 * besteht aus:
 *
 *   Muster   Wie der Name aufgebaut ist, mit Platzhaltern in {…}:
 *              {Kürzel:3}_{Datum}_{Titel}
 *            Platzhalter:
 *              {x}             beliebiger Text (mindestens ein Zeichen)
 *              {x:3}           genau 3 Zeichen (jede Zahl moeglich)
 *              {x:Zahl}        nur Ziffern
 *              {x:Buchstaben}  nur Buchstaben
 *              {x:Wort}        ein Wort (bis zum naechsten Leer-, Unter-
 *                              oder Bindestrich)
 *              {x:Datum}       ein Datum: 2026-05-12, 2026_05_12, 20260512,
 *                              12.05.2026 oder 2026-05 (Jahr und Monat);
 *                              {Datum} ohne Art ist immer ein Datum
 *            Alles andere muss so im Namen stehen; Gross-/Kleinschreibung
 *            zaehlt nicht, Leerzeichen und Unterstrich gelten als gleich.
 *
 *   Anzeige  Was stattdessen erscheint, mit denselben Platzhaltern:
 *              {Titel} – {Kürzel}, {Datum}
 *            Zusaetze:  {Datum:kurz} 12.05.2026 · {Datum:lang} (Vorgabe)
 *                       12. Mai 2026 · {Datum:Jahr} · {Datum:Monat} Mai ·
 *                       {Datum:Tag} 12 · {x:groß} · {x:klein} ·
 *                       {x:Anfang} erster Buchstabe gross
 *            {Name} ist der ganze urspruengliche Name.
 *
 *   Werte    Ersetzungen, eine je Zeile:  GDB = Gottesdienst
 *            Gilt fuer alle Platzhalter; nur fuer einen bestimmten:
 *              Kürzel.GDB = Gottesdienst
 *
 *   Gilt für Dateien, Ordner oder beides; Unterstriche in den Werten
 *            werden auf Wunsch zu Leerzeichen.
 *
 * Die erste passende Regel gewinnt. Passt keine, bleibt der Name, wie er ist.
 */
window.AANameRules = (() => {
  const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
    'August', 'September', 'Oktober', 'November', 'Dezember'];
  const TOKEN = /\{([^{}:]+?)(?::([^{}]*))?\}/g;
  const LETTERS = 'A-Za-zÀ-ÖØ-öø-ÿĀ-žẞ';
  const DATE_RE = '\\d{4}[-_.]\\d{1,2}[-_.]\\d{1,2}|\\d{8}|\\d{1,2}\\.\\d{1,2}\\.\\d{4}|\\d{4}[-_.]\\d{1,2}';
  const compiled = new Map();

  function escapeRe(text) {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  /** Woertlicher Teil: Leerzeichen/Unterstrich gleichwertig. */
  function literal(text) {
    return text.split(/[\s_]+/).map(escapeRe).join('[\\s_]+');
  }

  /**
   * Muster -> { re, slots: [{name, type}] } oder { error }.
   */
  function compile(pattern) {
    const key = String(pattern || '');
    if (compiled.has(key)) return compiled.get(key);
    let result;
    try {
      if (key.trim() === '') throw new Error('Das Muster ist leer.');
      const slots = [];
      let source = '^';
      let last = 0;
      key.replace(TOKEN, (all, rawName, rawType, offset) => {
        source += literal(key.slice(last, offset));
        last = offset + all.length;
        const name = rawName.trim();
        let type = (rawType || '').trim().toLowerCase();
        // {Datum} ohne Art ist ein Datum
        if (type === '' && name.toLowerCase() === 'datum') type = 'datum';
        let group;
        if (/^\d{1,3}$/.test(type)) group = '(.{' + Math.max(1, parseInt(type, 10)) + '})';
        else if (type === 'zahl') group = '(\\d+)';
        else if (type === 'buchstaben') group = '([' + LETTERS + ']+)';
        else if (type === 'wort') group = '([^\\s_\\-]+)';
        else if (type === 'datum') group = '(' + DATE_RE + ')';
        else if (type === '') group = '(.+?)';
        else throw new Error('Unbekannte Art „' + rawType + '“ bei {' + name + '}.');
        slots.push({ name, type });
        source += group;
        return all;
      });
      source += literal(key.slice(last)) + '$';
      if (slots.length === 0) throw new Error('Das Muster braucht mindestens einen Platzhalter in { }.');
      result = { re: new RegExp(source, 'iu'), slots };
    } catch (e) {
      result = { error: e.message || 'Das Muster ist ungültig.' };
    }
    compiled.set(key, result);
    return result;
  }

  /** Werte-Zeilen -> { all: Map, byName: { name: Map } } (Schluessel klein). */
  function parseMap(text) {
    const all = new Map();
    const byName = {};
    String(text || '').split(/\r?\n/).forEach((line) => {
      const m = line.match(/^\s*(.+?)\s*(?:=>|→|=)\s*(.*?)\s*$/);
      if (!m) return;
      let from = m[1];
      let scope = '';
      const dot = from.match(/^([^.]+)\.(.+)$/);
      if (dot) {
        scope = dot[1].trim().toLowerCase();
        from = dot[2];
      }
      const target = scope ? (byName[scope] = byName[scope] || new Map()) : all;
      target.set(from.trim().toLowerCase(), m[2]);
    });
    return { all, byName };
  }

  /** Datum aus dem Namen -> { y, m, d } (d = 0 bei Jahr+Monat) oder null. */
  function parseDate(text) {
    // Jede Schreibweise einzeln pruefen (ab 1.2.2): "2026_05" ist Jahr und
    // Monat - frueher las die erste Form daraus Monat 0 und gab auf
    const forms = [
      [/^(\d{4})[-_.](\d{1,2})[-_.](\d{1,2})$/, (m) => [+m[1], +m[2], +m[3]]],
      [/^(\d{4})(\d{2})(\d{2})$/, (m) => [+m[1], +m[2], +m[3]]],
      [/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/, (m) => [+m[3], +m[2], +m[1]]],
      [/^(\d{4})[-_.](\d{1,2})$/, (m) => [+m[1], +m[2], 0]],
    ];
    for (const [re, parts] of forms) {
      const m = text.match(re);
      if (!m) continue;
      const date = valid(...parts(m));
      if (date) return date;
    }
    return null;
  }

  function valid(y, m, d) {
    if (m < 1 || m > 12 || d < 0 || d > 31) return null;
    return { y, m, d };
  }

  function two(n) {
    return (n < 10 ? '0' : '') + n;
  }

  function formatDate(date, how) {
    switch (how) {
      case 'kurz': return date.d ? two(date.d) + '.' + two(date.m) + '.' + date.y : two(date.m) + '.' + date.y;
      case 'jahr': return String(date.y);
      case 'monat': return MONTHS[date.m - 1];
      case 'tag': return date.d ? String(date.d) : '';
      case 'iso': return date.y + '-' + two(date.m) + (date.d ? '-' + two(date.d) : '');
      default: return (date.d ? date.d + '. ' : '') + MONTHS[date.m - 1] + ' ' + date.y;
    }
  }

  // Zerlegte Werte-Listen und fertige Anzeigenamen merken (ab 1.2.2): jede
  // Liste wird nur einmal gelesen, jeder Name nur einmal umgerechnet
  const maps = new Map();
  const results = new WeakMap();
  function mapFor(text) {
    const key = String(text || '');
    if (!maps.has(key)) maps.set(key, parseMap(key));
    return maps.get(key);
  }

  function render(rule, slots, values, original) {
    const map = mapFor(rule.map);
    const types = {};
    slots.forEach((s) => { types[s.name.toLowerCase()] = s.type; });
    const out = String(rule.display || '').replace(TOKEN, (all, rawName, rawOpt) => {
      const name = rawName.trim();
      const lower = name.toLowerCase();
      const opt = (rawOpt || '').trim().toLowerCase();
      let value;
      if (Object.prototype.hasOwnProperty.call(values, lower)) value = values[lower];
      else if (lower === 'name') value = original;
      else return '';

      const scoped = map.byName[lower];
      const key = value.toLowerCase();
      if (scoped && scoped.has(key)) return applyCase(scoped.get(key), opt);
      if (map.all.has(key)) return applyCase(map.all.get(key), opt);

      if (types[lower] === 'datum' || ['kurz', 'lang', 'jahr', 'monat', 'tag', 'iso'].includes(opt)) {
        const date = parseDate(value);
        if (date) return formatDate(date, opt);
      }
      if (rule.spaces !== false) value = value.replace(/_+/g, ' ');
      return applyCase(value, opt);
    });
    return out.replace(/\s+/g, ' ').trim();
  }

  function applyCase(value, opt) {
    if (opt === 'groß' || opt === 'gross') return value.toUpperCase();
    if (opt === 'klein') return value.toLowerCase();
    if (opt === 'anfang') return value.charAt(0).toUpperCase() + value.slice(1);
    return value;
  }

  /**
   * Anzeigename nach den Regeln. kind: 'file' oder 'dir'.
   * Liefert null, wenn keine Regel passt.
   */
  function apply(rules, name, kind) {
    if (!Array.isArray(rules) || rules.length === 0 || !name) return null;
    let memo = results.get(rules);
    if (!memo) {
      memo = new Map();
      results.set(rules, memo);
    }
    const key = kind + '|' + name;
    if (memo.has(key)) return memo.get(key);
    const shown = applyUncached(rules, name, kind);
    if (memo.size > 20000) memo.clear();
    memo.set(key, shown);
    return shown;
  }

  function applyUncached(rules, name, kind) {
    for (const rule of rules) {
      if (!rule || !rule.pattern) continue;
      const applies = rule.applies || 'both';
      if ((kind === 'dir' && applies === 'files') || (kind === 'file' && applies === 'folders')) continue;
      const c = compile(rule.pattern);
      if (c.error) continue;
      const m = String(name).match(c.re);
      if (!m) continue;
      const values = {};
      c.slots.forEach((s, i) => { values[s.name.toLowerCase()] = m[i + 1]; });
      const shown = render(rule, c.slots, values, String(name));
      return shown !== '' ? shown : null;
    }
    return null;
  }

  // ------------------------------------------------------------------
  // Eingabe im Freigabe-Formular
  // ------------------------------------------------------------------

  const HELP = 'Platzhalter im Muster: {x} beliebiger Text · {x:3} genau 3 Zeichen · {x:Zahl} · '
    + '{x:Buchstaben} · {x:Wort} · {x:Datum}. In der Anzeige dieselben Namen, dazu {Datum:kurz}, '
    + '{Datum:Jahr}, {Datum:Monat}, {x:groß}, {x:klein}, {x:Anfang} und {Name} für den ganzen Namen.';

  /**
   * Baut die Eingabe fuer eine Freigabe.
   * @param {Array} rules   gespeicherte Regeln
   * @param {Array} samples Beispielnamen [{name, kind}] fuer die Vorschau
   * @returns {{el: HTMLElement, value: function(): Array, error: function(): string}}
   */
  function editor(rules, samples) {
    const root = document.createElement('div');
    root.className = 'name-rules';
    const list = document.createElement('div');
    list.className = 'name-rules-list';
    const items = [];

    const hint = document.createElement('p');
    hint.className = 'panel-hint';
    hint.textContent = 'Datei- und Ordnernamen anders anzeigen – die Dateien selbst bleiben, wie sie sind. '
      + 'Die erste passende Regel gilt; passt keine, bleibt der Name.';
    root.appendChild(hint);
    root.appendChild(list);

    const sampleList = Array.isArray(samples) ? samples.slice(0, 40) : [];

    function input(value, placeholder, cls) {
      const el = document.createElement('input');
      el.type = 'text';
      el.className = 'panel-input ' + (cls || '');
      el.value = value || '';
      el.placeholder = placeholder || '';
      el.spellcheck = false;
      return el;
    }

    function labelled(text, control) {
      const wrap = document.createElement('label');
      wrap.className = 'panel-field';
      const span = document.createElement('span');
      span.className = 'panel-field-label';
      span.textContent = text;
      wrap.append(span, control);
      return wrap;
    }

    function addRule(rule) {
      const r = rule || {};
      const box = document.createElement('fieldset');
      box.className = 'name-rule';
      const pattern = input(r.pattern, 'z. B. {Kürzel:3}_{Datum}_{Titel}', 'name-rule-pattern');
      const display = input(r.display, 'z. B. {Titel} – {Kürzel}, {Datum}', 'name-rule-display');
      const map = document.createElement('textarea');
      map.className = 'panel-input name-rule-map';
      map.rows = 3;
      map.value = r.map || '';
      map.placeholder = 'GDB = Gottesdienst\nJUG = Jugendstunde';
      map.spellcheck = false;
      const applies = document.createElement('select');
      applies.className = 'panel-input';
      [['both', 'Dateien und Ordner'], ['files', 'nur Dateien'], ['folders', 'nur Ordner']].forEach(([v, t]) => {
        const o = document.createElement('option');
        o.value = v;
        o.textContent = t;
        applies.appendChild(o);
      });
      applies.value = ['files', 'folders'].includes(r.applies) ? r.applies : 'both';
      const spacesWrap = document.createElement('label');
      spacesWrap.className = 'panel-choice';
      const spaces = document.createElement('input');
      spaces.type = 'checkbox';
      spaces.checked = r.spaces !== false;
      spacesWrap.append(spaces, document.createTextNode(' Unterstriche in den Werten als Leerzeichen zeigen'));

      const status = document.createElement('p');
      status.className = 'panel-hint name-rule-status';
      const preview = document.createElement('ul');
      preview.className = 'name-rule-preview';

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'panel-button panel-button--danger';
      remove.textContent = 'Regel entfernen';
      remove.addEventListener('click', () => {
        const i = items.indexOf(item);
        if (i >= 0) items.splice(i, 1);
        box.remove();
      });

      box.append(
        labelled('Muster', pattern),
        labelled('Anzeige', display),
        labelled('Werte ersetzen (optional, je Zeile „alt = neu“)', map),
        labelled('Gilt für', applies),
        spacesWrap, status, preview, remove
      );
      list.appendChild(box);

      const item = {
        value: () => ({
          pattern: pattern.value.trim(),
          display: display.value.trim(),
          map: map.value,
          applies: applies.value,
          spaces: spaces.checked,
        }),
        error: () => {
          const v = item.value();
          if (v.pattern === '' && v.display === '') return '';
          const c = compile(v.pattern);
          if (c.error) return c.error;
          if (v.display === '') return 'Bitte eintragen, was angezeigt werden soll.';
          return '';
        },
      };
      items.push(item);

      const refresh = () => {
        const v = item.value();
        const err = item.error();
        status.textContent = err;
        status.classList.toggle('is-error', err !== '');
        preview.textContent = '';
        if (err || v.pattern === '') return;
        let shown = 0;
        sampleList.forEach((s) => {
          if (shown >= 4) return;
          const out = apply([v], s.name, s.kind);
          if (out === null) return;
          shown++;
          const li = document.createElement('li');
          li.textContent = s.name + ' → ' + out;
          preview.appendChild(li);
        });
        if (shown === 0) {
          const li = document.createElement('li');
          li.textContent = sampleList.length
            ? 'Im geöffneten Ordner passt noch kein Name zu diesem Muster.'
            : 'Vorschau: im geöffneten Ordner sind keine Namen zum Ausprobieren.';
          preview.appendChild(li);
        }
      };
      [pattern, display, map].forEach((el) => el.addEventListener('input', refresh));
      [applies, spaces].forEach((el) => el.addEventListener('change', refresh));
      refresh();
    }

    (Array.isArray(rules) ? rules : []).forEach(addRule);

    const add = document.createElement('button');
    add.type = 'button';
    add.className = 'panel-button';
    add.textContent = '+ Regel';
    add.addEventListener('click', () => addRule({}));
    const help = document.createElement('p');
    help.className = 'panel-hint';
    help.textContent = HELP;
    root.append(add, help);

    return {
      el: root,
      value: () => items.map((i) => i.value()).filter((v) => v.pattern !== '' && v.display !== ''),
      error: () => {
        for (const i of items) {
          const e = i.error();
          if (e) return e;
        }
        return '';
      },
    };
  }

  return { apply, compile, editor };
})();
