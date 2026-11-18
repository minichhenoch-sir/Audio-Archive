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
 *            {x:mehrere} (ab 1.2.3): x nur zeigen, wenn es im selben Ordner
 *            einen Namen gibt, der sich nur in x unterscheidet (z. B.
 *            Vormittag/Abend am selben Tag) - sonst entfaellt x.
 *            Mehrere Zusaetze mit Komma: {x:mehrere,groß}.
 *            [ … ] (ab 1.2.3): Text in eckigen Klammern entfaellt ganz,
 *            wenn alle Platzhalter darin leer sind:  {Tag}[ – {Zeit:mehrere}]
 *
 *   Werte    Ersetzungen, eine je Zeile:  GDB = Gottesdienst
 *            Gilt fuer alle Platzhalter; nur fuer einen bestimmten:
 *              Kürzel.GDB = Gottesdienst
 *
 *   Gilt für Dateien, Ordner oder beides; Unterstriche in den Werten
 *            werden auf Wunsch zu Leerzeichen.
 *
 * Die erste passende Regel gewinnt. Passt keine, bleibt der Name, wie er ist.
 *
 * Ab 1.4.0 (Vikunja #72):
 *   lang     Sprache der Monats- und Tagesnamen ('' = Sprache des Hoerers,
 *            sonst z. B. 'de', 'en'); alte Regeln ohne lang: Deutsch
 *   where    { path, sub }  Regel gilt nur in diesem Ordner (mit/ohne
 *            Unterordner); path '' = ganze Freigabe
 *   level    nur Namen auf dieser Ebene unterhalb von where (1 = direkt darin)
 *   except   Ordner/Dateien (Pfade), fuer die die Regel nicht gilt
 *   builder  Zustand des Baukastens (nur fuer das Formular)
 *   { kind: 'name', target: 'dir'|'file', path, name }  eigener Name fuer
 *            genau einen Ordner bzw. eine Datei - geht allen Regeln vor
 *   Datum frei: {Datum:"T. MMMM JJJJ"} - Bausteine T/TT Tag, M/MM Monat,
 *            MMM/MMMM Monatsname, JJ/JJJJ Jahr, WW/WWWW Wochentag
 *            (auch D/DD, Y/YY/YYYY); Text in '…' bleibt, wie er ist.
 *   Pfade sind relativ zum geteilten Ordner.
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

  /** Sprache des Hoerers: Nextcloud-Sprache der Seite, sonst Browser. */
  function hearerLang() {
    const l = (document.documentElement && document.documentElement.lang) || navigator.language || 'de';
    return String(l).replace('_', '-');
  }

  const nameCache = new Map();
  /** Monats- bzw. Tagesname in einer Sprache (Intl, Rueckfall Deutsch). */
  function localName(kind, n, lang, width) {
    const loc = lang || hearerLang();
    const key = kind + '|' + n + '|' + loc + '|' + width;
    if (nameCache.has(key)) return nameCache.get(key);
    let out = '';
    try {
      const d = kind === 'month' ? new Date(Date.UTC(2001, n - 1, 15)) : new Date(Date.UTC(2001, 0, 7 + n));
      out = new Intl.DateTimeFormat(loc, { timeZone: 'UTC', [kind === 'month' ? 'month' : 'weekday']: width }).format(d);
    } catch (e) {
      out = '';
    }
    if (!out && kind === 'month') out = width === 'long' ? MONTHS[n - 1] : MONTHS[n - 1].slice(0, 3);
    if (!out) out = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][n];
    nameCache.set(key, out);
    return out;
  }

  const FMT_TOKEN = /'[^']*'|WWWW|WW|MMMM|MMM|MM|M|TT|T|DD|D|JJJJ|JJ|YYYY|YY/g;
  /** Eigenes Datumsformat ("T. MMMM JJJJ") - ab 1.4.0. */
  function customDate(date, fmt, lang) {
    let f = String(fmt);
    // Nur Jahr und Monat bekannt: Tag und Wochentag samt Trennzeichen weg
    if (!date.d) {
      f = f.replace(/(WWWW|WW|TT|T|DD|D)[\s.,\/-]*/g, '').replace(/^[\s.,\/-]+|[\s.,\/-]+$/g, '');
    }
    const wd = date.d ? new Date(Date.UTC(date.y, date.m - 1, date.d)).getUTCDay() : 0;
    return f.replace(FMT_TOKEN, (t) => {
      if (t.startsWith("'")) return t.slice(1, -1);
      switch (t) {
        case 'WWWW': return localName('day', wd, lang, 'long');
        case 'WW': return localName('day', wd, lang, 'short');
        case 'MMMM': return localName('month', date.m, lang, 'long');
        case 'MMM': return localName('month', date.m, lang, 'short');
        case 'MM': return two(date.m);
        case 'M': return String(date.m);
        case 'TT': case 'DD': return two(date.d);
        case 'T': case 'D': return String(date.d);
        case 'JJJJ': case 'YYYY': return String(date.y);
        case 'JJ': case 'YY': return String(date.y).slice(-2);
        default: return t;
      }
    });
  }

  function formatDate(date, how, lang) {
    switch (how) {
      case 'kurz': return date.d ? two(date.d) + '.' + two(date.m) + '.' + date.y : two(date.m) + '.' + date.y;
      case 'jahr': return String(date.y);
      case 'monat': return localName('month', date.m, lang, 'long');
      case 'tag': return date.d ? String(date.d) : '';
      case 'iso': return date.y + '-' + two(date.m) + (date.d ? '-' + two(date.d) : '');
      case '': case 'lang': return (date.d ? date.d + '. ' : '') + localName('month', date.m, lang, 'long') + ' ' + date.y;
      default: return customDate(date, how, lang);
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

  const CASES = ['groß', 'gross', 'klein', 'anfang'];
  const DATE_WORDS = ['kurz', 'lang', 'jahr', 'monat', 'tag', 'iso'];
  /**
   * Zusaetze eines Platzhalters: {x:mehrere,groß} -> { multi, kase, date }.
   * Ab 1.4.0 auch ein Datumsformat, in "…" (darf Kommas enthalten) oder
   * ohne Anfuehrungszeichen aus den Datums-Bausteinen ({Datum:TT.MM.JJJJ}).
   */
  function splitOpt(raw) {
    let text = String(raw || '');
    let date = '';
    const q = text.match(/"([^"]*)"/);
    if (q) {
      date = q[1];
      text = text.replace(q[0], '');
    }
    let multi = false;
    let kase = '';
    text.split(',').map((p) => p.trim()).filter(Boolean).forEach((p) => {
      const low = p.toLowerCase();
      if (low === 'mehrere') multi = true;
      else if (CASES.includes(low)) kase = low;
      else if (DATE_WORDS.includes(low)) date = date || low;
      else if (!date && /^(?:'[^']*'|[TMJWDY.\s\/:-])+$/.test(p)) date = p;
    });
    return { multi, kase, date, fmt: kase || date };
  }

  /**
   * Anzeige einer Regel. hidden: Platzhalter (klein), die wegen
   * {x:mehrere} entfallen.
   */
  function render(rule, slots, values, original, hidden) {
    const map = mapFor(rule.map);
    // Alte Regeln (vor 1.4.0) ohne Sprache: Deutsch wie bisher
    const lang = rule.lang === undefined ? 'de' : String(rule.lang || '');
    const types = {};
    slots.forEach((s) => { types[s.name.toLowerCase()] = s.type; });
    const one = (rawName, rawOpt) => {
      const lower = rawName.trim().toLowerCase();
      if (hidden && hidden.has(lower)) return '';
      const o = splitOpt(rawOpt);
      const opt = o.kase;
      let value;
      if (Object.prototype.hasOwnProperty.call(values, lower)) value = values[lower];
      else if (lower === 'name') value = original;
      else return '';

      const scoped = map.byName[lower];
      const key = value.toLowerCase();
      if (scoped && scoped.has(key)) return applyCase(scoped.get(key), opt);
      if (map.all.has(key)) return applyCase(map.all.get(key), opt);

      if (types[lower] === 'datum' || o.date) {
        const date = parseDate(value);
        if (date) return applyCase(formatDate(date, o.date, lang), opt);
      }
      if (rule.spaces !== false) value = value.replace(/_+/g, ' ');
      return applyCase(value, opt);
    };
    const part = (text) => {
      let had = false;
      let filled = false;
      const out = text.replace(TOKEN, (all, n, o) => {
        had = true;
        const v = one(n, o);
        if (v !== '') filled = true;
        return v;
      });
      return { out, had, filled };
    };
    // [ … ] entfaellt, wenn alle Platzhalter darin leer sind
    const outer = String(rule.display || '').replace(/\[([^\[\]]*)\]/g, (all, inner) => {
      const r = part(inner);
      return r.had && !r.filled ? '' : r.out;
    });
    return part(outer).out.replace(/\s+/g, ' ').trim();
  }

  /** Platzhalter mit {x:mehrere} in der Anzeige einer Regel (klein). */
  const multiCache = new Map();
  function multiOf(display) {
    const key = String(display || '');
    if (!multiCache.has(key)) {
      const set = new Set();
      key.replace(TOKEN, (all, n, o) => {
        if (splitOpt(o).multi) set.add(n.trim().toLowerCase());
        return all;
      });
      multiCache.set(key, set);
    }
    return multiCache.get(key);
  }

  function applyCase(value, opt) {
    if (opt === 'groß' || opt === 'gross') return value.toUpperCase();
    if (opt === 'klein') return value.toLowerCase();
    if (opt === 'anfang') return value.charAt(0).toUpperCase() + value.slice(1);
    return value;
  }

  // ------------------------------------------------------------------
  // Wo eine Regel gilt (ab 1.4.0): Bereich, Ebene, Ausnahmen, eigene Namen
  // ------------------------------------------------------------------

  function cleanPath(p) {
    return String(p || '').replace(/\\/g, '/').replace(/\/+/g, '/').replace(/^\/|\/$/g, '');
  }
  function stripExt(p) {
    return String(p).replace(/\.[A-Za-z0-9]{1,5}$/, '');
  }
  function depthOf(p) {
    return p === '' ? 0 : p.split('/').length;
  }
  /** Gleicher Pfad - bei Dateien auch ohne Endung. */
  function samePath(a, b, kind) {
    if (a === b) return true;
    return kind === 'file' && stripExt(a) === stripExt(b);
  }
  function isOverride(rule) {
    return !!rule && rule.kind === 'name';
  }

  /** Gilt die Regel fuer den Namen an diesem Pfad? */
  function ruleCovers(rule, kind, path) {
    const applies = rule.applies || 'both';
    if ((kind === 'dir' && applies === 'files') || (kind === 'file' && applies === 'folders')) return false;
    const p = cleanPath(path);
    const where = rule.where && typeof rule.where === 'object' ? rule.where : null;
    const area = where ? cleanPath(where.path) : '';
    if (area !== '') {
      const sub = where.sub !== false;
      const parent = p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : '';
      if (sub ? !p.startsWith(area + '/') : parent !== area) return false;
    }
    const level = parseInt(rule.level, 10) || 0;
    if (level > 0 && depthOf(p) - depthOf(area) !== level) return false;
    if (Array.isArray(rule.except)) {
      for (const e of rule.except) {
        const x = cleanPath(e);
        if (x !== '' && (samePath(p, x, kind) || p.startsWith(x + '/'))) return false;
      }
    }
    return true;
  }

  /** Eigener Name fuer genau diesen Ordner bzw. diese Datei, sonst null. */
  function overrideFor(rules, kind, path) {
    const p = cleanPath(path);
    for (const rule of rules) {
      if (!isOverride(rule) || !rule.name) continue;
      if ((rule.target === 'file') !== (kind === 'file')) continue;
      if (samePath(p, cleanPath(rule.path), kind)) return String(rule.name);
    }
    return null;
  }

  /** Pfad eines Namens: angegeben oder - ohne Angabe - der Name selbst. */
  function pathOf(name, path) {
    return path === undefined || path === null ? String(name) : cleanPath(path);
  }
  function join(parent, name) {
    const p = cleanPath(parent);
    return p === '' ? String(name) : p + '/' + name;
  }

  /**
   * Anzeigename nach den Regeln. kind: 'file' oder 'dir'; path: Pfad des
   * Namens im geteilten Ordner (ab 1.4.0, fuer Bereiche und eigene Namen).
   * Liefert null, wenn keine Regel passt.
   */
  // Namen, die mit Blick auf die Nachbarn im selben Ordner berechnet
  // wurden ({x:mehrere}, ab 1.2.3): je Regelliste kind|pfad -> Anzeige
  const context = new WeakMap();

  function apply(rules, name, kind, path) {
    if (!Array.isArray(rules) || rules.length === 0 || !name) return null;
    const where = pathOf(name, path);
    const ctx = context.get(rules);
    const key = kind + '|' + where;
    if (ctx && ctx.has(key)) return ctx.get(key);
    let memo = results.get(rules);
    if (!memo) {
      memo = new Map();
      results.set(rules, memo);
    }
    if (memo.has(key)) return memo.get(key);
    let shown = overrideFor(rules, kind, where);
    if (shown === null) {
      const m = match(rules, name, kind, where);
      shown = m ? (render(m.rule, m.slots, m.values, String(name), null) || null) : null;
    }
    if (memo.size > 20000) memo.clear();
    memo.set(key, shown);
    return shown;
  }

  /** Erste passende Regel: { rule, slots, values } oder null. */
  function match(rules, name, kind, path) {
    const where = pathOf(name, path);
    for (const rule of rules) {
      if (!rule || isOverride(rule) || !rule.pattern) continue;
      if (!ruleCovers(rule, kind, where)) continue;
      const c = compile(rule.pattern);
      if (c.error) continue;
      const m = String(name).match(c.re);
      if (!m) continue;
      const values = {};
      c.slots.forEach((s, i) => { values[s.name.toLowerCase()] = m[i + 1]; });
      return { rule, slots: c.slots, values };
    }
    return null;
  }

  /**
   * Alle Namen eines Ordners auf einmal (ab 1.2.3): Erst hier ist bekannt,
   * ob es zu einem Namen Nachbarn gibt, die sich nur in einem
   * {x:mehrere}-Teil unterscheiden. Die Ergebnisse merkt sich apply() fuer
   * Pfad, Seitenleiste und Player. parent: Pfad des Ordners (ab 1.4.0);
   * fileNames: bei Dateien die echten Namen samt Endung, falls bekannt.
   * @returns {Map<string, string|null>}
   */
  function applyAll(rules, names, kind, parent, fileNames) {
    const out = new Map();
    if (!Array.isArray(rules) || rules.length === 0) return out;
    const hasParent = parent !== undefined && parent !== null;
    const found = [];
    names.forEach((name, i) => {
      if (!name) return;
      const real = Array.isArray(fileNames) && fileNames[i] ? fileNames[i] : name;
      const path = hasParent ? join(parent, real) : undefined;
      const own = overrideFor(rules, kind, pathOf(name, path));
      if (own !== null) {
        out.set(name, own);
        return;
      }
      const m = match(rules, name, kind, path);
      if (m) found.push({ name, m, path });
      else out.set(name, null);
    });
    // Je Regel und Platzhalter x: Gruppen gleicher uebriger Werte -> verschiedene x
    const groups = new Map();
    const groupKey = (f, x) => rules.indexOf(f.m.rule) + '\u0001' + x + '\u0001'
      + f.m.slots.map((s) => s.name.toLowerCase()).filter((n) => n !== x)
        .map((n) => String(f.m.values[n]).toLowerCase()).join('\u0001');
    found.forEach((f) => {
      multiOf(f.m.rule.display).forEach((x) => {
        if (!(x in f.m.values)) return;
        const k = groupKey(f, x);
        if (!groups.has(k)) groups.set(k, new Set());
        groups.get(k).add(String(f.m.values[x]).toLowerCase());
      });
    });
    let ctx = context.get(rules);
    if (!ctx) {
      ctx = new Map();
      context.set(rules, ctx);
    }
    if (ctx.size > 20000) ctx.clear();
    out.forEach((shown, name) => {
      if (shown !== null) ctx.set(kind + '|' + pathOf(name, hasParent ? join(parent, name) : undefined), shown);
    });
    found.forEach((f) => {
      const hidden = new Set();
      multiOf(f.m.rule.display).forEach((x) => {
        if (!(x in f.m.values)) return;
        const g = groups.get(groupKey(f, x));
        if (!g || g.size < 2) hidden.add(x);
      });
      const shown = render(f.m.rule, f.m.slots, f.m.values, String(f.name), hidden) || null;
      out.set(f.name, shown);
      ctx.set(kind + '|' + pathOf(f.name, f.path), shown);
    });
    return out;
  }

  /** Vom Server berechneten Namen uebernehmen (Suchtreffer, ab 1.2.3). */
  function remember(rules, name, kind, shown, path) {
    if (!Array.isArray(rules) || rules.length === 0 || !name) return;
    let ctx = context.get(rules);
    if (!ctx) {
      ctx = new Map();
      context.set(rules, ctx);
    }
    ctx.set(kind + '|' + pathOf(name, path), shown || null);
  }

  // ------------------------------------------------------------------
  // Baukasten (ab 1.4.0, Vikunja #72): Ein Beispielname wird in Teile
  // zerlegt, jeder Teil bekommt per Auswahl eine Bedeutung, die Anzeige
  // wird per Antippen zusammengesetzt. Daraus entstehen Muster, Anzeige und
  // Werte in der bisherigen Schreibweise - die bleibt fuer Fortgeschrittene
  // aufklappbar. Regeln ohne Baukasten (vor 1.4.0) erscheinen dort.
  // ------------------------------------------------------------------

  const SEP_CHARS = /[\s_\-.]/;
  const DATE_PRESETS = ['T. MMMM JJJJ', 'TT.MM.JJJJ', 'T. MMMM', 'MMMM JJJJ', 'WW, TT.MM.JJJJ',
    'WWWW, T. MMMM JJJJ', 'JJJJ-MM-TT', 'TT/MM/JJJJ', 'MM/TT/JJJJ', 'T. MMM JJ', 'JJJJ'];
  const LANGS = [['', 'Sprache des Hörers'], ['de', 'Deutsch'], ['en', 'English'], ['fr', 'Français'],
    ['es', 'Español'], ['it', 'Italiano'], ['nl', 'Nederlands'], ['pt', 'Português'], ['pl', 'Polski'],
    ['cs', 'Čeština'], ['ro', 'Română'], ['hu', 'Magyar'], ['sv', 'Svenska'], ['da', 'Dansk'],
    ['nb', 'Norsk'], ['fi', 'Suomi'], ['el', 'Ελληνικά'], ['ru', 'Русский'], ['uk', 'Українська'],
    ['tr', 'Türkçe']];
  const KASES = [['', 'wie im Namen'], ['groß', 'GROSS'], ['klein', 'klein'], ['anfang', 'Erster Buchstabe groß']];
  const SAMPLE_DATE = { y: 2026, m: 5, d: 17 };

  let idCounter = 0;
  const newId = () => 'p' + (++idCounter) + Math.random().toString(36).slice(2, 6);

  /** Beispielname -> [{id, text, sep, role, name, join}] und Rest am Ende. */
  function splitSample(name) {
    const s = String(name || '');
    const spans = [];
    const re = new RegExp('(' + DATE_RE + ')', 'g');
    let m;
    while ((m = re.exec(s))) {
      const start = m.index;
      const end = start + m[1].length;
      const before = start === 0 ? '' : s[start - 1];
      const after = s[end] || '';
      if (!/\d/.test(before) && !/\d/.test(after) && parseDate(m[1])) spans.push([start, end]);
      re.lastIndex = Math.max(end, start + 1);
    }
    const parts = [];
    let sep = '';
    let i = 0;
    while (i < s.length) {
      const span = spans.find((x) => x[0] === i);
      if (span) {
        parts.push({ text: s.slice(span[0], span[1]), sep, role: 'date' });
        sep = '';
        i = span[1];
        continue;
      }
      if (SEP_CHARS.test(s[i])) {
        sep += s[i];
        i++;
        continue;
      }
      let j = i;
      while (j < s.length && !SEP_CHARS.test(s[j]) && !spans.some((x) => x[0] === j)) j++;
      parts.push({ text: s.slice(i, j), sep, role: 'text' });
      sep = '';
      i = j;
    }
    let n = 0;
    let dates = 0;
    parts.forEach((p) => {
      p.id = newId();
      p.join = false;
      // Dateiendungen im Namen (z. B. "_mp3") sind fast immer gleich
      if (p.role === 'text' && /^(mp3|m4a|mp4|aac|wav|ogg|oga|opus|flac|aif|aiff|wma|webm)$/i.test(p.text)) p.role = 'fixed';
      if (p.role === 'date') p.name = ++dates === 1 ? 'Datum' : 'Datum ' + dates;
      else if (p.role === 'text') p.name = 'Teil ' + (++n);
      else p.name = p.text;
    });
    return { parts, tail: sep };
  }

  /** Teile zu Gruppen (verbundene Teile = eine Gruppe). */
  function groupsOf(b) {
    const out = [];
    b.parts.forEach((p, i) => {
      const prev = b.parts[i - 1];
      if (i > 0 && prev.join) {
        const g = out[out.length - 1];
        g.text += p.sep + p.text;
        g.size++;
      } else {
        out.push({ id: p.id, text: p.text, sep: p.sep, role: p.role, name: p.name, size: 1, first: i });
      }
    });
    return out;
  }

  const cleanName = (n) => String(n || '').replace(/[{}:,\[\]".=]/g, '').trim();
  const cleanText = (t) => String(t || '').replace(/[{}\[\]"]/g, '');

  /** Baukasten -> { pattern, display, map } in der bisherigen Schreibweise. */
  function generate(b) {
    const groups = groupsOf(b);
    let pattern = '';
    groups.forEach((g, gi) => {
      pattern += g.sep;
      const last = gi === groups.length - 1;
      const next = groups[gi + 1];
      const tight = g.sep === '' && gi > 0 ? true : !!(next && next.sep === '');
      let type = '';
      if (!last && g.size === 1) {
        if (tight && /^\d+$/.test(g.text)) type = ':Zahl';
        else if (tight && new RegExp('^[' + LETTERS + ']+$').test(g.text)) type = ':Buchstaben';
        else type = ':Wort';
      }
      if (g.role === 'fixed') pattern += g.text.replace(/[{}]/g, '');
      else if (g.role === 'date') pattern += '{' + cleanName(g.name) + ':Datum}';
      else if (g.role === 'skip') pattern += '{_' + (gi + 1) + type + '}';
      else pattern += '{' + cleanName(g.name) + type + '}';
    });
    pattern += b.tail || '';

    const byId = new Map(groups.map((g) => [g.id, g]));
    const pieces = (b.show || []).filter((x) => x.t === 'text' || (byId.has(x.id)
      && ['text', 'date'].includes(byId.get(x.id).role)));
    // Zwischen zwei Teilen ohne eigenen Text steht ein Leerzeichen
    let display = '';
    pieces.forEach((x, i) => {
      if (x.t === 'text') {
        // Text direkt vor einem "nur wenn mehrere"-Teil gehoert mit in [ ]
        const nx = pieces[i + 1];
        if (nx && nx.t === 'part' && nx.multi) return;
        display += cleanText(x.v);
        return;
      }
      const pv = pieces[i - 1];
      const gap = pv && pv.t === 'part' ? ' ' : '';
      const g = byId.get(x.id);
      const opts = [];
      if (x.multi) opts.push('mehrere');
      if (x.kase) opts.push(x.kase);
      if (g.role === 'date') opts.push('"' + String(x.fmt || 'T. MMMM JJJJ').replace(/"/g, '') + '"');
      const token = '{' + cleanName(g.name) + (opts.length ? ':' + opts.join(',') : '') + '}';
      if (x.multi) {
        display += '[' + (pv && pv.t === 'text' ? cleanText(pv.v) : gap) + token + ']';
      } else {
        display += gap + token;
      }
    });

    const lines = [];
    Object.keys(b.values || {}).forEach((id) => {
      const g = byId.get(id);
      if (!g || g.role !== 'text') return;
      Object.keys(b.values[id]).forEach((from) => {
        const to = String(b.values[id][from] || '').trim();
        if (from.trim() !== '' && to !== '') lines.push(cleanName(g.name) + '.' + from.trim() + ' = ' + to);
      });
    });
    return { pattern, display: display.trim(), map: lines.join('\n') };
  }

  /** Vorgabe-Anzeige: alle Text- und Datumsteile (Leerzeichen dazwischen). */
  function defaultShow(b) {
    return groupsOf(b).filter((g) => g.role === 'text' || g.role === 'date')
      .map((g) => (g.role === 'date' ? { t: 'part', id: g.id, fmt: 'T. MMMM JJJJ' } : { t: 'part', id: g.id }));
  }

  /** Beispiel zu einem Datumsformat in einer Sprache. */
  function dateExample(fmt, lang) {
    return formatDate(SAMPLE_DATE, fmt, lang);
  }

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  function input(value, placeholder, cls) {
    const e = el('input', 'panel-input ' + (cls || ''));
    e.type = 'text';
    e.value = value || '';
    e.placeholder = placeholder || '';
    e.spellcheck = false;
    return e;
  }

  function select(options, value, cls) {
    const e = el('select', 'panel-input ' + (cls || ''));
    options.forEach(([v, t]) => {
      const o = el('option', '', t);
      o.value = v;
      e.appendChild(o);
    });
    e.value = value;
    return e;
  }

  function labelled(text, control, hint) {
    const wrap = el('label', 'panel-field');
    wrap.append(el('span', 'panel-field-label', text), control);
    if (hint) wrap.appendChild(el('span', 'panel-hint nb-field-hint', hint));
    return wrap;
  }

  function smallButton(text, title, onClick, cls) {
    const b = el('button', 'nb-mini ' + (cls || ''), text);
    b.type = 'button';
    if (title) {
      b.title = title;
      b.setAttribute('aria-label', title);
    }
    b.addEventListener('click', (ev) => {
      ev.preventDefault();
      onClick(ev);
    });
    return b;
  }

  /** Tabelle aller Bausteine (Wunsch: in Formular und Anleitung sichtbar). */
  function legend(langOf) {
    const box = el('details', 'nb-legend');
    box.appendChild(el('summary', '', 'Übersicht: alle Bausteine'));
    const body = el('div', 'nb-legend-body');
    const table = (head, rows) => {
      const t = el('table', 'nb-table');
      const tr = el('tr');
      head.forEach((h) => tr.appendChild(el('th', '', h)));
      t.appendChild(tr);
      rows.forEach((r) => {
        const row = el('tr');
        r.forEach((c) => row.appendChild(el('td', '', c)));
        t.appendChild(row);
      });
      return t;
    };
    const fill = () => {
      const lang = langOf();
      body.textContent = '';
      body.appendChild(el('p', 'nb-legend-head', 'Teile eines Namens'));
      body.appendChild(table(['Art', 'Bedeutung'], [
        ['Text', 'beliebiger Text bis zum nächsten Trennzeichen (_ - . Leerzeichen); der letzte Teil darf auch Trennzeichen enthalten'],
        ['Datum', 'ein Datum im Namen: 2026-05-17, 2026_05_17, 20260517, 17.05.2026 oder nur Jahr und Monat (2026-05)'],
        ['immer gleich', 'steht in jedem passenden Namen genau so (z. B. „mp3“)'],
        ['weglassen', 'darf beliebig sein und wird nicht angezeigt'],
      ]));
      body.appendChild(el('p', 'nb-legend-head', 'Datum – Bausteine für ein eigenes Format (Beispiel: Sonntag, 17. Mai 2026)'));
      body.appendChild(table(['Baustein', 'Bedeutung', 'Beispiel'], [
        ['T', 'Tag', dateExample('T', lang)], ['TT', 'Tag, zweistellig', dateExample('TT', lang)],
        ['M', 'Monat als Zahl', dateExample('M', lang)], ['MM', 'Monat, zweistellig', dateExample('MM', lang)],
        ['MMM', 'Monatsname, kurz', dateExample('MMM', lang)], ['MMMM', 'Monatsname', dateExample('MMMM', lang)],
        ['JJ', 'Jahr, zweistellig', dateExample('JJ', lang)], ['JJJJ', 'Jahr', dateExample('JJJJ', lang)],
        ['WW', 'Wochentag, kurz', dateExample('WW', lang)], ['WWWW', 'Wochentag', dateExample('WWWW', lang)],
        ["'Text'", 'Text in Hochkommas bleibt, wie er ist', dateExample("'am' T.M.", lang)],
      ]));
      body.appendChild(el('p', 'panel-hint',
        'Auch international: D/DD für den Tag und Y/YY/YYYY für das Jahr. Alle anderen Zeichen (Punkt, Komma, '
        + 'Schrägstrich, Leerzeichen) erscheinen, wie sie sind. Fehlt im Namen der Tag (z. B. 2026-05), entfallen '
        + 'Tag und Wochentag.'));
      body.appendChild(el('p', 'nb-legend-head', 'Weitere Einstellungen je Teil in der Anzeige'));
      body.appendChild(table(['Einstellung', 'Wirkung'], [
        ['Schreibweise', 'wie im Namen · GROSS · klein · Erster Buchstabe groß'],
        ['nur wenn mehrere', 'der Teil erscheint nur, wenn es im selben Ordner einen sonst gleichen Namen gibt (z. B. Vormittag/Abend am selben Tag)'],
        ['Werte übersetzen', 'für jeden gefundenen Wert eine Anzeige eintragen, z. B. So → Sonntag'],
      ]));
      body.appendChild(el('p', 'nb-legend-head', 'Schreibweise für Fortgeschrittene'));
      body.appendChild(table(['Im Muster', 'Bedeutung'], [
        ['{x}', 'beliebiger Text'], ['{x:3}', 'genau 3 Zeichen'], ['{x:Zahl} · {x:Buchstaben} · {x:Wort}', 'nur Ziffern · nur Buchstaben · bis zum nächsten Trennzeichen'],
        ['{x:Datum}', 'ein Datum'], ['In der Anzeige: {x:"T. MMMM JJJJ"}', 'Datum im eigenen Format'],
        ['{x:groß} · {x:klein} · {x:Anfang} · {x:mehrere}', 'Schreibweise · nur wenn mehrere'],
        ['[ … ]', 'entfällt, wenn alle Teile darin leer sind'], ['{Name}', 'der ganze ursprüngliche Name'],
        ['Werte: x.alt = neu', 'Wert eines Teils ersetzen'],
      ]));
    };
    box.addEventListener('toggle', () => { if (box.open) fill(); });
    return { el: box, refresh: () => { if (box.open) fill(); } };
  }

  /**
   * Baut die Eingabe fuer eine Freigabe.
   * @param {Array} rules   gespeicherte Regeln (samt eigenen Namen)
   * @param {Array} samples Beispielnamen [{name, kind}] des geoeffneten Ordners
   * @param {function(): Promise<Array>} [loadGroups] liefert die Namen samt
   *        Unterordnern: [{path, dirs: [], files: []}]
   * @param {function()} [onChange] bei jeder Aenderung (ab 1.5.0)
   * @returns {{el: HTMLElement, value: function(): Array, error: function(): string}}
   */
  function editor(rules, samples, loadGroups, onChange) {
    // Aenderungen melden (ab 1.5.0: Live-Vorschau in der Liste), gebuendelt
    let changeTimer = null;
    const changed = () => {
      if (typeof onChange !== 'function') return;
      clearTimeout(changeTimer);
      changeTimer = setTimeout(() => onChange(), 250);
    };
    const root = el('div', 'name-rules');
    root.appendChild(el('p', 'panel-hint',
      'Datei- und Ordnernamen anders anzeigen – die Dateien selbst bleiben, wie sie sind. Wähle einen Beispielnamen, '
      + 'sag bei jedem Teil, was er bedeutet, und stelle die Anzeige zusammen. Die erste passende Regel gilt; passt '
      + 'keine, bleibt der Name.'));
    const list = el('div', 'name-rules-list');
    root.appendChild(list);
    const items = [];
    const refreshers = [];

    // Namen fuer Beispiele, Vorschau und Auswahllisten, je Ordner gruppiert
    const sampleList = Array.isArray(samples) ? samples : [];
    let groups = [{
      path: '',
      dirs: sampleList.filter((x) => x.kind === 'dir').map((x) => x.name),
      files: sampleList.filter((x) => x.kind !== 'dir').map((x) => x.name),
    }];
    let groupsLoaded = !loadGroups;
    const join = (p, n) => (p ? p + '/' + n : n);
    const allFolders = () => {
      const set = new Set();
      groups.forEach((g) => {
        if (g.path) set.add(g.path);
        (g.dirs || []).forEach((d) => set.add(join(g.path, d)));
      });
      return [...set].sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    };
    const allFiles = () => {
      const out = [];
      groups.forEach((g) => (g.files || []).forEach((f) => out.push(join(g.path, f))));
      return out;
    };
    // Gemeinsame Vorschlagslisten fuer Ordner und Dateien
    const listId = 'nb-' + newId();
    const folderList = el('datalist');
    folderList.id = listId + '-dirs';
    const itemList = el('datalist');
    itemList.id = listId + '-items';
    root.append(folderList, itemList);
    const fillLists = () => {
      folderList.textContent = '';
      itemList.textContent = '';
      allFolders().forEach((p) => {
        folderList.appendChild(Object.assign(el('option'), { value: p }));
        itemList.appendChild(Object.assign(el('option'), { value: p, label: '📁 Ordner' }));
      });
      allFiles().slice(0, 3000).forEach((p) => itemList.appendChild(Object.assign(el('option'), { value: p, label: '🎵 Aufnahme' })));
    };
    fillLists();
    if (loadGroups) {
      loadGroups().then((loaded) => {
        if (Array.isArray(loaded) && loaded.length) groups = loaded;
      }).catch(() => {}).then(() => {
        groupsLoaded = true;
        fillLists();
        refreshers.forEach((fn) => fn());
      });
    }

    function addRule(rule, open) {
      const r = { ...(rule || {}) };
      // Neue Regeln: Datum in der Sprache des Hoerers; alte behalten Deutsch
      if (!rule) r.lang = '';
      let b = r.builder && Array.isArray(r.builder.parts) ? JSON.parse(JSON.stringify(r.builder)) : null;
      if (b) {
        b.parts.forEach((p) => { if (!p.id) p.id = newId(); });
        if (!Array.isArray(b.show)) b.show = defaultShow(b);
        if (!b.values || typeof b.values !== 'object') b.values = {};
      }
      const isNew = !rule;

      const box = el('details', 'name-rule');
      box.open = !!open;
      const summary = el('summary', 'name-rule-summary');
      box.appendChild(summary);
      const body = el('div', 'name-rule-body');
      box.appendChild(body);
      list.appendChild(box);

      // ---- 1. Beispiel
      const step1 = el('div', 'nb-step');
      step1.appendChild(el('p', 'nb-step-title', '1. Beispiel wählen'));
      const sampleInput = input(b ? b.sample : '', 'Name aus dem Ordner wählen oder eintippen', 'nb-sample');
      sampleInput.setAttribute('list', listId + '-samples');
      const sampleList2 = el('datalist');
      sampleList2.id = listId + '-samples';
      const fillSamples = () => {
        sampleList2.textContent = '';
        const seen = new Set();
        groups.forEach((g) => {
          (g.dirs || []).concat(g.files || []).forEach((n) => {
            if (seen.size < 400 && !seen.has(n)) {
              seen.add(n);
              sampleList2.appendChild(Object.assign(el('option'), { value: n }));
            }
          });
        });
      };
      fillSamples();
      const takeSample = smallButton('Zerlegen', 'Beispielname in Teile zerlegen', () => {
        const v = sampleInput.value.trim().replace(/\.[a-z0-9]{2,5}$/i, '');
        if (!v) return;
        const split = splitSample(v);
        b = { sample: v, parts: split.parts, tail: split.tail, values: {} };
        b.show = defaultShow(b);
        delete r.pattern;
        renderBuilder();
        refresh();
      }, 'nb-primary');
      const sampleRow = el('div', 'nb-row');
      sampleRow.append(sampleInput, takeSample);
      step1.append(sampleRow, sampleList2);
      sampleInput.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); takeSample.click(); } });

      // ---- 2. Teile
      const step2 = el('div', 'nb-step');
      // ---- 3. Anzeige
      const step3 = el('div', 'nb-step');
      // ---- 5. Gilt fuer
      const step5 = el('div', 'nb-step');
      step5.appendChild(el('p', 'nb-step-title', 'Wo die Regel gilt'));
      const applies = select([['both', 'Dateien und Ordner'], ['files', 'nur Dateien'], ['folders', 'nur Ordner']],
        ['files', 'folders'].includes(r.applies) ? r.applies : 'both');
      const wherePath = input(r.where && r.where.path ? r.where.path : '', 'ganze Freigabe (leer lassen) oder Ordner wählen', 'nb-where');
      wherePath.setAttribute('list', listId + '-dirs');
      const subWrap = el('label', 'panel-choice');
      const sub = el('input');
      sub.type = 'checkbox';
      sub.checked = !(r.where && r.where.sub === false);
      subWrap.append(sub, document.createTextNode(' auch in allen Unterordnern'));
      const level = select([['0', 'alle Ebenen'], ['1', 'nur Ebene 1 (direkt darin)'], ['2', 'nur Ebene 2'],
        ['3', 'nur Ebene 3'], ['4', 'nur Ebene 4'], ['5', 'nur Ebene 5'], ['6', 'nur Ebene 6']], String(parseInt(r.level, 10) || 0));
      const except = Array.isArray(r.except) ? r.except.slice() : [];
      const exceptList = el('ul', 'nb-chips');
      const exceptInput = input('', 'Ordner oder Aufnahme wählen', 'nb-except');
      exceptInput.setAttribute('list', listId + '-items');
      const exceptAdd = smallButton('+ Ausnahme', 'Ausnahme hinzufügen', () => {
        const v = exceptInput.value.trim().replace(/^\/+|\/+$/g, '');
        if (v && !except.includes(v)) except.push(v);
        exceptInput.value = '';
        renderExcept();
        refresh();
      });
      const renderExcept = () => {
        exceptList.textContent = '';
        except.forEach((x, i) => {
          const li = el('li', 'nb-chip', x);
          li.appendChild(smallButton('✕', 'Ausnahme entfernen', () => {
            except.splice(i, 1);
            renderExcept();
            refresh();
          }));
          exceptList.appendChild(li);
        });
      };
      renderExcept();
      const exceptRow = el('div', 'nb-row');
      exceptRow.append(exceptInput, exceptAdd);
      step5.append(
        labelled('Gilt für', applies),
        labelled('Nur in diesem Ordner', wherePath, 'Leer = in der ganzen Freigabe.'), subWrap,
        labelled('Ebene', level, 'Ebene 1 = direkt im gewählten Ordner bzw. in der Freigabe.'),
        labelled('Ausnahmen', exceptRow), exceptList
      );

      // ---- Sprache fuer Monats-/Tagesnamen
      const lang = select(LANGS, r.lang === undefined ? 'de' : String(r.lang || ''));
      const langWrap = labelled('Sprache für Monats- und Tagesnamen', lang);

      // ---- Fortgeschrittene
      const expert = el('details', 'nb-expert');
      expert.appendChild(el('summary', '', 'Für Fortgeschrittene: Schreibweise mit { }'));
      const pattern = input(r.pattern, 'z. B. {Kürzel:3}_{Datum}_{Titel}', 'name-rule-pattern');
      const display = input(r.display, 'z. B. {Titel} – {Kürzel}, {Datum}', 'name-rule-display');
      const map = el('textarea', 'panel-input name-rule-map');
      map.rows = 3;
      map.value = r.map || '';
      map.placeholder = 'GDB = Gottesdienst\nJUG = Jugendstunde';
      map.spellcheck = false;
      const spacesWrap = el('label', 'panel-choice');
      const spaces = el('input');
      spaces.type = 'checkbox';
      spaces.checked = r.spaces !== false;
      spacesWrap.append(spaces, document.createTextNode(' Unterstriche in den Werten als Leerzeichen zeigen'));
      const expertNote = el('p', 'panel-hint');
      const rebuild = smallButton('Mit dem Baukasten neu beginnen', '', () => {
        sampleInput.focus();
        box.open = true;
      });
      expert.append(expertNote, labelled('Muster', pattern), labelled('Anzeige', display),
        labelled('Werte ersetzen (je Zeile „alt = neu“)', map), spacesWrap);

      // ---- Vorschau und Ausprobieren
      const status = el('p', 'panel-hint name-rule-status');
      const preview = el('ul', 'name-rule-preview');
      const tryInput = input('', 'beliebigen Namen ausprobieren', 'name-rule-try');
      const tryResult = el('p', 'panel-hint name-rule-try-result');

      const actions = el('div', 'nb-row nb-actions');
      const up = smallButton('↑ Nach oben', 'Regel nach oben schieben (die erste passende gilt)', () => {
        const i = items.indexOf(item);
        if (i <= 0) return;
        items.splice(i, 1);
        items.splice(i - 1, 0, item);
        list.insertBefore(box, items[i].box);
        renumber();
        changed();
      });
      const remove = el('button', 'panel-button panel-button--danger', 'Regel entfernen');
      remove.type = 'button';
      remove.addEventListener('click', () => {
        const i = items.indexOf(item);
        if (i >= 0) items.splice(i, 1);
        box.remove();
        renumber();
        changed();
      });
      actions.append(up, remove);

      body.append(step1, step2, step3, langWrap, step5,
        el('p', 'nb-step-title', 'Vorschau'), status, preview, labelled('Namen ausprobieren', tryInput), tryResult,
        expert, actions);

      /** Regel, wie sie gespeichert wird. */
      const current = () => {
        const base = {
          applies: applies.value,
          spaces: spaces.checked,
          lang: lang.value,
        };
        const wp = wherePath.value.trim().replace(/^\/+|\/+$/g, '');
        if (wp) base.where = { path: wp, sub: sub.checked };
        if (level.value !== '0') base.level = parseInt(level.value, 10);
        if (except.length) base.except = except.slice();
        if (b) {
          const g = generate(b);
          return { ...base, pattern: g.pattern, display: g.display, map: g.map, builder: b };
        }
        return { ...base, pattern: pattern.value.trim(), display: display.value.trim(), map: map.value };
      };

      // Von Hand geaendert: Baukasten aus (Schreibweise ist dann massgeblich)
      [pattern, display, map].forEach((x) => x.addEventListener('input', () => {
        if (b) {
          b = null;
          renderBuilder();
        }
        refresh();
      }));

      function renderBuilder() {
        step2.textContent = '';
        step3.textContent = '';
        if (!b) {
          expertNote.textContent = 'Diese Regel ist von Hand geschrieben. Zum Baukasten oben einen Beispielnamen wählen und „Zerlegen“ tippen.';
          expertNote.appendChild(document.createTextNode(' '));
          expertNote.appendChild(rebuild);
          expert.open = true;
          step2.appendChild(el('p', 'panel-hint', isNew || r.pattern
            ? 'Noch kein Beispiel zerlegt.' : ''));
          return;
        }
        expertNote.textContent = 'Wird aus dem Baukasten erzeugt. Wer hier etwas ändert, schaltet den Baukasten für diese Regel aus.';
        const g = generate(b);
        pattern.value = g.pattern;
        display.value = g.display;
        map.value = g.map;

        // ---- 2. Teile: Bedeutung je Teil
        step2.appendChild(el('p', 'nb-step-title', '2. Was bedeuten die Teile?'));
        const row = el('div', 'nb-parts');
        b.parts.forEach((p, i) => {
          if (i > 0) {
            const prev = b.parts[i - 1];
            const sepLabel = p.sep === '' ? '·' : p.sep.replace(/ /g, '␣');
            const joinBtn = smallButton(prev.join ? sepLabel + ' (verbunden)' : sepLabel,
              prev.join ? 'Wieder trennen' : 'Mit dem vorigen Teil verbinden', () => {
                prev.join = !prev.join;
                renderBuilder();
                refresh();
              }, 'nb-sep' + (prev.join ? ' is-joined' : ''));
            row.appendChild(joinBtn);
          }
          const joinedWithPrev = i > 0 && b.parts[i - 1].join;
          const card = el('div', 'nb-part nb-role-' + p.role + (joinedWithPrev ? ' is-joined' : ''));
          card.appendChild(el('span', 'nb-part-text', p.text));
          if (!joinedWithPrev) {
            const role = select([['text', 'Text'], ['date', 'Datum'], ['fixed', 'immer gleich'], ['skip', 'weglassen']], p.role, 'nb-role');
            role.addEventListener('change', () => {
              p.role = role.value;
              if (p.role === 'date' && !/^Datum/.test(p.name)) p.name = b.parts.some((o) => o !== p && o.name === 'Datum') ? 'Datum 2' : 'Datum';
              if (p.role === 'text' && (p.name === p.text || /^Datum/.test(p.name))) p.name = 'Teil ' + (b.parts.indexOf(p) + 1);
              const ids = new Set((b.show || []).filter((x) => x.t === 'part').map((x) => x.id));
              if ((p.role === 'text' || p.role === 'date') && !ids.has(p.id)) {
                b.show.push(p.role === 'date' ? { t: 'part', id: p.id, fmt: 'T. MMMM JJJJ' } : { t: 'part', id: p.id });
              }
              renderBuilder();
              refresh();
            });
            card.appendChild(role);
            if (p.role === 'text' || p.role === 'date') {
              const nm = input(p.name, 'Name', 'nb-name');
              nm.title = 'Name dieses Teils (für die Anzeige)';
              nm.addEventListener('change', () => {
                let v = cleanName(nm.value) || p.name;
                if (v.toLowerCase() === 'name') v = 'Name 2';
                const taken = b.parts.some((o) => o !== p && o.name.toLowerCase() === v.toLowerCase());
                p.name = taken ? v + ' 2' : v;
                renderBuilder();
                refresh();
              });
              card.appendChild(nm);
            }
          }
          // Werte direkt beim Teil uebersetzen (ab 1.5.0, Wunsch von Henoch):
          // gefundene Werte stehen schon da, weitere mit "+ Wert"
          if (!joinedWithPrev && p.role === 'text') {
            card.classList.add('has-values');
            card.appendChild(valuesFor(p));
          }
          // Buchstaben und Ziffern ohne Trennzeichen (z. B. So1) teilen
          let cut = -1;
          for (let k = 1; p.role === 'text' && !joinedWithPrev && k < p.text.length && cut < 0; k++) {
            if (/\d/.test(p.text[k]) !== /\d/.test(p.text[k - 1])) cut = k;
          }
          if (cut > 0) {
            card.appendChild(smallButton('✂', 'Zwischen Buchstaben und Ziffern teilen', () => {
              const rest = { id: newId(), text: p.text.slice(cut), sep: '', role: 'text', name: 'Teil ' + (b.parts.length + 1), join: p.join };
              p.text = p.text.slice(0, cut);
              p.join = false;
              b.parts.splice(i + 1, 0, rest);
              renderBuilder();
              refresh();
            }));
          }
          row.appendChild(card);
        });
        step2.appendChild(row);
        step2.appendChild(el('p', 'panel-hint',
          'Bei Text-Teilen steht darunter, was angezeigt werden soll – z. B. „So“ als „Sonntag“; leer = wie im Namen. '
          + 'Ein Tipp auf ein Trennzeichen verbindet zwei Teile zu einem (z. B. „Psalm_23“ als ein Titel).'));

        // ---- 3. Anzeige zusammenstellen
        step3.appendChild(el('p', 'nb-step-title', '3. Was soll angezeigt werden?'));
        step3.appendChild(el('p', 'panel-hint', 'Zwischen zwei Teilen steht ein Leerzeichen. Mit „+ Text“ kommt eigener Text dazwischen (z. B. „ – “), mit ← → lässt sich die Reihenfolge ändern.'));
        const groupsNow = groupsOf(b);
        const byId = new Map(groupsNow.map((x) => [x.id, x]));
        b.show = b.show.filter((x) => x.t === 'text' || (byId.has(x.id) && ['text', 'date'].includes(byId.get(x.id).role)));
        const line = el('div', 'nb-show');
        b.show.forEach((x, i) => {
          const piece = el('div', 'nb-piece ' + (x.t === 'text' ? 'is-text' : 'is-part'));
          if (x.t === 'text') {
            const t = input(x.v, 'Text', 'nb-text');
            if (b.focusLast && i === b.show.length - 1) {
              delete b.focusLast;
              setTimeout(() => t.focus(), 0);
            }
            t.size = Math.max(2, String(x.v || '').length + 1);
            t.addEventListener('input', () => {
              x.v = t.value;
              t.size = Math.max(2, t.value.length + 1);
              syncGenerated();
            });
            piece.appendChild(t);
          } else {
            const gr = byId.get(x.id);
            piece.appendChild(el('span', 'nb-piece-name', gr.name));
            const opts = el('div', 'nb-piece-opts');
            if (gr.role === 'date') {
              const presets = DATE_PRESETS.map((f) => [f, dateExample(f, lang.value)]);
              const fmtSel = select(presets.concat([['*', 'eigenes Format …']]),
                DATE_PRESETS.includes(x.fmt || 'T. MMMM JJJJ') ? (x.fmt || 'T. MMMM JJJJ') : '*', 'nb-fmt');
              const fmtOwn = input(x.fmt || '', 'z. B. WW, T.M.JJ', 'nb-fmt-own');
              fmtOwn.hidden = fmtSel.value !== '*';
              fmtSel.addEventListener('change', () => {
                fmtOwn.hidden = fmtSel.value !== '*';
                if (fmtSel.value !== '*') x.fmt = fmtSel.value;
                else fmtOwn.focus();
                syncGenerated();
              });
              fmtOwn.addEventListener('input', () => {
                x.fmt = fmtOwn.value || 'T. MMMM JJJJ';
                syncGenerated();
              });
              opts.append(fmtSel, fmtOwn);
            }
            const kase = select(KASES, x.kase || '', 'nb-kase');
            kase.addEventListener('change', () => {
              x.kase = kase.value;
              syncGenerated();
            });
            const multiWrap = el('label', 'panel-choice nb-multi');
            const multi = el('input');
            multi.type = 'checkbox';
            multi.checked = !!x.multi;
            multi.addEventListener('change', () => {
              x.multi = multi.checked;
              syncGenerated();
            });
            multiWrap.append(multi, document.createTextNode(' nur wenn mehrere'));
            multiWrap.title = 'Nur zeigen, wenn es im selben Ordner einen sonst gleichen Namen gibt';
            opts.append(kase, multiWrap);
            piece.appendChild(opts);
          }
          const tools = el('div', 'nb-piece-tools');
          if (i > 0 && x.t !== 'text') {
            tools.appendChild(smallButton('←', 'Nach vorne', () => {
              b.show.splice(i - 1, 0, b.show.splice(i, 1)[0]);
              renderBuilder();
              refresh();
            }));
          }
          if (i < b.show.length - 1 && x.t !== 'text') {
            tools.appendChild(smallButton('→', 'Nach hinten', () => {
              b.show.splice(i + 1, 0, b.show.splice(i, 1)[0]);
              renderBuilder();
              refresh();
            }));
          }
          tools.appendChild(smallButton('✕', 'Aus der Anzeige nehmen', () => {
            b.show.splice(i, 1);
            renderBuilder();
            refresh();
          }));
          piece.appendChild(tools);
          line.appendChild(piece);
        });
        step3.appendChild(line);
        const add = el('div', 'nb-row nb-add');
        add.appendChild(el('span', 'panel-hint', 'Hinzufügen:'));
        groupsNow.filter((x) => x.role === 'text' || x.role === 'date').forEach((x) => {
          add.appendChild(smallButton('+ ' + x.name, '', () => {
            b.show.push(x.role === 'date' ? { t: 'part', id: x.id, fmt: 'T. MMMM JJJJ' } : { t: 'part', id: x.id });
            renderBuilder();
            refresh();
          }));
        });
        add.appendChild(smallButton('+ Text', 'Eigenen Text einfügen, z. B. „ – “ oder „Predigt vom “', () => {
          b.show.push({ t: 'text', v: ' – ' });
          b.focusLast = true;
          renderBuilder();
          refresh();
        }));
        step3.appendChild(add);
      }

      /** Werte, die in den Namen fuer diesen Teil vorkommen (hoechstens 40). */
      function foundValues(part) {
        const out = new Set();
        const g = generate(b);
        const c = compile(g.pattern);
        if (c.error) return out;
        const idx = c.slots.findIndex((x) => x.name.toLowerCase() === cleanName(part.name).toLowerCase());
        if (idx < 0) return out;
        const kinds = applies.value === 'files' ? ['files'] : applies.value === 'folders' ? ['dirs'] : ['dirs', 'files'];
        groups.forEach((gr) => kinds.forEach((k) => (gr[k] || []).forEach((n) => {
          if (out.size >= 40) return;
          const m = String(n).match(c.re);
          if (m) out.add(m[idx + 1]);
        })));
        return out;
      }

      /** Werte uebersetzen direkt im Teil (ab 1.5.0): So → Sonntag, + Wert. */
      function valuesFor(part) {
        const box = el('div', 'nb-values');
        const vals = b.values[part.id] = b.values[part.id] || {};
        box.appendChild(el('span', 'nb-values-head', 'Anzeigen als'));
        const keys = [...new Set([...foundValues(part), ...Object.keys(vals)])].slice(0, 60);
        keys.forEach((k) => {
          const row = el('div', 'nb-value-row');
          row.appendChild(el('span', 'nb-value-from', k));
          const to = input(vals[k] || '', 'wie im Namen', 'nb-value-to');
          to.title = '„' + k + '“ anzeigen als …';
          to.addEventListener('input', () => {
            vals[k] = to.value;
            syncGenerated();
          });
          row.appendChild(to);
          box.appendChild(row);
        });
        // Weitere Werte, die (noch) nicht im Ordner vorkommen
        const addRow = el('div', 'nb-value-row nb-value-add');
        const from = input('', 'Wert', 'nb-value-new');
        const to = input('', 'anzeigen als', 'nb-value-to');
        const addBtn = smallButton('+ Wert', 'Weiteren Wert hinzufügen, z. B. Mo → Montag', () => {
          const f = from.value.trim();
          if (!f) {
            from.focus();
            return;
          }
          vals[f] = to.value.trim();
          renderBuilder();
          refresh();
        });
        [from, to].forEach((x) => x.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter') {
            ev.preventDefault();
            addBtn.click();
          }
        }));
        addRow.append(from, to, addBtn);
        box.appendChild(addRow);
        return box;
      }

      function renderValues() {
        // Werte stehen seit 1.5.0 in den Teilen - bei Aenderung neu zeichnen
        if (b) renderBuilder();
      }

      /** Erzeugte Schreibweise und Vorschau nachziehen, ohne neu aufzubauen. */
      function syncGenerated() {
        if (b) {
          const g = generate(b);
          pattern.value = g.pattern;
          display.value = g.display;
          map.value = g.map;
        }
        refresh();
      }

      const item = {
        box,
        value: current,
        error: () => {
          const v = current();
          if (!b && v.pattern === '' && v.display === '') return '';
          const c = compile(v.pattern);
          if (c.error) return c.error;
          if (v.display === '') return 'Bitte festlegen, was angezeigt werden soll.';
          return '';
        },
      };
      items.push(item);

      const refresh = () => {
        const v = current();
        const err = item.error();
        status.textContent = err;
        status.classList.toggle('is-error', err !== '');
        preview.textContent = '';
        summary.textContent = 'Regel ' + (items.indexOf(item) + 1) + ': ' + (b ? b.sample : (v.pattern || 'neu'));
        if (err || v.pattern === '') {
          renumber();
          return;
        }
        const rulesNow = [v];
        const hits = [];
        let total = 0;
        groups.forEach((gr) => {
          [['dir', gr.dirs || []], ['file', gr.files || []]].forEach(([kind, names]) => {
            if (!names.length) return;
            applyAll(rulesNow, names, kind, gr.path).forEach((out, name) => {
              if (out === null || out === undefined) return;
              total++;
              if (hits.length < 8) hits.push({ name, out, where: gr.path });
            });
          });
        });
        hits.forEach((h) => {
          const li = el('li', '', h.name + ' → ' + h.out);
          if (h.where) li.appendChild(el('span', 'name-rule-where', '  (in ' + h.where + ')'));
          preview.appendChild(li);
        });
        if (hits.length) summary.textContent += ' → ' + hits[0].out;
        const sum = el('li', 'name-rule-sum');
        if (total === 0) {
          sum.textContent = groupsLoaded
            ? 'Hier und in den Unterordnern passt noch kein Name – unten einen Namen ausprobieren.'
            : 'Unterordner werden noch durchsucht …';
        } else {
          sum.textContent = total + (total === 1 ? ' Name passt' : ' Namen passen')
            + (groupsLoaded ? ' (hier und in den Unterordnern).' : ' – Unterordner werden noch durchsucht …');
        }
        preview.appendChild(sum);
        changed();
        const t = tryInput.value.trim();
        if (t !== '') {
          // Ausprobieren ohne Ort: Bereich, Ebene und Ausnahmen gelten hier nicht
          const loose = { ...v };
          delete loose.where;
          delete loose.level;
          delete loose.except;
          const asDir = v.applies !== 'files' ? apply([loose], t, 'dir') : null;
          const asFile = v.applies !== 'folders' ? apply([loose], t.replace(/\.[a-z0-9]{2,5}$/i, ''), 'file') : null;
          const out = asDir || asFile;
          tryResult.textContent = out ? '→ ' + out : 'passt nicht zu dieser Regel';
          tryResult.classList.toggle('is-error', !out);
        } else {
          tryResult.textContent = '';
        }
        renumber();
      };
      tryInput.addEventListener('input', refresh);
      [applies, sub, level].forEach((x) => x.addEventListener('change', () => {
        renderValues();
        refresh();
      }));
      wherePath.addEventListener('input', refresh);
      spaces.addEventListener('change', refresh);
      lang.addEventListener('change', () => {
        renderBuilder();
        refresh();
        legendBox.refresh();
      });
      refreshers.push(() => {
        fillSamples();
        renderValues();
        refresh();
      });
      renderBuilder();
      refresh();
    }

    function renumber() {
      items.forEach((it, i) => {
        const s = it.box.querySelector('.name-rule-summary');
        if (s) s.textContent = s.textContent.replace(/^Regel \d+/, 'Regel ' + (i + 1));
      });
    }

    const patternRules = (Array.isArray(rules) ? rules : []).filter((x) => x && !isOverride(x));
    const ownNames = (Array.isArray(rules) ? rules : []).filter(isOverride);
    patternRules.forEach((x) => addRule(x, false));

    const add = el('button', 'panel-button', '+ Regel');
    add.type = 'button';
    add.addEventListener('click', () => addRule(null, true));
    root.appendChild(add);

    // ---- Eigene Namen fuer einzelne Ordner und Dateien
    const own = el('div', 'nb-own');
    own.appendChild(el('p', 'nb-step-title', 'Eigene Namen für einzelne Ordner oder Aufnahmen'));
    own.appendChild(el('p', 'panel-hint', 'Gilt genau für diesen einen Ordner bzw. diese eine Aufnahme und geht allen Regeln vor.'));
    const ownList = el('div', 'nb-own-list');
    own.appendChild(ownList);
    const ownItems = [];
    const addOwn = (o) => {
      const row = el('div', 'nb-row nb-own-row');
      const path = input(o ? o.path : '', 'Ordner oder Aufnahme wählen', 'nb-own-path');
      path.setAttribute('list', listId + '-items');
      const name = input(o ? o.name : '', 'angezeigter Name', 'nb-own-name');
      const it = {
        value: () => {
          const p = path.value.trim().replace(/^\/+|\/+$/g, '');
          const isFolder = allFolders().includes(p) || (o && o.target === 'dir' && o.path === p);
          return { kind: 'name', target: isFolder ? 'dir' : 'file', path: p, name: name.value.trim() };
        },
      };
      row.append(path, el('span', 'panel-hint', '→'), name, smallButton('✕', 'Eigenen Namen entfernen', () => {
        ownItems.splice(ownItems.indexOf(it), 1);
        row.remove();
        changed();
      }));
      [path, name].forEach((x) => x.addEventListener('input', changed));
      ownList.appendChild(row);
      ownItems.push(it);
    };
    ownNames.forEach(addOwn);
    const addOwnBtn = el('button', 'panel-button', '+ Eigener Name');
    addOwnBtn.type = 'button';
    addOwnBtn.addEventListener('click', () => addOwn(null));
    own.appendChild(addOwnBtn);
    root.appendChild(own);

    const legendBox = legend(() => {
      const first = items[0] && items[0].value();
      return first ? first.lang : '';
    });
    root.appendChild(legendBox.el);

    return {
      el: root,
      value: () => items.map((i) => i.value()).filter((v) => v.pattern !== '' && v.display !== '')
        .concat(ownItems.map((i) => i.value()).filter((v) => v.path !== '' && v.name !== '')),
      error: () => {
        for (const i of items) {
          const e = i.error();
          if (e) return e;
        }
        return '';
      },
    };
  }

  return { apply, applyAll, remember, compile, editor, generate, splitSample, formatDate };
})();
