/**
 * app.js
 * Steuert Login-Flow (inkl. Admin-Trigger), Anzeige-Einstellungen
 * (Titel/Untertitel/Hintergrund) und die Explorer-artige Ordnernavigation.
 */

(() => {
  const loginScreen = document.getElementById('login-screen');
  const mainScreen = document.getElementById('main-screen');
  const loginForm = document.getElementById('login-form');
  const loginPassword = document.getElementById('login-password');
  const loginError = document.getElementById('login-error');
  const loginSubmit = document.getElementById('login-submit');
  const loginTitle = document.getElementById('login-title');
  const logoutBtn = document.getElementById('logout-btn');
  const topbarTitle = document.getElementById('topbar-title');
  const topbarSubtitle = document.getElementById('topbar-subtitle');
  const libraryStatus = document.getElementById('library-status');
  const breadcrumbEl = document.getElementById('breadcrumb');
  const listContainer = document.getElementById('list-container');
  const offlineBar = document.getElementById('offline-bar');
  const offlineBtn = document.getElementById('offline-btn');
  const offlineInfo = document.getElementById('offline-info');

  // Navigationszustand des Explorers: bildet die ECHTE Ordnerstruktur ab.
  // view.source ist die Quelle ('shared' = gemeinsamer Ordner, 'home' =
  // eigene Dateien), view.path der relative Pfad des geöffneten Ordners
  // ('' = Audio-Hauptverzeichnis), beliebig tief verschachtelt.
  let currentEntries = [];
  let view = { source: 'shared', path: '' };

  // ------------------------------------------------------------------
  // Installierte App, aber mit Nextcloud-Rahmen gestartet?
  // ------------------------------------------------------------------
  /*
   * Bis 0.7 war die Startadresse der installierten App /apps/audioarchive/.
   * Dort liegt jetzt die Fassung MIT Nextcloud-Leiste. Bereits installierte
   * Apps starten dort, bis der Browser das neue Manifest uebernommen hat -
   * sie werden deshalb auf die Fassung ohne Leiste umgeleitet.
   */
  const runsAsInstalledApp = window.matchMedia('(display-mode: standalone)').matches
    || window.matchMedia('(display-mode: fullscreen)').matches
    || window.navigator.standalone === true;

  if (AudioArchive.isEmbedded() && runsAsInstalledApp && AudioArchive.standaloneUrl) {
    location.replace(AudioArchive.standaloneUrl);
    return;
  }

  // ------------------------------------------------------------------
  // Knopf "App installieren"
  // ------------------------------------------------------------------
  /*
   * Innerhalb von Nextcloud kann die Seite nicht installiert werden (dort
   * gilt Nextclouds Manifest). Der Knopf fuehrt deshalb zur Fassung ohne
   * Leiste; dort bietet der Browser die Installation an.
   *
   * In der Fassung ohne Leiste erscheint der Knopf nur, wenn der Browser
   * die Installation direkt anbietet (Chrome, Edge, Android). Safari kennt
   * das nicht - dort geht es ueber "Teilen > Zum Home-Bildschirm".
   */
  const installBtn = document.getElementById('install-btn');
  let installPrompt = null;

  if (installBtn) {
    if (AudioArchive.isEmbedded()) {
      installBtn.href = AudioArchive.standaloneUrl;
      installBtn.hidden = false;
    } else if (!runsAsInstalledApp) {
      window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installPrompt = event;
        installBtn.hidden = false;
      });

      installBtn.addEventListener('click', async (event) => {
        event.preventDefault();
        if (!installPrompt) return;
        const prompt = installPrompt;
        installPrompt = null;
        installBtn.hidden = true;
        prompt.prompt();
        try {
          await prompt.userChoice;
        } catch (e) {
          // Abgebrochen - nichts weiter zu tun
        }
      });

      window.addEventListener('appinstalled', () => {
        installPrompt = null;
        installBtn.hidden = true;
      });
    }
  }

  // ------------------------------------------------------------------
  // Service Worker registrieren (PWA-Installierbarkeit + App-Shell-Cache)
  // ------------------------------------------------------------------
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      // Adresse und Geltungsbereich kommen vom Server: Der Worker wird ueber
      // eine Route ausgeliefert, damit er den ganzen App-Pfad abdecken darf.
      navigator.serviceWorker.register(AudioArchive.serviceWorker, { scope: AudioArchive.scope }).catch((err) => {
        console.warn('Service Worker Registrierung fehlgeschlagen:', err);
      });
    });
  }

  // ------------------------------------------------------------------
  // Öffentliche Anzeige-Einstellungen (Titel/Untertitel/Hintergrund) -
  // werden schon VOR dem Login geladen, da sie auch auf dem Login-Screen
  // sichtbar sein sollen.
  // ------------------------------------------------------------------
  /**
   * Titel, Untertitel und Farben stehen bereits im Dokument - der Server hat
   * sie beim Ausliefern der Seite hineingeschrieben. Anders als in der
   * eigenstaendigen Fassung ist dafuer kein eigener Abruf noetig, und die
   * Seite steht sofort richtig da, ohne kurzes Umspringen.
   */
  /**
   * Zeigt die Beta-Kennzeichnung: ein kleines Zeichen neben dem Titel und
   * darunter den vom Administrator formulierten Hinweis ueber dem Pfad.
   *
   * Der Link wird bewusst NUR mit seiner Beschriftung angezeigt, nicht mit
   * der vollen Adresse - lange Adressen sprengen auf dem Telefon die Zeile.
   */
  function applyBetaNotice() {
    if (!AudioArchive.betaEnabled) return;

    // Kennzeichnung in der Kopfzeile
    const badge = document.createElement('span');
    badge.className = 'beta-badge';
    badge.textContent = 'Beta';
    // In die Ueberschrift hinein, nicht daneben: Als eigenstaendiges
    // Element neben dem h1 wuerde es in einer eigenen Zeile landen.
    topbarTitle.appendChild(badge);

    const notice = document.getElementById('beta-notice');
    if (!notice) return;

    const text = AudioArchive.betaText.trim();
    const url = AudioArchive.betaLinkUrl.trim();
    const label = AudioArchive.betaLinkLabel.trim() || 'Mehr erfahren';

    if (text === '' && url === '') return;

    notice.textContent = '';

    if (text !== '') {
      const span = document.createElement('span');
      span.textContent = text;
      notice.appendChild(span);
    }

    if (url !== '') {
      const link = document.createElement('a');
      /*
       * textContent statt innerHTML: Der Text stammt zwar vom
       * Administrator, wird aber allen Nutzern angezeigt - auch denen der
       * oeffentlichen Seite. So kann daraus kein Markup werden.
       */
      link.textContent = label;
      link.href = url;
      link.target = '_blank';
      link.rel = 'noreferrer noopener';
      notice.appendChild(document.createTextNode(' '));
      notice.appendChild(link);
    }

    notice.hidden = false;
  }

  function applySettingsFromDocument() {
    applyTheme(AudioArchive.themeAccent, AudioArchive.themeBar, AudioArchive.themeBase);

    const title = AudioArchive.headerTitle || 'Audio Archive';
    const subtitle = AudioArchive.headerSubtitle || '';

    document.title = title;
    topbarTitle.textContent = title;
    loginTitle.textContent = title;

    topbarSubtitle.textContent = subtitle;
    topbarSubtitle.hidden = subtitle === '';

    const loginSubtitle = document.getElementById('login-subtitle');
    if (loginSubtitle) {
      loginSubtitle.textContent = subtitle;
      loginSubtitle.hidden = subtitle === '';
    }

    /*
     * Der Abmelde-Knopf beendet nur die Sitzung der oeffentlichen Seite.
     * Fuer angemeldete Nextcloud-Nutzer ergibt er keinen Sinn - die melden
     * sich ueber Nextcloud selbst ab.
     */
    if (!AudioArchive.isPublic()) {
      logoutBtn.hidden = true;
    }

    applyBetaNotice();
  }

  // ------------------------------------------------------------------
  // Design/Farben aus den Einstellungen anwenden.
  // Gesetzt werden nur CSS-Variablen - das gesamte Stylesheet arbeitet
  // ausschliesslich mit diesen Variablen, deshalb faerbt sich die komplette
  // Oberflaeche (Leisten, Knoepfe, Akzente) automatisch mit um.
  // ------------------------------------------------------------------
  function hexToRgb(hex) {
    if (typeof hex !== 'string') return null;
    let h = hex.trim().replace('#', '');
    if (h.length === 3) h = h.split('').map((c) => c + c).join('');
    if (!/^[0-9a-fA-F]{6}$/.test(h)) return null;
    return {
      r: parseInt(h.slice(0, 2), 16),
      g: parseInt(h.slice(2, 4), 16),
      b: parseInt(h.slice(4, 6), 16),
    };
  }

  /** Mischt eine Farbe Richtung Schwarz (amount 0..1) - fuer Hover-/Tiefton. */
  function darken({ r, g, b }, amount) {
    const f = (v) => Math.max(0, Math.round(v * (1 - amount)));
    return `rgb(${f(r)}, ${f(g)}, ${f(b)})`;
  }

  /** Mischt eine Farbe Richtung Weiss (amount 0..1) - fuer helle Verlaufsstufen. */
  function lighten({ r, g, b }, amount) {
    const f = (v) => Math.round(v + (255 - v) * amount);
    return { r: f(r), g: f(g), b: f(b) };
  }

  /**
   * Baut den Verlaufs-Hintergrund aus einem einzigen Grundton auf, damit
   * sich die gesamte Oberflaeche (z.B. auf Blau oder Gruen) umstellen laesst,
   * ohne dass fuer jede Farbe ein eigener Verlauf hinterlegt werden muss.
   */
  function buildBackgroundGradient(base) {
    const rgb = (c, a) => `rgba(${c.r}, ${c.g}, ${c.b}, ${a})`;
    const light = lighten(base, 0.45);
    const mid = base;
    const dark = { r: base.r * 0.45, g: base.g * 0.45, b: base.b * 0.45 };
    const deep = { r: base.r * 0.16, g: base.g * 0.16, b: base.b * 0.16 };
    const rnd = (c) => ({ r: Math.round(c.r), g: Math.round(c.g), b: Math.round(c.b) });

    return [
      `radial-gradient(circle at 12% 18%, ${rgb(light, 0.65)}, transparent 42%)`,
      `radial-gradient(circle at 88% 12%, ${rgb(mid, 0.55)}, transparent 46%)`,
      `radial-gradient(circle at 82% 82%, ${rgb(rnd(dark), 0.55)}, transparent 48%)`,
      `radial-gradient(circle at 15% 85%, ${rgb(rnd(deep), 0.6)}, transparent 46%)`,
      `linear-gradient(160deg, rgb(${light.r}, ${light.g}, ${light.b}) 0%, `
        + `rgb(${mid.r}, ${mid.g}, ${mid.b}) 40%, `
        + `rgb(${Math.round(dark.r)}, ${Math.round(dark.g)}, ${Math.round(dark.b)}) 75%, `
        + `rgb(${Math.round(deep.r)}, ${Math.round(deep.g)}, ${Math.round(deep.b)}) 100%)`,
    ].join(', ');
  }

  function applyTheme(accentHex, barHex, baseHex) {
    // Bei Nextcloud-Gestaltung bleiben Farben und Hintergrund ganz bei
    // Nextclouds Variablen - eigene Werte wuerden sie hier ueberschreiben.
    if (AudioArchive.isNextcloudDesign()) return;

    const root = document.documentElement.style;

    const accent = hexToRgb(accentHex);
    if (accent) {
      const { r, g, b } = accent;
      root.setProperty('--color-accent', `rgb(${r}, ${g}, ${b})`);
      root.setProperty('--color-accent-deep', darken(accent, 0.3));
      root.setProperty('--color-accent-rgb', `${r}, ${g}, ${b}`);
    }

    const bar = hexToRgb(barHex);
    if (bar) {
      const { r, g, b } = bar;
      root.setProperty('--glass-dark-bg', `rgba(${r}, ${g}, ${b}, 0.55)`);
      root.setProperty('--glass-dark-bg-strong', `rgba(${r}, ${g}, ${b}, 0.72)`);

      // Auch die Statusleisten-Farbe der installierten App mitfaerben
      const meta = document.querySelector('meta[name="theme-color"]');
      if (meta) meta.setAttribute('content', barHex);
    }

    // Grundton der Flaeche: Nur anwenden, wenn KEIN eigenes Hintergrundbild
    // gesetzt ist - sonst wuerde der Verlauf das Foto ueberdecken.
    const base = hexToRgb(baseHex);
    themeGradient = base ? buildBackgroundGradient(base) : '';
    applyBackgroundLayer();
  }

  // Merkt sich den aktuellen Stand, damit Verlauf und Hintergrundbild
  // unabhaengig voneinander gesetzt werden koennen.
  let themeGradient = '';
  // Vom Administrator gesetztes Hintergrundbild; leer bedeutet: Verlauf
  // aus dem Grundton.
  let backgroundImageUrl = AudioArchive.backgroundUrl || '';

  function applyBackgroundLayer() {
    const layer = document.getElementById('bg-layer');
    if (!layer) return;

    if (backgroundImageUrl) {
      layer.style.backgroundImage =
        `linear-gradient(rgba(20,14,9,0.55), rgba(20,14,9,0.55)), url("${backgroundImageUrl}")`;
      document.body.classList.add('has-bg-image');
    } else {
      layer.style.backgroundImage = themeGradient;
      document.body.classList.remove('has-bg-image');
    }
  }

  // ------------------------------------------------------------------
  // Bildschirm-Wechsel
  // ------------------------------------------------------------------
  function showLogin() {
    loginScreen.hidden = false;
    mainScreen.hidden = true;
  }

  function showMain() {
    loginScreen.hidden = true;
    mainScreen.hidden = false;
    // Explorer immer sauber im Hauptordner öffnen: angemeldet zuerst der
    // gemeinsame Ordner, sofern eingerichtet, sonst die eigenen Dateien
    view = { source: startSource(), path: '' };
    // Ohne Verbindung mit einer Quelle beginnen, fuer die etwas gespeichert ist
    if (offlineMode) {
      const available = offlineSources();
      if (!available.has(view.source) && available.size > 0) {
        view.source = available.has('shared') ? 'shared' : 'home';
      }
    }
    // Basis-Historie-Eintrag setzen (ersetzt den aktuellen Eintrag, statt
    // einen neuen zu erzeugen) - Ausgangspunkt für die Zurück-Geste/-Taste.
    history.replaceState({ view }, '');
    loadLibrary('', view.source);
    Tree.init();
  }

  // ------------------------------------------------------------------
  // Explorer-Navigation über die History-API führen, damit die
  // Zurück-Geste (z. B. Android-Swipe) in der Ordnerstruktur eine Ebene
  // zurückgeht, statt direkt die App zu schließen. Jede Navigation
  // "tiefer" oder "zur Seite" (Breadcrumb) erzeugt einen History-Eintrag;
  // der Zurück-Button/die Geste löst dann popstate aus (siehe unten),
  // statt die App zu verlassen. Nur ganz oben auf der Monatsebene
  // schließt Zurück die App wie gewohnt.
  // ------------------------------------------------------------------
  function navigate(newPath, source = view.source) {
    view = { source, path: newPath };
    history.pushState({ view }, '');
    loadLibrary(newPath, source);
  }

  function startSource() {
    if (AudioArchive.isPublic() || !AudioArchive.loggedIn) return 'shared';
    return AudioArchive.hasShared ? 'shared' : 'home';
  }

  /** Anzeigename einer Quelle - Wurzel im Pfad und im Ordnerbaum. */
  function sourceLabel(source) {
    return source === 'home' ? 'Meine Dateien' : (AudioArchive.loggedIn && !AudioArchive.isPublic()
      ? 'Gemeinsame Aufnahmen'
      : 'Aufnahmen');
  }

  window.addEventListener('popstate', (e) => {
    if (mainScreen.hidden) return; // nicht relevant, solange nicht eingeloggt
    view = (e.state && e.state.view) ? e.state.view : { source: startSource(), path: '' };
    if (!view.source) view.source = 'shared';
    loadLibrary(view.path, view.source);
  });

  // ------------------------------------------------------------------
  // Session-Status beim App-Start prüfen
  // ------------------------------------------------------------------
  // ==================================================================
  // OFFLINE-BETRIEB
  //
  // Ohne Verbindung ist keiner der PHP-Endpunkte erreichbar - weder die
  // Anmeldung noch die Ordnerliste. Damit heruntergeladene Aufnahmen
  // trotzdem nutzbar sind, merkt sich die App zwei Dinge lokal:
  //   1. einen Pruefwert des Passworts (SHA-256 mit zufaelligem Salz),
  //      damit die Anmeldung auch offline geprueft werden kann
  //   2. die Ordnerlisten der offline gespeicherten Ordner
  //
  // Wichtig zur Einordnung: Die eigentliche Passwortpruefung bleibt online
  // Sache des Servers. Der lokale Pruefwert schuetzt nur davor, dass jemand
  // die App am fremden Geraet einfach oeffnet - wer vollen Zugriff auf das
  // Geraet hat, koennte die Dateien im Browser-Cache ohnehin auslesen.
  // ==================================================================
  const LS_AUTH = 'audioarchive_offline_auth';
  const LS_INDEX = 'audioarchive_offline_index';

  let offlineMode = false;

  // Welche Funktionen der Admin freigegeben hat. Bis die Einstellungen
  // geladen sind, gelten die Standardwerte.
  const features = { offline: true, download: false };

  function lsGet(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; }
  }

  function lsSet(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* Speicher voll */ }
  }

  async function hashPassword(password, salt) {
    const data = new TextEncoder().encode(salt + ':' + password);
    const digest = await crypto.subtle.digest('SHA-256', data);
    return Array.from(new Uint8Array(digest))
      .map((b) => b.toString(16).padStart(2, '0'))
      .join('');
  }

  /** Nach erfolgreicher Anmeldung am Server den lokalen Pruefwert ablegen. */
  async function rememberPasswordForOffline(password) {
    if (!window.crypto || !crypto.subtle) return; // nur ueber HTTPS verfuegbar
    try {
      const saltBytes = crypto.getRandomValues(new Uint8Array(16));
      const salt = Array.from(saltBytes).map((b) => b.toString(16).padStart(2, '0')).join('');
      lsSet(LS_AUTH, { salt, hash: await hashPassword(password, salt) });
    } catch (err) {
      // Ohne gespeicherten Pruefwert ist nur die Online-Anmeldung moeglich.
    }
  }

  /** Liest den Pruefwert, notfalls aus dem Schluessel der aelteren Fassung. */
  function storedAuth() {
    const current = lsGet(LS_AUTH);
    if (current) return current;

    const legacy = lsGet('gp_offline_auth');
    if (legacy) {
      lsSet(LS_AUTH, legacy);
      return legacy;
    }

    return null;
  }

  async function checkPasswordOffline(password) {
    const stored = storedAuth();
    if (!stored || !stored.salt || !stored.hash) return false;
    if (!window.crypto || !crypto.subtle) return false;
    try {
      return (await hashPassword(password, stored.salt)) === stored.hash;
    } catch (err) {
      return false;
    }
  }

  /** Verzeichnis der offline gespeicherten Ordner: { pfad: {entries, savedAt} } */
  function offlineIndex() {
    const current = lsGet(LS_INDEX);
    if (current) return current;

    /*
     * Uebernahme aus einer aelteren Fassung: Die Schluessel hiessen frueher
     * "gp_...". Ohne diesen Schritt waere ein bestehendes Verzeichnis
     * unsichtbar, und die App wuerde es aus dem Audio-Speicher
     * rekonstruieren - dabei gingen Kuenstler, Album und Spieldauer
     * verloren, weil im Speicher nur die Dateien selbst liegen.
     */
    const legacy = lsGet('gp_offline_index');
    if (legacy) {
      lsSet(LS_INDEX, legacy);
      return legacy;
    }

    return {};
  }

  /*
   * Schluessel im Verzeichnis. Der gemeinsame Ordner verwendet - wie bis
   * 0.10 - den blossen Pfad, damit bestehende Verzeichnisse gueltig bleiben.
   * Die eigenen Dateien bekommen eine Vorsilbe, damit sich gleich
   * benannte Ordner beider Quellen nicht in die Quere kommen.
   */
  const HOME_PREFIX = '@@home:';

  function indexKey(source, path) {
    return source === 'home' ? HOME_PREFIX + path : path;
  }

  /** Umkehrung von indexKey(): { source, path } */
  function parseIndexKey(key) {
    return key.startsWith(HOME_PREFIX)
      ? { source: 'home', path: key.slice(HOME_PREFIX.length) }
      : { source: 'shared', path: key };
  }

  /** Nur die Eintraege einer Quelle: { pfad: {entries, savedAt} } */
  function offlineIndexFor(source) {
    const index = offlineIndex();
    const result = {};
    Object.keys(index).forEach((key) => {
      const parsed = parseIndexKey(key);
      if (parsed.source === source) result[parsed.path] = index[key];
    });
    return result;
  }

  function setOfflineFolder(source, path, entries) {
    const index = offlineIndex();
    index[indexKey(source, path)] = { entries, savedAt: Date.now() };
    lsSet(LS_INDEX, index);
  }

  function removeOfflineFolder(source, path) {
    const index = offlineIndex();
    delete index[indexKey(source, path)];
    lsSet(LS_INDEX, index);
  }

  /** Quellen, fuer die offline etwas gespeichert ist */
  function offlineSources() {
    const sources = new Set();
    Object.keys(offlineIndex()).forEach((key) => sources.add(parseIndexKey(key).source));
    return sources;
  }

  function hasOfflineContent() {
    return Object.keys(offlineIndex()).length > 0;
  }

  /**
   * Stellt das Ordner-Verzeichnis aus dem Audio-Cache wieder her.
   *
   * Noetig fuer Aufnahmen, die gespeichert wurden, BEVOR es dieses
   * Verzeichnis gab - und generell als Selbstheilung, falls der
   * localStorage-Eintrag verloren geht (z.B. Browserdaten teilweise
   * geloescht), die Audiodateien im Cache aber noch liegen.
   */
  async function rebuildOfflineIndexFromCache() {
    if (!offlineSupportedStorage()) return false;

    try {
      // Auch den Speichernamen der aelteren Fassung mitlesen
      const keys = [];
      for (const name of [OFFLINE_AUDIO_CACHE_NAME, 'gemeinde-offline-audio']) {
        try {
          const cache = await caches.open(name);
          keys.push(...await cache.keys());
        } catch (e) { /* Speicher existiert nicht */ }
      }
      if (keys.length === 0) return false;

      const folders = {};

      keys.forEach((request) => {
        let path;
        let source;
        try {
          const url = new URL(request.url);
          // Nur Aufnahmen - die mitgespeicherten Ordnerlisten liegen im
          // selben Speicher
          if (!url.pathname.endsWith('/api/stream')) return;
          path = url.searchParams.get('path');
          source = url.searchParams.get('source') === 'home' ? 'home' : 'shared';
        } catch (e) {
          return;
        }
        if (!path) return;

        const parts = path.split('/');
        const fileName = parts.pop();
        const folderKey = indexKey(source, parts.join('/'));

        if (!folders[folderKey]) folders[folderKey] = [];
        folders[folderKey].push({
          type: 'file',
          name: fileName.replace(/\.[^.]+$/, ''),
          file: fileName,
          path,
        });
      });

      const index = offlineIndex();
      let added = false;

      Object.keys(folders).forEach((folderKey) => {
        if (!index[folderKey]) {
          folders[folderKey].sort((a, b) => a.name.localeCompare(b.name, 'de', { numeric: true }));
          index[folderKey] = { entries: folders[folderKey], savedAt: Date.now(), rebuilt: true };
          added = true;
        }
      });

      if (added) lsSet(LS_INDEX, index);
      return added || Object.keys(index).length > 0;
    } catch (err) {
      return false;
    }
  }

  function offlineSupportedStorage() {
    return 'caches' in window;
  }

  const OFFLINE_AUDIO_CACHE_NAME = 'audioarchive-offline-audio';

  /**
   * Baut im Offline-Betrieb die Ordneransicht aus dem lokalen Verzeichnis.
   * Auf der obersten Ebene werden die gespeicherten Ordner selbst als
   * Eintraege gezeigt, darunter deren Dateien.
   */
  /**
   * Baut die Offline-Ansicht als echten Ordnerbaum auf.
   *
   * Gespeichert wird je Ordner unter seinem VOLLEN Pfad. Damit die Ansicht
   * ohne Verbindung genauso aussieht wie online, werden die Zwischenebenen
   * aus diesen Pfaden abgeleitet: Liegt etwa "2026_08/Sonntag" vor, zeigt die
   * oberste Ebene "2026_08" und darin erst "Sonntag" - statt wie zuvor alle
   * gespeicherten Ordner flach nebeneinander.
   */
  function offlineEntriesFor(path, source) {
    const index = offlineIndexFor(source);
    const prefix = path === '' ? '' : path + '/';

    const dirs = new Map();
    const files = [];

    Object.keys(index).forEach((folderPath) => {
      if (folderPath === path) {
        // Dateien genau dieses Ordners
        (index[folderPath].entries || []).forEach((entry) => files.push(entry));
        return;
      }

      if (prefix !== '' && !folderPath.startsWith(prefix)) return;
      if (prefix === '' && folderPath === '') return;

      // Naechste Ebene unterhalb des aktuellen Pfades
      const rest = folderPath.slice(prefix.length);
      if (rest === '') return;

      const name = rest.split('/')[0];
      const childPath = prefix + name;

      if (!dirs.has(childPath)) {
        dirs.set(childPath, { type: 'dir', name, path: childPath, count: 0 });
      }
    });

    // Aufnahmen je Unterordner zaehlen - auch die in tieferen Ebenen
    dirs.forEach((dir) => {
      Object.keys(index).forEach((folderPath) => {
        if (folderPath === dir.path || folderPath.startsWith(dir.path + '/')) {
          dir.count += (index[folderPath].entries || []).filter((e) => e.type === 'file').length;
        }
      });
    });

    const dirList = Array.from(dirs.values())
      .sort((a, b) => a.name.localeCompare(b.name, 'de', { numeric: true }));

    files.sort((a, b) => a.name.localeCompare(b.name, 'de', { numeric: true }));

    return dirList.concat(files);
  }

  /**
   * Liest die gespeicherte Ordnerliste. Gibt null zurueck, wenn fuer diesen
   * Ordner nichts hinterlegt ist.
   */
  async function offlineEntriesFromCache(path, source) {
    if (!offlineSupportedStorage()) return null;

    try {
      const cache = await caches.open(OFFLINE_AUDIO_CACHE_NAME);
      const cached = await cache.match(listUrlFor(path, source));
      if (!cached) return null;

      const data = await cached.json();
      return Array.isArray(data.entries) ? data.entries : null;
    } catch (err) {
      return null;
    }
  }

  function enterOfflineMode() {
    offlineMode = true;
    document.body.classList.add('is-offline');

    // Angemeldete Nutzer geben hier ihre selbst vergebene PIN ein, nicht
    // das Nextcloud-Passwort - das muss auf dem Bildschirm stehen.
    const hint = document.querySelector('.login-hint');
    if (hint) {
      hint.textContent = AudioArchive.isPublic()
        ? 'Keine Internetverbindung – bitte das Zugangspasswort eingeben.'
        : 'Keine Internetverbindung – bitte die Offline-PIN eingeben.';
    }

    const banner = document.getElementById('offline-banner');
    if (banner) banner.hidden = false;

    // Der Baum zeigt ohne Verbindung nur, was gespeichert ist
    Tree.rebuild();

    // Im Offline-Betrieb gibt es kein Admin-Portal und keine Abmeldung am
    // Server - der Abmelde-Knopf wuerde nur in einen Fehler laufen.
    const logout = document.getElementById('logout-btn');
    if (logout) logout.hidden = true;

    // Persoenliche Einstellungen brauchen den Server
    const settingsBtn = document.getElementById('user-settings-btn');
    if (settingsBtn) settingsBtn.hidden = true;
  }

  /**
   * Klaert beim Start, ob bereits Zugang besteht.
   *
   * Angemeldete Nextcloud-Nutzer kommen immer durch. Auf der oeffentlichen
   * Seite entscheidet, ob dort schon das gemeinsame Passwort eingegeben
   * wurde.
   */
  async function checkSession() {
    try {
      const res = await fetch(AudioArchive.api('public/status'), { credentials: 'same-origin' });
      const data = await res.json();

      if (data.authenticated) {
        showMain();
      } else {
        showLogin();
      }
    } catch (err) {
      // Server nicht erreichbar. Liegen Aufnahmen offline vor und ist ein
      // Pruefwert hinterlegt, ist die Anmeldung trotzdem moeglich.
      await rebuildOfflineIndexFromCache();

      if (storedAuth()) {
        enterOfflineMode();
      } else if (hasOfflineContent()) {
        offlineHint(
          'Es sind Aufnahmen gespeichert, aber die Offline-Anmeldung ist noch nicht '
          + 'eingerichtet. Dazu bitte einmal mit Internetverbindung das Passwort eingeben.'
        );
      }
      showLogin();
    }
  }

  /** Blendet einen Hinweis auf dem Anmelde-Bildschirm ein. */
  function offlineHint(message) {
    loginError.textContent = message;
    loginError.hidden = false;
  }

  // ------------------------------------------------------------------
  // Login
  // ------------------------------------------------------------------
  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    loginError.hidden = true;

    const entered = loginPassword.value;

    loginSubmit.disabled = true;
    loginSubmit.textContent = 'Anmelden …';

    // Kein Netz: gegen den lokal hinterlegten Pruefwert anmelden
    if (offlineMode) {
      const ok = await checkPasswordOffline(entered);
      loginSubmit.disabled = false;
      loginSubmit.textContent = 'Anmelden';

      if (ok) {
        loginPassword.value = '';
        await rebuildOfflineIndexFromCache();
        showMain();
      } else {
        loginError.textContent = 'Passwort falsch (Anmeldung ohne Internetverbindung).';
        loginError.hidden = false;
      }
      return;
    }

    try {
      const res = await fetch(AudioArchive.api('public/login'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: AudioArchive.publicToken, password: entered }),
      });
      const data = await res.json();

      if (res.ok && data.success) {
        // Fuer die spaetere Anmeldung ohne Verbindung merken
        await rememberPasswordForOffline(entered);
        loginPassword.value = '';
        showMain();
      } else {
        loginError.textContent = data.error || 'Anmeldung fehlgeschlagen.';
        loginError.hidden = false;
      }
    } catch (err) {
      await rebuildOfflineIndexFromCache();

      if (storedAuth() && await checkPasswordOffline(entered)) {
        // Verbindung erst jetzt verloren - trotzdem in den Offline-Betrieb
        enterOfflineMode();
        loginPassword.value = '';
        showMain();
        return;
      }
      loginError.textContent = 'Verbindung zum Server fehlgeschlagen.';
      loginError.hidden = false;
    } finally {
      loginSubmit.disabled = false;
      loginSubmit.textContent = 'Anmelden';
    }
  });

  // ------------------------------------------------------------------
  // Logout
  // ------------------------------------------------------------------
  logoutBtn.addEventListener('click', async () => {
    try {
      await fetch(AudioArchive.api('public/logout'), { method: 'POST', credentials: 'same-origin' });
    } catch (err) {
      // ignorieren, wir loggen lokal trotzdem aus
    }
    listContainer.innerHTML = '';
    showLogin();
  });

  // ------------------------------------------------------------------
  // Ordnerstruktur laden
  // ------------------------------------------------------------------
  async function loadLibrary(path = '', source = view.source) {
    libraryStatus.hidden = false;
    libraryStatus.textContent = 'Lade Aufnahmen \u2026';
    listContainer.innerHTML = '';

    // Ohne Verbindung: Ansicht aus dem Offline-Speicher aufbauen
    if (offlineMode) {
      view = { source, path };

      /*
       * Zuerst die gespeicherte Ordnerliste versuchen - sie enthaelt
       * Kuenstler, Album und Spieldauer. Der Service Worker beantwortet den
       * Aufruf ohne Verbindung aus dem Speicher. Erst wenn dort nichts
       * liegt, greift das lokale Verzeichnis als Rueckfall; dort fehlen
       * diese Angaben moeglicherweise.
       */
      currentEntries = await offlineEntriesFromCache(path, source);
      if (currentEntries === null) {
        currentEntries = offlineEntriesFor(path, source);
      }
      tagEntries(currentEntries, source);
      Tree.select(source, path);
      renderBreadcrumb();

      if (currentEntries.length === 0) {
        offlineBar.hidden = true;
        libraryStatus.textContent = 'Keine offline gespeicherten Aufnahmen vorhanden.';
        return;
      }

      /*
       * Ist dieser Ordner bereits offline gespeichert, das hinterlegte
       * Verzeichnis mit den frischen Angaben auffrischen. Dadurch fuellen
       * sich Kuenstler, Album und Spieldauer auch bei Eintraegen wieder auf,
       * die aus dem Audio-Speicher rekonstruiert werden mussten - sie sind
       * dort nicht enthalten.
       */
      const index = offlineIndex();
      if (index[indexKey(source, view.path)]) {
        const files = currentEntries.filter((e) => e.type === 'file');
        if (files.length > 0) setOfflineFolder(source, view.path, files);
      }

      libraryStatus.hidden = true;
      renderEntries();
      refreshOfflineBar();
      return;
    }

    try {
      const res = await fetch(AudioArchive.listUrl(path, source), {
        credentials: 'same-origin',
      });

      if (res.status === 401) {
        showLogin();
        return;
      }

      const data = await res.json();

      if (!res.ok) {
        libraryStatus.textContent = data.error || 'Fehler beim Laden der Aufnahmen.';
        renderBreadcrumb();
        return;
      }

      view = { source, path: data.path || '' };
      currentEntries = tagEntries(data.entries || [], source);
      Tree.select(source, view.path);

      // Die vom Administrator freigegebenen Funktionen liefert der Server
      // zusammen mit der Ordnerliste mit.
      if (data.features) {
        features.offline = data.features.offline !== false;
        features.download = data.features.download === true;
      }

      renderBreadcrumb();

      if (currentEntries.length === 0) {
        offlineBar.hidden = true;
        libraryStatus.textContent = view.path === ''
          ? 'Es wurden noch keine Aufnahmen gefunden.'
          : 'Dieser Ordner ist leer.';
        return;
      }

      /*
       * Ist dieser Ordner bereits offline gespeichert, das hinterlegte
       * Verzeichnis mit den frischen Angaben auffrischen. Dadurch fuellen
       * sich Kuenstler, Album und Spieldauer auch bei Eintraegen wieder auf,
       * die aus dem Audio-Speicher rekonstruiert werden mussten - sie sind
       * dort nicht enthalten.
       */
      const index = offlineIndex();
      if (index[indexKey(source, view.path)]) {
        const files = currentEntries.filter((e) => e.type === 'file');
        if (files.length > 0) setOfflineFolder(source, view.path, files);
      }

      libraryStatus.hidden = true;
      renderEntries();
      refreshOfflineBar();
    } catch (err) {
      libraryStatus.textContent = 'Verbindung zum Server fehlgeschlagen.';
    }
  }

  // ------------------------------------------------------------------
  // Explorer-Ansicht: zeigt den Inhalt des aktuellen Ordners 1:1 so an,
  // wie er auf der Platte liegt (Unterordner zuerst, dann Dateien) -
  // beliebig tief verschachtelt, ohne feste Monats-/Tages-Ebenen.
  // ------------------------------------------------------------------
  /**
   * Haengt jedem Eintrag seine Quelle und eine eindeutige Kennung an. Der
   * Player braucht die Quelle fuer die Adresse der Aufnahme, die Liste die
   * Kennung fuer die Markierung des laufenden Titels.
   */
  function tagEntries(entries, source) {
    entries.forEach((entry) => {
      entry.source = source;
      entry.key = source + '|' + entry.path;
    });
    return entries;
  }

  function renderEntries() {
    listContainer.innerHTML = '';
    listContainer.classList.remove('entering');
    void listContainer.offsetWidth; // Reflow erzwingen, damit die Animation neu startet
    listContainer.classList.add('entering');

    // Nur die Audiodateien dieses Ordners bilden die Abspielliste
    const folderFiles = currentEntries.filter((e) => e.type === 'file');
    const folderLabel = view.path === '' ? sourceLabel(view.source) : view.path.split('/').join(' \u00b7 ');

    currentEntries.forEach((entry) => {
      if (entry.type === 'dir') {
        const count = entry.count || 0; // null bei den eigenen Dateien (nicht gezaehlt)
        listContainer.appendChild(makeRow({
          icon: folderIcon(),
          label: entry.name,
          meta: count > 0 ? count + (count === 1 ? ' Aufnahme' : ' Aufnahmen') : '',
          onClick: () => navigate(entry.path),
        }));
        return;
      }

      const fileIndex = folderFiles.findIndex((f) => f.path === entry.path);
      const isActive = Player.getCurrentKey() === entry.key;

      const row = makeRow({
        icon: isActive ? playingIcon() : fileIcon(),
        label: entry.name,
        meta: isActive ? 'L\u00e4uft gerade' : trackMeta(entry),
        onClick: () => Player.playFolder(folderFiles, fileIndex, folderLabel),
      });
      // Download-Knopf nur, wenn der Admin das Herunterladen freigegeben hat
      // und eine Verbindung besteht (offline gaebe es nichts zu holen).
      if (features.download && !offlineMode) {
        const dl = document.createElement('a');
        dl.className = 'row-download';
        dl.href = AudioArchive.streamUrl(entry.path, entry.source, true);
        dl.setAttribute('download', entry.file || entry.name);
        dl.title = 'Aufnahme herunterladen';
        dl.setAttribute('aria-label', 'Aufnahme herunterladen');
        dl.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">'
          + '<path d="M12 3v10.6l3.3-3.3 1.4 1.4-5.7 5.7-5.7-5.7 1.4-1.4L10 13.6V3h2zM5 19h14v2H5z"/></svg>';
        // Klick darf nicht die Zeile (= Wiedergabe starten) ausloesen
        dl.addEventListener('click', (ev) => ev.stopPropagation());
        row.appendChild(dl);
      }

      row.dataset.key = entry.key;
      if (isActive) {
        row.classList.add('active');
        if (!Player.isPlaying()) row.classList.add('paused');
      }
      listContainer.appendChild(row);
    });
  }

  function renderBreadcrumb() {
    breadcrumbEl.innerHTML = '';

    const segments = view.path === '' ? [] : view.path.split('/');

    // Wurzel ("Aufnahmen") ist anklickbar, sobald man tiefer steht
    const rootIsCurrent = segments.length === 0;
    const rootEl = document.createElement(rootIsCurrent ? 'span' : 'button');
    rootEl.textContent = sourceLabel(view.source);
    if (rootIsCurrent) {
      rootEl.className = 'crumb crumb--current';
    } else {
      rootEl.type = 'button';
      rootEl.className = 'crumb';
      rootEl.addEventListener('click', () => navigate(''));
    }
    breadcrumbEl.appendChild(rootEl);

    segments.forEach((segment, i) => {
      breadcrumbEl.appendChild(crumbSeparator());

      const isLast = i === segments.length - 1;
      const targetPath = segments.slice(0, i + 1).join('/');
      const el = document.createElement(isLast ? 'span' : 'button');
      el.textContent = segment;

      if (isLast) {
        el.className = 'crumb crumb--current';
      } else {
        el.type = 'button';
        el.className = 'crumb';
        el.addEventListener('click', () => navigate(targetPath));
      }
      breadcrumbEl.appendChild(el);
    });
  }

  function crumbSeparator() {
    const s = document.createElement('span');
    s.className = 'crumb-sep';
    s.textContent = '/';
    return s;
  }

  function makeRow({ icon, label, meta, onClick }) {
    const row = document.createElement('button');
    row.type = 'button';
    row.className = 'explorer-row';
    row.innerHTML = `
      <span class="explorer-row-icon">${icon}</span>
      <span class="explorer-row-label">${escapeHtml(label)}</span>
      <span class="explorer-row-meta">${escapeHtml(meta || '')}</span>
    `;
    row.addEventListener('click', onClick);
    return row;
  }

  function folderIcon() {
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8l-2-2z"/></svg>';
  }

  function fileIcon() {
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
  }

  // Kleine animierte "Equalizer"-Balken statt Datei-Icon, damit auf einen
  // Blick klar ist, welcher Titel gerade tatsächlich läuft (Anforderung:
  // bei mehreren angetippten Titeln nacheinander soll eindeutig erkennbar
  // sein, welcher zuletzt/aktuell läuft).
  function playingIcon() {
    return `<span class="eq-icon" aria-hidden="true">
      <span class="eq-bar"></span><span class="eq-bar"></span><span class="eq-bar"></span>
    </span>`;
  }

  /**
   * Spieldauer als mm:ss bzw. h:mm:ss. Faellt auf die Dateigroesse zurueck,
   * wenn die Dauer nicht ermittelt werden konnte (z.B. beschaedigte Datei).
   */
  function formatDuration(seconds) {
    if (!seconds || !isFinite(seconds) || seconds <= 0) return '';
    const total = Math.round(seconds);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const sec = total % 60;

    if (h > 0) {
      return `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
    }
    return `${m}:${String(sec).padStart(2, '0')}`;
  }

  /** Anzeigetext rechts in der Zeile: bevorzugt die Laenge, sonst die Groesse. */
  function trackMeta(entry) {
    return formatDuration(entry.duration) || formatSize(entry.size);
  }

  function formatSize(bytes) {
    if (!bytes || bytes <= 0) return '';
    const mb = bytes / (1024 * 1024);
    if (mb >= 1) return mb.toFixed(1).replace('.', ',') + ' MB';
    return Math.round(bytes / 1024) + ' KB';
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // ------------------------------------------------------------------
  // Offline-Speicherung eines Ordners.
  //
  // Die Aufnahmen werden in einen eigenen Cache der PWA gelegt (Cache API).
  // Sie liegen damit NICHT als mp3-Datei im Dateisystem/Download-Ordner des
  // Geraets, sondern nur innerhalb der App - der Service Worker liefert sie
  // von dort aus, wenn keine Verbindung besteht. Beim Abmelden bzw. ueber den
  // Knopf lassen sie sich wieder entfernen.
  // ------------------------------------------------------------------
  const OFFLINE_AUDIO_CACHE = 'audioarchive-offline-audio';
  const offlineSupported = 'caches' in window;
  let offlineBusy = false;

  function streamUrlFor(path, source) {
    return AudioArchive.streamUrl(path, source);
  }

  /** Zaehlt, wie viele Dateien dieses Ordners bereits offline vorliegen. */
  async function countOfflineFiles(files) {
    if (!offlineSupported || files.length === 0) return 0;
    try {
      const cache = await caches.open(OFFLINE_AUDIO_CACHE);
      const results = await Promise.all(
        files.map((f) => cache.match(streamUrlFor(f.path, f.source)).then((r) => !!r))
      );
      return results.filter(Boolean).length;
    } catch (err) {
      return 0;
    }
  }

  async function refreshOfflineBar() {
    const files = currentEntries.filter((e) => e.type === 'file');

    if (!features.offline || !offlineSupported || files.length === 0 || offlineBusy) {
      if (!offlineBusy) offlineBar.hidden = true;
      return;
    }

    // Ohne Verbindung laesst sich nichts nachladen - nur das Entfernen
    // gespeicherter Aufnahmen bleibt sinnvoll.
    if (offlineMode && view.path === '') {
      offlineBar.hidden = true;
      return;
    }

    offlineBar.hidden = false;
    const stored = await countOfflineFiles(files);
    const allStored = stored === files.length;

    offlineBtn.textContent = allStored
      ? 'Offline-Aufnahmen entfernen'
      : 'Diesen Ordner offline verfügbar machen';
    offlineBtn.classList.toggle('is-stored', allStored);
    offlineBtn.disabled = false;

    offlineInfo.textContent = stored === 0
      ? ''
      : `${stored} von ${files.length} offline verfügbar`;
  }

  /** Adresse der Ordnerliste - wird zusammen mit den Dateien gespeichert. */
  function listUrlFor(path, source) {
    return AudioArchive.listUrl(path, source);
  }

  /**
   * Legt die Ordnerliste mit in den Offline-Speicher.
   *
   * Kuenstler, Album und Spieldauer stehen NUR in dieser Antwort - die
   * Audiodateien selbst enthalten sie nicht in einer Form, die die App ohne
   * Server auslesen koennte. Ohne diesen Schritt saehe man ohne Verbindung
   * nur die Dateinamen.
   */
  async function storeFolderListing(path, source) {
    try {
      const cache = await caches.open(OFFLINE_AUDIO_CACHE);
      const url = listUrlFor(path, source);
      const res = await fetch(url, { credentials: 'same-origin' });
      if (res.ok) await cache.put(url, res);
    } catch (err) {
      // Nicht kritisch: Dann greift ersatzweise das lokale Verzeichnis.
    }
  }

  async function downloadFolderOffline(files) {
    const cache = await caches.open(OFFLINE_AUDIO_CACHE);
    let done = 0;
    let failed = 0;

    for (const file of files) {
      const url = streamUrlFor(file.path, file.source);
      try {
        if (await cache.match(url)) { done++; continue; }

        // Bewusst ohne Range-Header anfordern, damit die VOLLSTAENDIGE Datei
        // als 200-Antwort im Cache landet (Teilantworten mit 206 lassen sich
        // nicht speichern). Der Service Worker schneidet spaeter selbst die
        // angeforderten Bereiche heraus.
        const res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) { failed++; continue; }
        await cache.put(url, res);
        done++;
      } catch (err) {
        failed++;
      }
      offlineInfo.textContent = `Speichere … ${done + failed} von ${files.length}`;
    }

    return { done, failed };
  }

  async function removeFolderOffline(files) {
    const cache = await caches.open(OFFLINE_AUDIO_CACHE);
    for (const file of files) {
      await cache.delete(streamUrlFor(file.path, file.source));
    }
    // Die mitgespeicherte Ordnerliste ebenfalls entfernen
    await cache.delete(listUrlFor(view.path, view.source));
  }

  /**
   * Fragt eine Offline-PIN ab und legt ihren Pruefwert lokal ab.
   *
   * Warum ueberhaupt eine PIN? Angemeldete Nextcloud-Nutzer haben kein
   * App-Passwort, und ihre Nextcloud-Anmeldung laesst sich ohne Verbindung
   * nicht pruefen - das Kontopasswort darf dafuer keinesfalls lokal liegen.
   * Die PIN schuetzt deshalb ausschliesslich den Zugriff auf die bereits
   * heruntergeladenen Aufnahmen am Geraet. Gespeichert wird wie beim
   * oeffentlichen Passwort nur ein gesalzener Pruefwert, nie die PIN selbst.
   */
  function askOfflinePin() {
    return new Promise((resolve) => {
      const box = document.createElement('div');
      box.className = 'offline-pin';
      box.innerHTML = `
        <p>Lege eine PIN fest. Sie wird abgefragt, wenn du die gespeicherten
        Aufnahmen ohne Internetverbindung hörst.</p>
        <input type="password" id="offline-pin-input" inputmode="numeric"
               autocomplete="new-password" placeholder="PIN">
        <div class="offline-pin-actions">
          <button type="button" id="offline-pin-ok">Übernehmen</button>
          <button type="button" id="offline-pin-cancel">Abbrechen</button>
        </div>
        <p class="offline-pin-error" id="offline-pin-error" hidden></p>
      `;
      offlineBar.after(box);

      const input = box.querySelector('#offline-pin-input');
      const error = box.querySelector('#offline-pin-error');
      input.focus();

      const finish = (value) => { box.remove(); resolve(value); };

      box.querySelector('#offline-pin-ok').addEventListener('click', () => {
        const value = input.value.trim();
        if (value.length < 4) {
          error.textContent = 'Bitte mindestens vier Zeichen.';
          error.hidden = false;
          return;
        }
        finish(value);
      });

      box.querySelector('#offline-pin-cancel').addEventListener('click', () => finish(null));
      input.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') box.querySelector('#offline-pin-ok').click();
      });
    });
  }

  offlineBtn.addEventListener('click', async () => {
    const files = currentEntries.filter((e) => e.type === 'file');
    if (files.length === 0 || offlineBusy) return;

    const removing = offlineBtn.classList.contains('is-stored');

    /*
     * Vor dem ersten Speichern sicherstellen, dass ueberhaupt eine
     * Offline-Anmeldung moeglich ist. Ohne sie waeren die Aufnahmen zwar
     * gespeichert, aber ohne Verbindung nicht erreichbar.
     */
    if (!removing && !storedAuth()) {
      const pin = await askOfflinePin();
      if (pin === null) return;
      await rememberPasswordForOffline(pin);
    }

    offlineBusy = true;
    offlineBtn.disabled = true;

    try {
      if (removing) {
        offlineInfo.textContent = 'Entferne …';
        await removeFolderOffline(files);
        // Auch aus dem Verzeichnis nehmen, sonst bliebe der Ordner offline
        // sichtbar, obwohl seine Aufnahmen geloescht sind.
        removeOfflineFolder(view.source, view.path);
        offlineInfo.textContent = 'Offline-Aufnahmen entfernt.';
      } else {
        offlineInfo.textContent = `Speichere … 0 von ${files.length}`;
        const { done, failed } = await downloadFolderOffline(files);

        /*
         * Entscheidend: Die Dateiliste MIT allen Angaben ins Verzeichnis
         * schreiben. Im Audio-Speicher liegen nur die Aufnahmen selbst -
         * Kuenstler, Album und Spieldauer stehen ausschliesslich hier.
         * Ohne diesen Schritt muss die App ohne Verbindung alles aus den
         * Dateinamen rekonstruieren, und genau diese Angaben fehlen dann.
         */
        if (done > 0) {
          /*
           * Zwei Ablagen mit Absicht:
           *   - die Antwort des Servers im Offline-Speicher (vollstaendig,
           *     ueberlebt das Loeschen der Browserdaten nicht, wohl aber
           *     einen leeren localStorage)
           *   - dieselben Angaben im lokalen Verzeichnis (Grundlage fuer die
           *     Ordneransicht ohne Verbindung)
           * Kuenstler, Album und Spieldauer stehen NUR hier - die
           * Audiodateien selbst liefern sie der App nicht.
           */
          await storeFolderListing(view.path, view.source);
          setOfflineFolder(view.source, view.path, files);
        }

        offlineInfo.textContent = failed === 0
          ? `${done} Aufnahmen offline verfügbar.`
          : `${done} gespeichert, ${failed} fehlgeschlagen.`;
      }
    } catch (err) {
      offlineInfo.textContent = 'Offline-Speichern fehlgeschlagen.';
    } finally {
      offlineBusy = false;
      offlineBtn.disabled = false;
      // Beschriftung/Zaehler frisch bestimmen, Statustext dabei kurz stehen lassen
      const message = offlineInfo.textContent;
      await refreshOfflineBar();
      if (message) offlineInfo.textContent = message;
    }
  });

  // ------------------------------------------------------------------
  // Aktive Zeile aktualisieren, OHNE die ganze Liste neu aufzubauen -
  // das hält das Scrollen flüssig (kein Neuaufbau aller DOM-Knoten, keine
  // erneute Ein-Animation) und verhindert Ruckler beim Titelwechsel.
  // ------------------------------------------------------------------
  function updateActiveRow() {
    const currentKey = Player.getCurrentKey();
    const playing = Player.isPlaying();

    listContainer.querySelectorAll('.explorer-row[data-key]').forEach((row) => {
      const isActive = row.dataset.key === currentKey;
      const wasActive = row.classList.contains('active');

      // Zeile pausiert/läuft: steuert nur die Animation der Equalizer-Balken
      row.classList.toggle('paused', isActive && !playing);

      if (isActive === wasActive) return; // Icon/Text müssen nicht neu gesetzt werden

      row.classList.toggle('active', isActive);

      const iconEl = row.querySelector('.explorer-row-icon');
      const metaEl = row.querySelector('.explorer-row-meta');
      const entry = currentEntries.find((e) => e.key === row.dataset.key);

      if (iconEl) iconEl.innerHTML = isActive ? playingIcon() : fileIcon();
      if (metaEl) {
        metaEl.textContent = isActive
          ? 'L\u00e4uft gerade'
          : (entry ? trackMeta(entry) : '');
      }
    });
  }

  // Titelwechsel (Autoplay, Sperrbildschirm-"Nächster Titel") und
  // Play/Pause spiegeln sich beide in der Liste wider.
  Player.onTrackChange(updateActiveRow);
  Player.onPlayStateChange(updateActiveRow);

  // ------------------------------------------------------------------
  // Ordnerbaum in der Seitenleiste (nur angemeldet)
  //
  // Zwei Wurzeln: der gemeinsame Ordner des Administrators (falls
  // eingerichtet) und die eigenen Dateien. Unterordner werden erst beim
  // Aufklappen geladen - die eigenen Dateien koennen sehr umfangreich sein.
  // Ohne Verbindung baut sich der Baum aus den offline gespeicherten
  // Ordnern auf.
  // ------------------------------------------------------------------
  const Tree = (() => {
    const enabled = AudioArchive.loggedIn && !AudioArchive.isPublic();
    const sidebar = document.getElementById('sidebar');
    const treeEl = document.getElementById('tree');
    const toggleBtn = document.getElementById('sidebar-toggle');
    const backdrop = document.getElementById('sidebar-backdrop');
    const root = document.getElementById('audioarchive');

    /** Knoten je Kennung "quelle|pfad": { li, item, toggle, children, loaded, expanded } */
    const nodes = new Map();
    let selectedKey = null;
    let built = false;

    const key = (source, path) => source + '|' + path;

    const folderSvg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8l-2-2z"/></svg>';
    const homeSvg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12 3 2 12h3v8h6v-6h2v6h6v-8h3z"/></svg>';
    const sharedSvg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm-1 13.5v-9l6 4.5z"/></svg>';
    const chevronSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>';

    function isNarrow() {
      return window.matchMedia('(max-width: 1023px)').matches;
    }

    function setOpen(open) {
      root.classList.toggle('aa-sidebar-open', open);
      backdrop.hidden = !open;
      toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    /** Unterordner laden - online vom Server, offline aus dem Verzeichnis. */
    async function fetchChildren(source, path) {
      if (offlineMode) {
        return offlineEntriesFor(path, source)
          .filter((e) => e.type === 'dir')
          .map((d) => ({
            name: d.name,
            path: d.path,
            hasChildren: offlineEntriesFor(d.path, source).some((e) => e.type === 'dir'),
          }));
      }
      const res = await fetch(AudioArchive.treeUrl(path, source), { credentials: 'same-origin' });
      if (!res.ok) throw new Error('tree');
      const data = await res.json();
      return Array.isArray(data.dirs) ? data.dirs : [];
    }

    function createNode(source, path, label, hasChildren, icon, isRoot) {
      const li = document.createElement('li');
      li.setAttribute('role', 'treeitem');
      if (isRoot) li.className = 'tree-root';

      const item = document.createElement('div');
      item.className = 'tree-item';

      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'tree-toggle' + (hasChildren ? '' : ' is-empty');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Aufklappen');
      toggle.innerHTML = chevronSvg;

      const labelBtn = document.createElement('button');
      labelBtn.type = 'button';
      labelBtn.className = 'tree-label';
      labelBtn.innerHTML = `<span class="tree-icon">${icon}</span><span class="tree-label-text"></span>`;
      labelBtn.querySelector('.tree-label-text').textContent = label;
      labelBtn.title = label;

      item.append(toggle, labelBtn);
      li.appendChild(item);

      const children = document.createElement('ul');
      children.setAttribute('role', 'group');
      children.hidden = true;
      li.appendChild(children);

      const node = { li, item, toggle, children, loaded: false, expanded: false, source, path };
      nodes.set(key(source, path), node);

      toggle.addEventListener('click', () => (node.expanded ? collapse(node) : expand(node)));
      labelBtn.addEventListener('click', () => {
        if (isNarrow()) setOpen(false);
        if (key(view.source, view.path) !== key(source, path)) navigate(path, source);
        if (!node.expanded && hasChildren) expand(node);
      });

      return node;
    }

    function expand(node) {
      node.expanded = true;
      node.toggle.setAttribute('aria-expanded', 'true');
      node.children.hidden = false;
      if (node.loaded) return Promise.resolve();
      // Laeuft das Laden schon (z.B. Aufbau und Auswahl gleichzeitig), auf
      // denselben Vorgang warten statt die Kinder doppelt anzulegen
      if (!node.loading) {
        node.loading = loadChildren(node).finally(() => { node.loading = null; });
      }
      return node.loading;
    }

    async function loadChildren(node) {
      node.children.innerHTML = '<li class="tree-status">Lade …</li>';
      try {
        const dirs = await fetchChildren(node.source, node.path);
        node.children.innerHTML = '';
        dirs.forEach((dir) => {
          const child = createNode(node.source, dir.path, dir.name, dir.hasChildren, folderSvg, false);
          node.children.appendChild(child.li);
        });
        node.loaded = true;
        if (dirs.length === 0) node.toggle.classList.add('is-empty');
        markSelected();
      } catch (err) {
        node.children.innerHTML = '<li class="tree-status">Nicht erreichbar</li>';
      }
    }

    function collapse(node) {
      node.expanded = false;
      node.toggle.setAttribute('aria-expanded', 'false');
      node.children.hidden = true;
    }

    function markSelected() {
      nodes.forEach((node, k) => node.item.classList.toggle('is-active', k === selectedKey));
    }

    function build() {
      treeEl.innerHTML = '';
      nodes.clear();

      const roots = [];
      if (offlineMode) {
        // Ohne Verbindung nur Quellen, fuer die etwas gespeichert ist
        const available = offlineSources();
        if (available.has('shared')) roots.push('shared');
        if (available.has('home')) roots.push('home');
      } else {
        if (AudioArchive.hasShared) roots.push('shared');
        roots.push('home');
      }

      roots.forEach((source) => {
        const node = createNode(source, '', sourceLabel(source), true,
          source === 'home' ? homeSvg : sharedSvg, true);
        treeEl.appendChild(node.li);
        expand(node);
      });
      built = true;
    }

    return {
      init() {
        if (!enabled) return;
        sidebar.hidden = false;
        toggleBtn.hidden = false;
        toggleBtn.addEventListener('click', () => setOpen(!root.classList.contains('aa-sidebar-open')));
        backdrop.addEventListener('click', () => setOpen(false));
        build();
      },

      /** Nach dem Wechsel in den Offline-Betrieb neu aufbauen. */
      rebuild() {
        if (!enabled || !built) return;
        build();
        if (selectedKey) {
          const [source, ...rest] = selectedKey.split('|');
          this.select(source, rest.join('|'));
        }
      },

      /**
       * Markiert den geoeffneten Ordner und klappt seine Vorfahren auf,
       * damit er im Baum sichtbar ist.
       */
      async select(source, path) {
        if (!enabled) return;
        selectedKey = key(source, path);
        markSelected();

        const segments = path === '' ? [] : path.split('/');
        let current = '';
        const rootNode = nodes.get(key(source, ''));
        if (rootNode && !rootNode.expanded) await expand(rootNode);

        for (const segment of segments.slice(0, -1)) {
          current = current === '' ? segment : current + '/' + segment;
          const node = nodes.get(key(source, current));
          if (!node) break;
          if (!node.expanded || !node.loaded) await expand(node);
        }

        markSelected();
        const active = nodes.get(selectedKey);
        if (active && !isNarrow()) active.item.scrollIntoView({ block: 'nearest' });
      },
    };
  })();

  // ------------------------------------------------------------------
  // Persoenliche Darstellung (Zahnrad, nur angemeldet)
  //
  // Gestaltung und Hintergrundbild gelten nur fuer die eigene Ansicht.
  // Nach dem Uebernehmen wird die Seite neu geladen: Gestaltung und Bild
  // setzt der Server bereits beim Ausliefern, so steht alles sofort richtig
  // da - auch in der installierten App und im Offline-Start.
  // ------------------------------------------------------------------
  const userSettingsBtn = document.getElementById('user-settings-btn');
  const userSettingsPanel = document.getElementById('user-settings');

  if (AudioArchive.userSettings && userSettingsBtn && userSettingsPanel) {
    userSettingsBtn.hidden = false;

    const usError = document.getElementById('us-error');
    const usBackgroundState = document.getElementById('us-background-state');
    const usBackgroundFile = document.getElementById('us-background-file');
    const usBackgroundRemove = document.getElementById('us-background-remove');
    const usDefaultLabel = document.getElementById('us-design-default-label');
    let usChanged = false;

    const designName = (d) => (d === 'nextcloud' ? 'Nextcloud' : 'Eigene Gestaltung');

    function usShowError(message) {
      usError.textContent = message || '';
      usError.hidden = !message;
    }

    function usShowBackground(has) {
      usBackgroundRemove.hidden = !has;
      usBackgroundState.textContent = has
        ? 'Eigenes Bild gesetzt. Es gilt in beiden Gestaltungen.'
        : 'Kein eigenes Bild – es gilt die Vorgabe.';
    }

    /** Schreibender Aufruf mit Nextclouds Anfrage-Token. */
    async function usRequest(name, options) {
      const res = await fetch(AudioArchive.api(name), {
        credentials: 'same-origin',
        ...options,
        headers: { requesttoken: AudioArchive.requestToken, ...(options.headers || {}) },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.error || (res.status === 412
          ? 'Sitzung abgelaufen – bitte die Seite neu laden.'
          : 'Speichern fehlgeschlagen.'));
      }
      return data;
    }

    async function openUserSettings() {
      usShowError('');
      userSettingsPanel.hidden = false;
      userSettingsPanel.scrollIntoView({ block: 'nearest' });
      try {
        const res = await fetch(AudioArchive.api('user/settings'), { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || '');
        usDefaultLabel.textContent = 'Vorgabe des Administrators (' + designName(data.adminDesign) + ')';
        userSettingsPanel.querySelectorAll('input[name="us-design"]').forEach((input) => {
          input.checked = input.value === (data.design || '');
        });
        usShowBackground(data.hasBackground === true);
      } catch (err) {
        usShowError('Einstellungen konnten nicht geladen werden (keine Verbindung?).');
      }
    }

    function closeUserSettings() {
      userSettingsPanel.hidden = true;
      // Ein neues oder entferntes Bild zeigt sich erst nach dem Neuladen
      if (usChanged) location.reload();
    }

    userSettingsBtn.addEventListener('click', () => {
      if (userSettingsPanel.hidden) openUserSettings(); else closeUserSettings();
    });
    document.getElementById('us-cancel').addEventListener('click', closeUserSettings);

    document.getElementById('us-save').addEventListener('click', async () => {
      const chosen = userSettingsPanel.querySelector('input[name="us-design"]:checked');
      usShowError('');
      try {
        await usRequest('user/settings', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ design: chosen ? chosen.value : '' }),
        });
        location.reload();
      } catch (err) {
        usShowError(err.message);
      }
    });

    usBackgroundFile.addEventListener('change', async () => {
      const file = usBackgroundFile.files[0];
      if (!file) return;
      usShowError('');
      usBackgroundState.textContent = 'Lade hoch …';
      const form = new FormData();
      form.append('file', file);
      try {
        await usRequest('user/background', { method: 'POST', body: form });
        usChanged = true;
        usShowBackground(true);
      } catch (err) {
        usShowError(err.message);
        usShowBackground(!usBackgroundRemove.hidden);
      } finally {
        usBackgroundFile.value = '';
      }
    });

    usBackgroundRemove.addEventListener('click', async () => {
      usShowError('');
      try {
        await usRequest('user/background/remove', { method: 'POST' });
        usChanged = true;
        usShowBackground(false);
      } catch (err) {
        usShowError(err.message);
      }
    });
  }

  // ------------------------------------------------------------------
  // Start
  // ------------------------------------------------------------------
  applySettingsFromDocument();
  checkSession();
})();
