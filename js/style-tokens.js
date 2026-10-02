/**
 * Frei einstellbare Gestaltung (ab 0.17): Werte, Vorlagen, Umsetzung in
 * CSS-Variablen und der Editor mit Vorschau.
 *
 * Genutzt von
 *   - app.js (Player): Anwenden einer Gestaltung, Editor fuer
 *     "Benutzerdefiniert" (persoenlich und je Freigabe), Farbvorlagen fuer
 *     "Modern"
 *   - settings.js (Verwaltung): Editor fuer "Vom Administrator
 *     bereitgestellt", Farbvorlagen fuer "Modern"
 *
 * Bewusst als globales window.AAStyle: In der Verwaltung laedt Nextcloud
 * Skripte als Module, dort waere eine Konstante sonst nicht sichtbar.
 *
 * Die Vorgabewerte muessen mit lib/Service/StyleTokens.php
 * uebereinstimmen und ergeben genau "Modern" mit den Standardfarben.
 */
window.AAStyle = (() => {
  'use strict';

  const DEFAULTS = Object.freeze({
    base: 'modern',
    accent: '#b9793f',
    bar: '#291c12',
    surface: '#fffaf2',
    background: '#a86a3d',
    barText: '',
    surfaceText: '',
    bgStyle: 'gradient',
    radius: 100,
    blur: 20,
    barOpacity: 55,
    surfaceOpacity: 82,
    shadow: 100,
    font: 'system',
    titleFont: 'serif',
    fontScale: 100,
    density: 'normal',
    imageDim: 55,
  });

  const RANGES = {
    radius: [0, 200],
    blur: [0, 60],
    barOpacity: [10, 100],
    surfaceOpacity: [10, 100],
    shadow: [0, 200],
    fontScale: [85, 130],
    imageDim: [0, 90],
  };

  const CHOICES = {
    base: ['modern', 'classic'],
    bgStyle: ['gradient', 'solid'],
    font: ['system', 'serif', 'rounded', 'humanist', 'mono'],
    titleFont: ['system', 'serif', 'rounded', 'humanist', 'mono'],
    density: ['compact', 'normal', 'comfortable'],
  };

  const FONTS = {
    system: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
    serif: '"Iowan Old Style", "Palatino Linotype", "URW Palladio L", Georgia, serif',
    rounded: 'ui-rounded, "SF Pro Rounded", "Nunito", "Varela Round", "Quicksand", system-ui, sans-serif',
    humanist: '"Avenir Next", Avenir, "Segoe UI", "Helvetica Neue", Ubuntu, Cantarell, sans-serif',
    mono: 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
  };
  const FONT_LABELS = {
    system: 'System (schlicht)',
    serif: 'Serif (klassisch)',
    rounded: 'Rund (freundlich)',
    humanist: 'Klar (Avenir)',
    mono: 'Schreibmaschine',
  };
  const DENSITY = { compact: 0.7, normal: 1, comfortable: 1.3 };

  /** Farbvorlagen fuer "Modern" (Akzent, Leisten, Grundton). */
  const PALETTES = [
    { id: 'terracotta', name: 'Terracotta', accent: '#b9793f', bar: '#291c12', base: '#a86a3d' },
    { id: 'ocean', name: 'Ozean', accent: '#3fa7d6', bar: '#0f2233', base: '#2f6f95' },
    { id: 'forest', name: 'Wald', accent: '#6fae5a', bar: '#14241a', base: '#4f7a45' },
    { id: 'teal', name: 'Lagune', accent: '#2bb5a0', bar: '#0f2624', base: '#1f7f73' },
    { id: 'lavender', name: 'Lavendel', accent: '#a58be0', bar: '#221a33', base: '#7a64b0' },
    { id: 'rose', name: 'Rosé', accent: '#e0889a', bar: '#2e1820', base: '#b0637a' },
    { id: 'cherry', name: 'Kirsche', accent: '#e05a47', bar: '#2a1210', base: '#a8402f' },
    { id: 'sun', name: 'Sonne', accent: '#f0b429', bar: '#2a2110', base: '#c98a1c' },
    { id: 'night', name: 'Nacht', accent: '#7c9cff', bar: '#0d1020', base: '#2a3160' },
    { id: 'graphite', name: 'Graphit', accent: '#9aa5b1', bar: '#1c1f24', base: '#56606b' },
  ];

  /** Vorlagen fuer "Benutzerdefiniert" - Ausgangspunkte, danach frei aenderbar. */
  const PRESETS = [
    { id: 'modern', name: 'Modern', values: {} },
    {
      id: 'flat', name: 'Flach & klar',
      values: { base: 'classic', accent: '#0082c9', bar: '#1b2530', surface: '#ffffff', background: '#dfe7ee',
        radius: 60, font: 'system', titleFont: 'system', shadow: 60 },
    },
    {
      id: 'square', name: 'Eckig',
      values: { radius: 0, blur: 0, barOpacity: 92, surfaceOpacity: 96, shadow: 40, titleFont: 'system',
        accent: '#d9822b', bar: '#1f1f1f', surface: '#f7f7f5', background: '#6b6b6b', bgStyle: 'solid' },
    },
    {
      id: 'soft', name: 'Weich & rund',
      values: { radius: 200, blur: 36, barOpacity: 45, surfaceOpacity: 70, font: 'rounded', titleFont: 'rounded',
        density: 'comfortable', accent: '#a58be0', bar: '#221a33', surface: '#faf7ff', background: '#7a64b0' },
    },
    {
      id: 'dark', name: 'Dunkel',
      values: { accent: '#7c9cff', bar: '#0d1020', surface: '#1b1f2e', background: '#2a3160', surfaceOpacity: 88,
        barOpacity: 70, titleFont: 'humanist', font: 'humanist' },
    },
    {
      id: 'paper', name: 'Papier',
      values: { accent: '#b0412e', bar: '#35332e', surface: '#fbf8f1', background: '#d9d2c3', bgStyle: 'solid',
        blur: 0, barOpacity: 95, surfaceOpacity: 100, shadow: 30, radius: 40, font: 'serif', titleFont: 'serif' },
    },
    {
      id: 'compact', name: 'Kompakt',
      values: { density: 'compact', fontScale: 92, radius: 70, titleFont: 'system' },
    },
  ];

  // ------------------------------------------------------------------
  // Werte bereinigen (wie StyleTokens::normalize auf dem Server)
  // ------------------------------------------------------------------
  function hex(value) {
    if (typeof value !== 'string') return '';
    let h = value.trim().toLowerCase();
    if (/^#[0-9a-f]{3}$/.test(h)) h = '#' + h.slice(1).split('').map((c) => c + c).join('');
    return /^#[0-9a-f]{6}$/.test(h) ? h : '';
  }

  function normalize(input) {
    const out = { ...DEFAULTS };
    if (!input || typeof input !== 'object') return out;
    ['accent', 'bar', 'surface', 'background'].forEach((k) => {
      const c = hex(input[k]);
      if (c) out[k] = c;
    });
    ['barText', 'surfaceText'].forEach((k) => {
      if (input[k] === '') out[k] = '';
      const c = hex(input[k]);
      if (c) out[k] = c;
    });
    Object.keys(CHOICES).forEach((k) => {
      if (CHOICES[k].includes(input[k])) out[k] = input[k];
    });
    Object.keys(RANGES).forEach((k) => {
      const n = Number(input[k]);
      if (input[k] !== '' && input[k] !== null && Number.isFinite(n)) {
        out[k] = Math.max(RANGES[k][0], Math.min(RANGES[k][1], Math.round(n)));
      }
    });
    return out;
  }

  // ------------------------------------------------------------------
  // Farbrechnung
  // ------------------------------------------------------------------
  function rgb(h) {
    const c = hex(h) || '#000000';
    return { r: parseInt(c.slice(1, 3), 16), g: parseInt(c.slice(3, 5), 16), b: parseInt(c.slice(5, 7), 16) };
  }
  const toHex = ({ r, g, b }) => '#' + [r, g, b].map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('');
  const rgba = (c, a) => `rgba(${c.r}, ${c.g}, ${c.b}, ${Math.round(a * 1000) / 1000})`;
  const mix = (a, b, t) => ({ r: a.r + (b.r - a.r) * t, g: a.g + (b.g - a.g) * t, b: a.b + (b.b - a.b) * t });
  const round = (c) => ({ r: Math.round(c.r), g: Math.round(c.g), b: Math.round(c.b) });
  const darken = (c, t) => round(mix(c, { r: 0, g: 0, b: 0 }, t));
  const lighten = (c, t) => round(mix(c, { r: 255, g: 255, b: 255 }, t));

  function luminance({ r, g, b }) {
    const f = (v) => {
      const s = v / 255;
      return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  }
  const isLight = (c) => luminance(c) > 0.4;
  const contrast = (a, b) => {
    const la = luminance(a); const lb = luminance(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  };

  /**
   * Akzentfarbe als Schrift auf einer Flaeche (ab 0.17.1): so weit
   * abgedunkelt bzw. aufgehellt, bis sie mindestens 4,5:1 Kontrast hat
   * (WCAG AA fuer normalen Text). Der Farbton bleibt erkennbar.
   */
  function readableOn(fgHex, bgHex, min = 4.5) {
    const bg = rgb(bgHex);
    let fg = rgb(fgHex);
    const toDark = isLight(bg);
    for (let i = 0; i < 20 && contrast(fg, bg) < min; i++) {
      fg = toDark ? darken(fg, 0.12) : lighten(fg, 0.12);
    }
    return toHex(fg);
  }

  /**
   * Schriftfarbe fuer eine Flaeche: dunkel auf hell, hell auf dunkel.
   * Getoent mit der Leisten- bzw. Flaechenfarbe, damit es nicht nach reinem
   * Schwarz/Weiss aussieht (bei den Standardfarben ergibt das die Braun-
   * und Cremetoene von "Modern").
   */
  function autoText(bg, s) {
    if (isLight(bg)) {
      const bar = rgb(s.bar);
      return luminance(bar) < 0.05 ? bar : rgb('#1d1d1f');
    }
    const surface = rgb(s.surface);
    return luminance(surface) > 0.85 ? surface : rgb('#f7f7f7');
  }

  /** Gradient aus einem Grundton - gleiche Rechnung wie bisher in app.js */
  function gradient(baseHex) {
    const base = rgb(baseHex);
    const light = lighten(base, 0.45);
    const dark = round({ r: base.r * 0.45, g: base.g * 0.45, b: base.b * 0.45 });
    const deep = round({ r: base.r * 0.16, g: base.g * 0.16, b: base.b * 0.16 });
    return [
      `radial-gradient(circle at 12% 18%, ${rgba(light, 0.65)}, transparent 42%)`,
      `radial-gradient(circle at 88% 12%, ${rgba(base, 0.55)}, transparent 46%)`,
      `radial-gradient(circle at 82% 82%, ${rgba(dark, 0.55)}, transparent 48%)`,
      `radial-gradient(circle at 15% 85%, ${rgba(deep, 0.6)}, transparent 46%)`,
      `linear-gradient(160deg, ${toHex(light)} 0%, ${toHex(base)} 40%, ${toHex(dark)} 75%, ${toHex(deep)} 100%)`,
    ].join(', ');
  }

  /**
   * Alle abgeleiteten Werte einer Gestaltung an einer Stelle - fuer die
   * App und fuer die Vorschau im Editor.
   */
  function compute(input) {
    const s = normalize(input);
    const accent = rgb(s.accent);
    const bar = rgb(s.bar);
    const surface = rgb(s.surface);
    const background = rgb(s.background);
    const barText = s.barText ? rgb(s.barText) : autoText(bar, s);
    const surfaceText = s.surfaceText ? rgb(s.surfaceText) : autoText(surface, s);
    const bo = s.barOpacity / 100;
    const so = s.surfaceOpacity / 100;
    const sh = s.shadow / 100;
    const rf = s.radius / 100;
    const shadowTint = darken(bar, 0.4);
    const accentText = isLight(accent) ? rgb('#1d1d1f') : rgb('#ffffff');
    const surfaceLight = isLight(surface);

    return {
      s,
      flat: s.base === 'classic',
      accent, accentDeep: darken(accent, 0.3), accentText,
      bar, barText, surface, surfaceText, background,
      barBg: rgba(bar, bo),
      barBgStrong: rgba(bar, Math.min(1, bo + 0.17)),
      barBorder: isLight(bar) ? 'rgba(0, 0, 0, 0.12)' : 'rgba(255, 255, 255, 0.16)',
      rowBg: rgba(surface, so),
      lightBg: rgba(surface, so * 0.67),
      lightBgStrong: rgba(surface, Math.min(1, so * 0.88)),
      lightBorder: surfaceLight ? 'rgba(255, 255, 255, 0.65)' : 'rgba(255, 255, 255, 0.12)',
      pressBg: rgba(surfaceLight ? lighten(surface, 0.3) : lighten(surface, 0.08), 0.95),
      // Laufende Zeile fast deckend - sonst wird sie ueber dunklen Bildern
      // grau und der Titel unlesbar (0.17.1)
      activeBg: rgba(surface, Math.max(0.9, so)),
      buttonBg: surfaceLight ? 'rgba(255, 255, 255, 0.75)' : rgba(lighten(surface, 0.1), 0.9),
      lightShadow: sh === 0 ? 'none'
        : `0 10px 30px ${rgba(shadowTint, 0.16 * sh)}, inset 0 1px 0 rgba(255, 255, 255, ${Math.min(0.7, 0.7 * sh)})`,
      darkShadow: sh === 0 ? 'none'
        : `0 14px 36px ${rgba(shadowTint, Math.min(0.9, 0.4 * sh))}, inset 0 1px 0 rgba(255, 255, 255, 0.14)`,
      blur: s.blur,
      rf,
      pill: rf >= 1 ? '999px' : `${Math.round(rf * 22)}px`,
      rfc: Math.min(1, rf),
      fontBody: FONTS[s.font],
      fontDisplay: FONTS[s.titleFont],
      fontScale: s.fontScale / 100,
      density: DENSITY[s.density],
      bgImage: s.bgStyle === 'solid' ? 'none' : gradient(s.background),
      bgColor: s.background,
      imageOverlay: rgba(darken(background, 0.8), s.imageDim / 100),
      themeColor: s.base === 'classic' ? s.accent : s.bar,
    };
  }

  const soft = (c, a) => rgba(c, a);
  const s0 = (c) => toHex(c);

  /**
   * CSS-Variablen fuer die App.
   *   doc:  auf <html> - ersetzt die Werte aus :root in style.css
   *   root: auf #audioarchive - nur beim flachen Grundstil. Dort baut der
   *         Nextcloud-Aufbau aus style.css auf Nextclouds Variablen auf;
   *         die werden hier fuer den Player mit den eigenen Farben belegt
   *         (die Nextcloud-Leiste ausserhalb bleibt unberuehrt).
   */
  function appVars(input) {
    const c = compute(input);
    const doc = {
      '--color-accent': toHex(c.accent),
      '--color-accent-deep': toHex(c.accentDeep),
      '--color-accent-rgb': `${c.accent.r}, ${c.accent.g}, ${c.accent.b}`,
      '--color-ink': toHex(c.surfaceText),
      '--color-ink-soft': soft(c.surfaceText, 0.68),
      '--color-cream': toHex(c.barText),
      '--color-cream-soft': soft(c.barText, 0.72),
      '--glass-dark-bg': c.barBg,
      '--glass-dark-bg-strong': c.barBgStrong,
      '--glass-dark-border': c.barBorder,
      '--glass-dark-shadow': c.darkShadow,
      '--glass-row-bg': c.rowBg,
      '--glass-light-bg': c.lightBg,
      '--glass-light-bg-strong': c.lightBgStrong,
      '--glass-light-border': c.lightBorder,
      '--glass-light-shadow': c.lightShadow,
      '--glass-blur': `blur(${c.blur}px) saturate(180%)`,
      '--aa-blur': `${c.blur}px`,
      '--aa-surface-press': c.pressBg,
      '--aa-surface-active': c.activeBg,
      '--aa-surface-button': c.buttonBg,
      '--aa-accent-on-surface': readableOn(s0(c.accent), s0(c.surface)),
      '--aa-rf': String(c.rf),
      '--aa-rfc': String(c.rfc),
      '--aa-pill': c.pill,
      '--aa-density': String(c.density),
      '--aa-font-scale': String(c.fontScale),
      '--font-body': c.fontBody,
      '--font-display': c.fontDisplay,
    };

    const root = {};
    if (c.flat) {
      const text = c.surfaceText;
      const surf = c.surface;
      const primaryLight = round(mix(surf, c.accent, 0.16));
      Object.assign(root, {
        '--color-main-background': toHex(surf),
        '--color-main-background-blur': rgba(surf, Math.max(0.7, c.s.barOpacity / 100)),
        '--color-main-text': toHex(text),
        '--color-text-maxcontrast': toHex(round(mix(surf, text, 0.66))),
        '--color-background-hover': toHex(round(mix(surf, text, 0.05))),
        '--color-background-dark': toHex(round(mix(surf, text, 0.09))),
        '--color-border': toHex(round(mix(surf, text, 0.1))),
        '--color-border-maxcontrast': toHex(round(mix(surf, text, 0.5))),
        '--color-primary-element': toHex(c.accent),
        '--color-primary-element-hover': toHex(darken(c.accent, 0.1)),
        '--color-primary-element-text': toHex(c.accentText),
        '--color-primary-element-light': toHex(primaryLight),
        '--color-primary-element-light-hover': toHex(round(mix(surf, c.accent, 0.24))),
        '--color-primary-element-light-text': toHex(isLight(primaryLight) ? darken(c.accent, 0.55) : lighten(c.accent, 0.7)),
        '--color-box-shadow': rgba(darken(c.bar, 0.3), Math.min(0.8, 0.3 * c.s.shadow / 100)),
        '--border-radius-element': `${Math.round(8 * c.rf)}px`,
        '--border-radius-container-large': `${Math.round(16 * c.rf)}px`,
        '--filter-background-blur': `blur(${c.blur}px)`,
        '--font-face': c.fontBody,
        '--color-background-plain': c.bgColor,
        // Flach: ruhiger Verlauf wie Nextclouds Hintergrund, nicht der
        // kraeftige Glas-Untergrund von Modern
        '--image-background': c.s.bgStyle === 'solid' ? 'none'
          : `linear-gradient(160deg, ${toHex(lighten(c.background, 0.25))}, ${toHex(c.background)} 55%, ${toHex(darken(c.background, 0.22))})`,
      });
    }
    return { doc, root, computed: c };
  }

  /** Alle Variablennamen, die appVars je setzen kann - zum Aufraeumen. */
  const ALL_DOC_KEYS = Object.keys(appVars(DEFAULTS).doc);
  const ALL_ROOT_KEYS = Object.keys(appVars({ base: 'classic' }).root);

  // ------------------------------------------------------------------
  // Editor
  // ------------------------------------------------------------------
  function h(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  let uid = 0;

  /**
   * Kleine Nachbildung des Players. Wird mit denselben Werten eingefaerbt
   * wie die App, damit man sieht, was man einstellt - auch in der
   * Verwaltung, wo es keinen Player gibt.
   */
  function createPreview() {
    const box = h('div', 'aa-se-preview');
    box.setAttribute('aria-hidden', 'true');
    box.innerHTML = ''
      + '<div class="aa-se-pv-bg"></div>'
      + '<div class="aa-se-pv-top"><span class="aa-se-pv-title">Aufnahmen</span><span class="aa-se-pv-btn"></span></div>'
      + '<div class="aa-se-pv-list">'
      + '<div class="aa-se-pv-row"><span class="aa-se-pv-ico"></span><span class="aa-se-pv-text"><b>2026_09</b><i>12 Aufnahmen</i></span></div>'
      + '<div class="aa-se-pv-row is-active"><span class="aa-se-pv-eq"><i></i><i></i><i></i></span><span class="aa-se-pv-text"><b>Vortrag – Teil 1</b><i>48:12</i></span></div>'
      + '<div class="aa-se-pv-row"><span class="aa-se-pv-ico"></span><span class="aa-se-pv-text"><b>Interview</b><i>31:05</i></span></div>'
      + '</div>'
      + '<div class="aa-se-pv-player"><span class="aa-se-pv-cover"></span><span class="aa-se-pv-text"><b>Vortrag – Teil 1</b><i>Archiv</i><span class="aa-se-pv-progress"><span></span></span></span><span class="aa-se-pv-play"></span></div>';

    return {
      el: box,
      update(style, imageUrl) {
        const c = compute(style);
        const set = (k, v) => box.style.setProperty(k, v);
        box.classList.toggle('is-flat', c.flat);
        set('--se-accent', toHex(c.accent));
        set('--se-accent-text', toHex(c.accentText));
        set('--se-bar-bg', c.flat ? rgba(c.surface, 0.92) : c.barBg);
        set('--se-bar-text', c.flat ? toHex(c.surfaceText) : toHex(c.barText));
        set('--se-bar-border', c.flat ? toHex(round(mix(c.surface, c.surfaceText, 0.1))) : c.barBorder);
        set('--se-row-bg', c.flat ? 'transparent' : c.rowBg);
        set('--se-row-active', c.flat ? toHex(round(mix(c.surface, c.accent, 0.16))) : c.activeBg);
        set('--se-row-text', toHex(c.surfaceText));
        set('--se-row-soft', soft(c.surfaceText, 0.66));
        set('--se-row-border', c.flat ? 'transparent' : c.lightBorder);
        set('--se-shadow', c.flat ? 'none' : c.lightShadow);
        set('--se-dark-shadow', c.darkShadow);
        set('--se-blur', `blur(${c.blur}px)`);
        set('--se-r', String(c.rf));
        set('--se-pill', c.pill);
        set('--se-font', c.fontBody);
        set('--se-title-font', c.fontDisplay);
        set('--se-scale', String(c.fontScale));
        set('--se-density', String(c.density));
        let bg;
        if (imageUrl) {
          bg = `linear-gradient(${c.imageOverlay}, ${c.imageOverlay}), url("${String(imageUrl).replace(/["\\\n]/g, '')}") center / cover`;
        } else if (c.flat) {
          bg = toHex(c.surface);
        } else {
          bg = c.bgImage === 'none' ? c.bgColor : c.bgImage;
        }
        set('--se-bg', bg);
      },
    };
  }

  /** Farbvorlagen als Knoepfe. onPick({accent, bar, base}) */
  function createPalettePicker(onPick, current) {
    const wrap = h('div', 'aa-se-palettes');
    const buttons = [];
    PALETTES.forEach((p) => {
      const b = h('button', 'aa-se-swatch');
      b.type = 'button';
      b.title = p.name;
      b.setAttribute('aria-label', 'Farbvorlage ' + p.name);
      b.style.setProperty('--sw-a', p.accent);
      b.style.setProperty('--sw-b', p.bar);
      b.style.setProperty('--sw-c', p.base);
      b.appendChild(h('span', 'aa-se-swatch-dots'));
      b.appendChild(h('span', 'aa-se-swatch-name', p.name));
      b.addEventListener('click', () => {
        onPick({ accent: p.accent, bar: p.bar, base: p.base });
        mark(p);
      });
      buttons.push([p, b]);
      wrap.appendChild(b);
    });
    function mark(values) {
      buttons.forEach(([p, b]) => {
        const on = !!values && hex(values.accent) === p.accent && hex(values.bar) === p.bar && hex(values.base) === p.base;
        b.classList.toggle('is-selected', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }
    mark(current);
    return { el: wrap, mark };
  }

  /**
   * Editor fuer alle Werte.
   *
   * @param {object} opts
   *   value     Startwerte (werden bereinigt)
   *   onChange  function(style) bei jeder Aenderung
   *   imageUrl  Hintergrundbild fuer die Vorschau (optional)
   * @returns {{el, get, set, setImage}}
   */
  function createEditor(opts) {
    const id = 'aa-se-' + (++uid);
    let value = normalize(opts.value);
    let imageUrl = opts.imageUrl || '';
    const onChange = typeof opts.onChange === 'function' ? opts.onChange : () => {};
    const root = h('div', 'aa-style-editor');
    const preview = createPreview();
    const controls = {};

    const layout = h('div', 'aa-se-layout');
    const form = h('div', 'aa-se-form');
    const side = h('div', 'aa-se-side');
    side.appendChild(preview.el);
    side.appendChild(h('p', 'aa-se-note', 'Vorschau'));
    layout.append(side, form);
    root.appendChild(layout);

    function emit() {
      preview.update(value, imageUrl);
      palettes.mark({ accent: value.accent, bar: value.bar, base: value.background });
      syncVisibility();
      onChange({ ...value });
    }

    function section(title, open) {
      const d = h('details', 'aa-se-section');
      if (open) d.open = true;
      d.appendChild(h('summary', 'aa-se-summary', title));
      const body = h('div', 'aa-se-body');
      d.appendChild(body);
      form.appendChild(d);
      return body;
    }

    function row(label, control, hint) {
      const r = h('label', 'aa-se-row');
      r.appendChild(h('span', 'aa-se-label', label));
      r.appendChild(control);
      if (hint) r.appendChild(h('span', 'aa-se-hint', hint));
      return r;
    }

    function colorControl(key, label) {
      const input = h('input', 'aa-se-color');
      input.type = 'color';
      input.addEventListener('input', () => { value[key] = input.value; emit(); });
      controls[key] = { set: (v) => { input.value = v || '#000000'; } };
      const r = h('label', 'aa-se-color-field');
      r.append(input, h('span', 'aa-se-hint', label));
      return r;
    }

    function rangeControl(key, label, unit, hint) {
      const [min, max] = RANGES[key];
      const wrap = h('span', 'aa-se-range');
      const input = h('input', '');
      input.type = 'range';
      input.min = String(min);
      input.max = String(max);
      input.step = '1';
      const out = h('output', 'aa-se-out');
      input.addEventListener('input', () => {
        value[key] = Number(input.value);
        out.textContent = input.value + unit;
        emit();
      });
      controls[key] = { set: (v) => { input.value = String(v); out.textContent = v + unit; } };
      wrap.append(input, out);
      return row(label, wrap, hint);
    }

    function selectControl(key, label, options) {
      const sel = h('select', 'aa-se-select');
      options.forEach(([v, text]) => {
        const o = h('option', '', text);
        o.value = v;
        sel.appendChild(o);
      });
      sel.addEventListener('change', () => { value[key] = sel.value; emit(); });
      controls[key] = { set: (v) => { sel.value = v; } };
      return row(label, sel);
    }

    function segmentedControl(key, label, options) {
      const wrap = h('span', 'aa-se-seg');
      wrap.setAttribute('role', 'radiogroup');
      const inputs = [];
      options.forEach(([v, text]) => {
        const l = h('label', 'aa-se-seg-item');
        const input = h('input', '');
        input.type = 'radio';
        input.name = id + '-' + key;
        input.value = v;
        input.addEventListener('change', () => { if (input.checked) { value[key] = v; emit(); } });
        l.append(input, h('span', '', text));
        wrap.appendChild(l);
        inputs.push(input);
      });
      controls[key] = { set: (v) => inputs.forEach((i) => { i.checked = i.value === v; }) };
      return row(label, wrap);
    }

    // ---------- Vorlagen ----------
    const presetsBody = section('Vorlagen', true);
    const presetRow = h('div', 'aa-se-presets');
    PRESETS.forEach((p) => {
      const b = h('button', 'aa-se-chip', p.name);
      b.type = 'button';
      b.addEventListener('click', () => {
        value = normalize({ ...DEFAULTS, ...p.values });
        syncControls();
        emit();
      });
      presetRow.appendChild(b);
    });
    presetsBody.appendChild(presetRow);
    presetsBody.appendChild(h('span', 'aa-se-hint', 'Farbvorlagen (ändern nur die Farben):'));
    const palettes = createPalettePicker((p) => {
      value.accent = p.accent;
      value.bar = p.bar;
      value.background = p.base;
      syncControls();
      emit();
    });
    presetsBody.appendChild(palettes.el);

    // ---------- Farben ----------
    const colorsBody = section('Farben', true);
    const colorGrid = h('div', 'aa-se-colors');
    colorGrid.append(
      colorControl('accent', 'Akzent'),
      colorControl('bar', 'Leisten'),
      colorControl('surface', 'Listen & Karten'),
      colorControl('background', 'Hintergrund')
    );
    colorsBody.appendChild(colorGrid);
    const barHint = h('span', 'aa-se-hint', 'Beim flachen Grundstil färben „Listen & Karten“ auch die Leisten.');
    colorsBody.appendChild(barHint);

    const autoText = h('label', 'aa-se-check');
    const autoTextInput = h('input', '');
    autoTextInput.type = 'checkbox';
    autoText.append(autoTextInput, h('span', '', 'Schriftfarben automatisch (gut lesbar)'));
    colorsBody.appendChild(autoText);
    const textGrid = h('div', 'aa-se-colors');
    const barTextField = colorControl('barText', 'Schrift auf Leisten');
    const surfaceTextField = colorControl('surfaceText', 'Schrift in Listen');
    textGrid.append(barTextField, surfaceTextField);
    colorsBody.appendChild(textGrid);
    autoTextInput.addEventListener('change', () => {
      if (autoTextInput.checked) {
        value.barText = '';
        value.surfaceText = '';
      } else {
        const c = compute(value);
        value.barText = toHex(c.barText);
        value.surfaceText = toHex(c.surfaceText);
      }
      syncControls();
      emit();
    });

    // ---------- Form ----------
    const shapeBody = section('Form & Glas', false);
    shapeBody.appendChild(segmentedControl('base', 'Grundstil', [['modern', 'Modern (Glas)'], ['classic', 'Flach (wie Nextcloud)']]));
    shapeBody.appendChild(rangeControl('radius', 'Ecken', ' %', '0 % = eckig, 100 % = wie Modern, 200 % = sehr rund'));
    shapeBody.appendChild(rangeControl('blur', 'Unschärfe (Blur)', ' px', 'Glaseffekt hinter Leisten und laufendem Titel'));
    shapeBody.appendChild(rangeControl('barOpacity', 'Deckkraft der Leisten', ' %'));
    shapeBody.appendChild(rangeControl('surfaceOpacity', 'Deckkraft der Listen', ' %'));
    shapeBody.appendChild(rangeControl('shadow', 'Schatten', ' %'));

    // ---------- Schrift ----------
    const fontBody = section('Schrift & Abstände', false);
    const fontOptions = Object.keys(FONTS).map((k) => [k, FONT_LABELS[k]]);
    fontBody.appendChild(selectControl('titleFont', 'Überschriften', fontOptions));
    fontBody.appendChild(selectControl('font', 'Text', fontOptions));
    fontBody.appendChild(rangeControl('fontScale', 'Schriftgröße', ' %'));
    fontBody.appendChild(segmentedControl('density', 'Abstände', [['compact', 'Kompakt'], ['normal', 'Normal'], ['comfortable', 'Großzügig']]));

    // ---------- Hintergrund ----------
    const bgBody = section('Hintergrund', false);
    bgBody.appendChild(segmentedControl('bgStyle', 'Ohne Bild', [['gradient', 'Farbverlauf'], ['solid', 'Einfarbig']]));
    bgBody.appendChild(rangeControl('imageDim', 'Bild abdunkeln', ' %', 'Nur mit Hintergrundbild'));

    const reset = h('button', 'aa-se-reset', 'Alles zurücksetzen');
    reset.type = 'button';
    reset.addEventListener('click', () => {
      value = normalize(DEFAULTS);
      syncControls();
      emit();
    });
    form.appendChild(reset);

    function syncVisibility() {
      const auto = value.barText === '' && value.surfaceText === '';
      autoTextInput.checked = auto;
      textGrid.hidden = auto;
      barHint.hidden = value.base !== 'classic';
    }

    function syncControls() {
      Object.keys(controls).forEach((k) => {
        let v = value[k];
        if ((k === 'barText' || k === 'surfaceText') && !v) {
          const c = compute(value);
          v = toHex(k === 'barText' ? c.barText : c.surfaceText);
        }
        controls[k].set(v);
      });
      syncVisibility();
    }

    syncControls();
    preview.update(value, imageUrl);
    palettes.mark({ accent: value.accent, bar: value.bar, base: value.background });

    return {
      el: root,
      get: () => ({ ...value }),
      set(v) {
        value = normalize(v);
        syncControls();
        preview.update(value, imageUrl);
        palettes.mark({ accent: value.accent, bar: value.bar, base: value.background });
      },
      setImage(url) {
        imageUrl = url || '';
        preview.update(value, imageUrl);
      },
    };
  }

  /** Werte, mit denen die Karte "Klassisch" ungefaehr wie Nextcloud aussieht. */
  function classicThumb(primary) {
    return normalize({
      base: 'classic', accent: hex(primary) || '#00679e', surface: '#ffffff', bar: '#ffffff',
      background: hex(primary) || '#00679e', font: 'system', titleFont: 'system', radius: 100,
    });
  }

  /** Werte fuer die Karte "Modern" mit bestimmten Farben. */
  function modernThumb(colors) {
    const c = colors || {};
    return normalize({ accent: c.accent, bar: c.bar, background: c.base });
  }

  /**
   * Auswahl der Gestaltung als Karten mit kleiner Vorschau.
   *
   * @param {object} opts
   *   name      Name der Radio-Gruppe
   *   options   [{value, label, desc, style, disabled}]  style = Werte fuer die Vorschau
   *   value     gewaehlter Wert
   *   onChange  function(value)
   * @returns {{el, get, set, setThumb(value, style)}}
   */
  function createDesignCards(opts) {
    const wrap = h('div', 'aa-design-cards');
    wrap.setAttribute('role', 'radiogroup');
    const cards = new Map();
    opts.options.forEach((o) => {
      const card = h('label', 'aa-design-card');
      const input = h('input', '');
      input.type = 'radio';
      input.name = opts.name;
      input.value = o.value;
      input.disabled = !!o.disabled;
      input.addEventListener('change', () => {
        if (input.checked && typeof opts.onChange === 'function') opts.onChange(o.value);
      });
      const thumb = h('span', 'aa-design-card-thumb');
      const preview = createPreview();
      preview.update(o.style || DEFAULTS);
      thumb.appendChild(preview.el);
      card.append(input, thumb, h('span', 'aa-design-card-name', o.label));
      if (o.desc) card.appendChild(h('span', 'aa-design-card-desc', o.desc));
      wrap.appendChild(card);
      cards.set(o.value, { input, preview, card });
    });
    const api = {
      el: wrap,
      get() {
        const checked = wrap.querySelector('input:checked');
        return checked ? checked.value : '';
      },
      set(value) {
        cards.forEach(({ input }, v) => { input.checked = v === value; });
      },
      setThumb(value, style) {
        const c = cards.get(value);
        if (c) c.preview.update(style);
      },
      setDisabled(value, disabled) {
        const c = cards.get(value);
        if (c) c.input.disabled = !!disabled;
      },
    };
    api.set(opts.value);
    return api;
  }

  return {
    DEFAULTS,
    PALETTES,
    classicThumb,
    modernThumb,
    createDesignCards,
    PRESETS,
    normalize,
    compute,
    appVars,
    gradient,
    ALL_DOC_KEYS,
    ALL_ROOT_KEYS,
    createEditor,
    createPreview,
    createPalettePicker,
    readableOn,
    parse(json) {
      if (!json) return null;
      try {
        const data = JSON.parse(json);
        return data && typeof data === 'object' ? normalize(data) : null;
      } catch (e) {
        return null;
      }
    },
  };
})();
